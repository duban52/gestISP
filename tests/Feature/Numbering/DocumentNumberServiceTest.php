<?php

namespace Tests\Feature\Numbering;

use App\Models\Branch;
use App\Models\Company;
use App\Models\DocumentSequence;
use App\Services\Numbering\DocumentNumberService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * El servicio que reserva consecutivos.
 *
 * LA GARANTÍA
 * -----------
 * Es una sola y es la razón de que este servicio exista: **dos altas
 * simultáneas nunca reciben el mismo número**.
 *
 * Se consigue con bloqueo pesimista sobre la fila de la serie. El
 * segundo proceso espera al commit del primero y lee un contador ya
 * incrementado. Por eso todo esto tiene que correr dentro de una
 * transacción — el bloqueo vive hasta el commit.
 *
 * POR QUÉ EL SERVICIO
 * -------------------
 * Esa misma lógica estaba escrita tres veces: contratos, facturas y
 * notas. Tres sitios donde arreglar el mismo fallo y tres suites
 * probando lo mismo. Esta es ahora la única.
 */
class DocumentNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentNumberService $servicio;
    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->servicio = app(DocumentNumberService::class);
        $this->empresa = Company::factory()->create();
    }

    // ==================== Lo básico ====================

    public function test_entrega_numeros_consecutivos(): void
    {
        DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'prefix' => 'ENG',
            'padding' => 6,
        ]);

        $primero = $this->servicio->siguiente(DocumentSequence::CONTRATO, $this->empresa->id);
        $segundo = $this->servicio->siguiente(DocumentSequence::CONTRATO, $this->empresa->id);

        $this->assertSame('ENG000001', $primero->completo);
        $this->assertSame('ENG000002', $segundo->completo);
        $this->assertSame(2, $segundo->consecutivo);
    }

    public function test_no_repite_ni_salta_en_una_tanda_larga(): void
    {
        // Un número repetido lo rechazaría el UNIQUE de la tabla del
        // documento; uno saltado no lo rechaza nadie y deja un hueco
        // que hay que justificar.
        DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'prefix' => 'X',
            'padding' => 0,
        ]);

        $entregados = [];

        for ($i = 0; $i < 50; $i++) {
            $entregados[] = $this->servicio
                ->siguiente(DocumentSequence::CONTRATO, $this->empresa->id)
                ->consecutivo;
        }

        $this->assertSame(range(1, 50), $entregados);
    }

    public function test_el_relleno_es_un_dato_de_la_serie(): void
    {
        // Antes era una constante distinta en cada servicio: los
        // contratos a seis dígitos, las notas sin rellenar.
        $conRelleno = DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'document_type' => DocumentSequence::CONTRATO,
            'prefix' => 'ENG',
            'padding' => 6,
        ]);

        DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'document_type' => DocumentSequence::NOTA_CREDITO,
            'prefix' => 'NC-',
            'padding' => 0,
        ]);

        $this->assertSame(
            'ENG000001',
            $this->servicio->siguiente(DocumentSequence::CONTRATO, $this->empresa->id)->completo,
        );

        $this->assertSame(
            'NC-1',
            $this->servicio->siguiente(DocumentSequence::NOTA_CREDITO, $this->empresa->id)->completo,
        );

        $this->assertSame('ENG', $conRelleno->fresh()->prefix);
    }

    // ==================== El bloqueo ====================

    public function test_reserva_con_la_fila_bloqueada(): void
    {
        // Es LA garantía del servicio. No se puede probar con dos
        // procesos de verdad desde aquí, así que se comprueba que la
        // consulta que lee la serie pide el bloqueo: sin `for update`,
        // dos altas simultáneas leerían el mismo contador y el segundo
        // número saldría repetido.
        DocumentSequence::factory()->create(['company_id' => $this->empresa->id]);

        $consultas = [];
        DB::listen(function ($q) use (&$consultas) {
            $consultas[] = strtolower($q->sql);
        });

        DB::transaction(fn () => $this->servicio->siguiente(
            DocumentSequence::CONTRATO,
            $this->empresa->id,
        ));

        $conBloqueo = array_filter(
            $consultas,
            fn (string $sql) => str_contains($sql, 'document_sequences') && str_contains($sql, 'for update'),
        );

        $this->assertNotEmpty($conBloqueo, 'La serie se leyó sin bloquear la fila.');
    }

    public function test_funciona_llamado_sin_transaccion_propia(): void
    {
        // El bloqueo no sirve de nada fuera de una transacción: se
        // suelta al terminar la consulta. Si quien llama no abrió una,
        // la abre el servicio.
        //
        // No se comprueba que el nivel sea 0 antes de llamar porque
        // aquí nunca lo es: RefreshDatabase envuelve cada prueba en su
        // propia transacción. Lo que sí se puede comprobar —y es lo
        // que importa— es que llamarlo sin abrir una funciona y deja
        // el contador guardado.
        $serie = DocumentSequence::factory()->create(['company_id' => $this->empresa->id]);

        $numero = $this->servicio->siguiente(DocumentSequence::CONTRATO, $this->empresa->id);

        $this->assertSame(1, $numero->consecutivo);
        $this->assertSame(1, $serie->fresh()->current_number);
    }

    public function test_el_numero_vuelve_atras_si_la_transaccion_falla(): void
    {
        // El consecutivo se reserva junto al documento que lo lleva. Si
        // el documento no llega a guardarse, el número tiene que volver
        // atrás con él o queda un hueco.
        $serie = DocumentSequence::factory()->create(['company_id' => $this->empresa->id]);

        try {
            DB::transaction(function () {
                $this->servicio->siguiente(DocumentSequence::CONTRATO, $this->empresa->id);

                throw new RuntimeException('el documento falló');
            });
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertSame(0, $serie->fresh()->current_number);
    }

    // ==================== La semilla ====================

    public function test_sin_serie_la_crea_desde_el_mayor_ya_usado(): void
    {
        // Es lo que evita que un sistema con mil contratos reciba el
        // número 1 al crear la serie. Sin esto, el comando de migración
        // sería obligatorio para poder seguir operando.
        $numero = $this->servicio->siguiente(
            DocumentSequence::CONTRATO,
            $this->empresa->id,
            null,
            ['prefix' => 'ENG', 'padding' => 6],
            semilla: fn () => 1000,
        );

        $this->assertSame(1001, $numero->consecutivo);
        $this->assertSame('ENG001001', $numero->completo);
    }

    public function test_la_semilla_solo_actua_al_crear_la_serie(): void
    {
        // Si actuara siempre, cualquier cálculo raro del llamador
        // podría empujar el contador hacia adelante sin motivo.
        DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'current_number' => 5,
        ]);

        $numero = $this->servicio->siguiente(
            DocumentSequence::CONTRATO,
            $this->empresa->id,
            null,
            [],
            semilla: fn () => 9999,
        );

        $this->assertSame(6, $numero->consecutivo);
    }

    // ==================== Números que vienen de fuera ====================

    public function test_un_numero_externo_mayor_adelanta_el_contador(): void
    {
        $serie = DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'current_number' => 10,
        ]);

        $this->servicio->registrarExterno(
            DocumentSequence::CONTRATO,
            $this->empresa->id,
            null,
            500,
        );

        $this->assertSame(500, $serie->fresh()->current_number);

        // Y el siguiente sale de ahí, no de 11.
        $this->assertSame(
            501,
            $this->servicio->siguiente(DocumentSequence::CONTRATO, $this->empresa->id)->consecutivo,
        );
    }

    public function test_un_numero_externo_menor_no_retrocede_el_contador(): void
    {
        // Un contador que baja es un número repetido esperando a
        // ocurrir.
        $serie = DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'current_number' => 500,
        ]);

        $this->servicio->registrarExterno(
            DocumentSequence::CONTRATO,
            $this->empresa->id,
            null,
            10,
        );

        $this->assertSame(500, $serie->fresh()->current_number);
    }

    // ==================== El rango ====================

    public function test_respeta_el_inicio_del_rango(): void
    {
        DocumentSequence::factory()->conRango(900, 1000)->create([
            'company_id' => $this->empresa->id,
        ]);

        $this->assertSame(
            900,
            $this->servicio->siguiente(DocumentSequence::CONTRATO, $this->empresa->id)->consecutivo,
        );
    }

    public function test_falla_al_agotar_el_rango_en_vez_de_seguir(): void
    {
        // Cuando esta serie sea la fiscal, seguir emitiendo pasado el
        // rango es emitir con números que nadie autorizó. Falla en voz
        // alta.
        DocumentSequence::factory()->conRango(1, 2)->create([
            'company_id' => $this->empresa->id,
            'prefix' => 'FE',
        ]);

        $this->servicio->siguiente(DocumentSequence::CONTRATO, $this->empresa->id);
        $this->servicio->siguiente(DocumentSequence::CONTRATO, $this->empresa->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/agotó su rango/');

        $this->servicio->siguiente(DocumentSequence::CONTRATO, $this->empresa->id);
    }

    // ==================== Una sola serie activa ====================

    public function test_no_puede_haber_dos_series_activas_iguales(): void
    {
        // Con dos, de cuál sale el número siguiente dependería del
        // orden de la consulta, y las dos avanzarían en paralelo
        // entregando los mismos consecutivos. Lo impide la BASE.
        DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'branch_id' => null,
            'document_type' => DocumentSequence::CONTRATO,
        ]);

        $this->expectException(QueryException::class);

        DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'branch_id' => null,
            'document_type' => DocumentSequence::CONTRATO,
        ]);
    }

    public function test_si_pueden_convivir_muchas_inactivas(): void
    {
        // Las inactivas son el histórico: qué prefijo se usó y hasta
        // dónde llegó. Si el índice fuera sobre las columnas a secas,
        // la segunda ya fallaría.
        DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'document_type' => DocumentSequence::CONTRATO,
        ]);

        DocumentSequence::factory()->inactiva()->count(3)->create([
            'company_id' => $this->empresa->id,
            'document_type' => DocumentSequence::CONTRATO,
        ]);

        $this->assertSame(4, DocumentSequence::withoutGlobalScope('empresa')->count());
    }

    public function test_cada_sucursal_lleva_la_suya(): void
    {
        $norte = Branch::factory()->create(['company_id' => $this->empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $this->empresa->id]);

        DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'branch_id' => $norte->id,
            'prefix' => 'NOR',
            'padding' => 4,
        ]);

        DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'branch_id' => $sur->id,
            'prefix' => 'SUR',
            'padding' => 4,
        ]);

        $this->servicio->siguiente(DocumentSequence::CONTRATO, $this->empresa->id, $norte->id);
        $this->servicio->siguiente(DocumentSequence::CONTRATO, $this->empresa->id, $norte->id);

        $delSur = $this->servicio->siguiente(DocumentSequence::CONTRATO, $this->empresa->id, $sur->id);

        // El del sur arranca en 1: son contadores independientes.
        $this->assertSame('SUR0001', $delSur->completo);
    }

    public function test_la_serie_de_empresa_y_la_de_sucursal_conviven(): void
    {
        // COALESCE(branch_id, 0) en el índice: sin él, NULL nunca es
        // igual a NULL y dos series de empresa del mismo tipo pasarían
        // las dos.
        $sucursal = Branch::factory()->create(['company_id' => $this->empresa->id]);

        DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'branch_id' => null,
        ]);

        DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'branch_id' => $sucursal->id,
        ]);

        $this->expectException(QueryException::class);

        // Pero una SEGUNDA de empresa, no.
        DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'branch_id' => null,
        ]);
    }

    // ==================== Aislamiento ====================

    public function test_no_toca_la_serie_de_otra_empresa(): void
    {
        $otra = Company::factory()->create();

        DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'prefix' => 'MIA',
            'current_number' => 100,
        ]);

        $serieAjena = DocumentSequence::factory()->create([
            'company_id' => $otra->id,
            'prefix' => 'SUYA',
            'current_number' => 700,
        ]);

        $numero = $this->servicio->siguiente(DocumentSequence::CONTRATO, $this->empresa->id);

        $this->assertSame(101, $numero->consecutivo);
        $this->assertSame(700, $serieAjena->fresh()->current_number);
    }

    public function test_consultar_la_serie_no_reserva_nada(): void
    {
        // Las pantallas que enseñan el prefijo o lo que queda del rango
        // no pueden gastar un número al pintarse.
        $serie = DocumentSequence::factory()->create([
            'company_id' => $this->empresa->id,
            'current_number' => 42,
        ]);

        $leida = $this->servicio->serie(DocumentSequence::CONTRATO, $this->empresa->id);

        $this->assertSame($serie->id, $leida->id);
        $this->assertSame(42, $serie->fresh()->current_number);
    }
}
