<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\CableStrand;
use App\Models\Client;
use App\Models\Contract;
use App\Models\FiberCable;
use App\Models\NapBox;
use App\Models\Olt;
use App\Models\OpticalNetwork;
use App\Models\Plan;
use App\Models\PonPort;
use App\Models\SpliceClosure;
use App\Models\User;
use App\Services\FiberPathTracer;
use App\Services\FiberPlantManager;
use App\Services\OdnManager;
use App\Support\FiberColors;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Planta de fibra: cables, hilos, muflas, fusiones y splitters.
 *
 * QUÉ SE PROTEGE AQUÍ
 * -------------------
 * Dos cosas, y la segunda es la que justifica el módulo entero:
 *
 *   1. Que la numeración y los colores de los hilos salgan de la norma
 *      y no del criterio de quien captura. Si el sistema dice que el
 *      hilo 14 es azul y en el cable es naranja, el técnico corta el
 *      que no era.
 *
 *   2. Que el análisis de impacto sea correcto. «Si corto esta mufla,
 *      ¿a quién dejo sin servicio?» es la pregunta que hoy se responde
 *      llamando al que lleva más años en la empresa; una respuesta mal
 *      calculada es peor que no tener respuesta, porque se le cree.
 */
class FiberPlantTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private OpticalNetwork $red;
    private Olt $olt;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create(['contract_prefix' => 'FIB']);
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3007778899']);
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => $this->branch->id,
            'current_role_id' => $rol->id,
        ]);

        $this->plan = Plan::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->red = OpticalNetwork::create([
            'branch_id' => $this->branch->id,
            'name' => 'Red fibra',
            'nap_prefix' => 'NAP',
            'nap_next_number' => 1,
            'active' => true,
            'user_id' => $this->admin->id,
        ]);

        $this->olt = Olt::create([
            'branch_id' => $this->branch->id,
            'optical_network_id' => $this->red->id,
            'name' => 'OLT cabecera',
            'ip_address' => '10.0.0.30',
            'ssh_port' => 22, 'telnet_port' => 23, 'snmp_port' => 161,
            'read_snmp_comunity' => 'public', 'write_snmp_comunity' => 'private',
            'username' => 'root', 'password' => 'admin',
            'brand' => 'huawei', 'uptime' => '0',
        ]);
    }

    // ==================== Hilos y colores ====================

    /** @test */
    public function el_cable_genera_sus_hilos_con_el_codigo_de_colores(): void
    {
        $cable = $this->cable('TRC-001', 48, 4, 12);

        $this->assertCount(48, $cable->strands);

        // El hilo 1 abre las dos secuencias
        $primero = $cable->strands->firstWhere('number', 1);
        $this->assertSame('Azul', $primero->buffer_color);
        $this->assertSame('Azul', $primero->strand_color);

        // El 14 es el segundo del segundo buffer: naranja/naranja.
        // Es la conversión que el técnico hace de cabeza y que aquí no
        // puede fallar.
        $catorce = $cable->strands->firstWhere('number', 14);
        $this->assertSame(2, $catorce->buffer_number);
        $this->assertSame('Naranja', $catorce->buffer_color);
        $this->assertSame(2, $catorce->strand_number);
        $this->assertSame('Naranja', $catorce->strand_color);
        $this->assertSame('B2 Naranja / H2 Naranja', $catorce->posicion_legible);

        // Y el último cierra la cuarta vuelta
        $ultimo = $cable->strands->firstWhere('number', 48);
        $this->assertSame(4, $ultimo->buffer_number);
        $this->assertSame('Café', $ultimo->buffer_color);
        $this->assertSame('Aguamarina', $ultimo->strand_color);
    }

    /** @test */
    public function un_cable_de_doce_tiene_un_solo_buffer(): void
    {
        $cable = $this->cable('DIS-001', 12, 1, 12);

        $this->assertCount(12, $cable->strands);
        $this->assertSame(1, $cable->strands->max('buffer_number'));
        $this->assertSame('Aguamarina', $cable->strands->firstWhere('number', 12)->strand_color);
    }

    /**
     * @test
     *
     * Un reparto que no cuadra es un error de captura, no un cable.
     *
     * Dejarlo pasar generaría hilos con posiciones que no existen en el
     * cable real, y el técnico buscaría un color que no está ahí.
     */
    public function un_reparto_que_no_cuadra_no_se_guarda(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no cuadra/');

        $this->cable('MAL-001', 48, 4, 10);
    }

    /** @test */
    public function pasada_la_docena_los_colores_se_repiten_con_trazador(): void
    {
        // 24 buffers de 12: los buffers 13 en adelante repiten
        $this->assertSame('Azul', FiberColors::nombre(1));
        $this->assertSame('Aguamarina', FiberColors::nombre(12));
        $this->assertSame('Azul con trazador', FiberColors::nombre(13));
        $this->assertSame('Naranja con trazador', FiberColors::nombre(14));
    }

    // ==================== Fusiones ====================

    /** @test */
    public function una_fusion_une_dos_hilos_y_los_pone_en_uso(): void
    {
        $mufla = $this->mufla('MUF-001');
        $a = $this->cable('A', 12, 1, 12)->strands->first();
        $b = $this->cable('B', 12, 1, 12)->strands->first();

        $this->assertTrue($a->estaDisponible());

        app(FiberPlantManager::class)->fusionar($mufla, $a, $b, ['tray' => 1, 'loss_db' => 0.05]);

        $a = $a->fresh();

        // "En uso" NO se guarda: se deduce de la fusión
        $this->assertTrue($a->estaEnUso());
        $this->assertFalse($a->estaLibre());
        $this->assertSame('En uso', $a->estado_legible);

        // Pero le queda el otro extremo suelto: con una sola fusión el
        // hilo todavía admite continuar hacia el cliente.
        $this->assertSame(1, $a->conexiones());
        $this->assertTrue($a->estaDisponible());
    }

    /**
     * @test
     *
     * El mismo hilo no puede fusionarse dos veces en la misma mufla:
     * físicamente solo llega por un extremo.
     */
    public function un_hilo_no_se_fusiona_dos_veces_en_la_misma_mufla(): void
    {
        $mufla = $this->mufla('MUF-001');
        $troncal = $this->cable('A', 12, 1, 12);
        $b = $this->cable('B', 12, 1, 12);
        $c = $this->cable('C', 12, 1, 12);

        $gestor = app(FiberPlantManager::class);
        $gestor->fusionar($mufla, $troncal->strands->first(), $b->strands->first());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ya está fusionado/');

        $gestor->fusionar($mufla, $troncal->strands->first()->fresh(), $c->strands->first());
    }

    /** @test */
    public function un_hilo_si_puede_estar_fusionado_en_dos_muflas_distintas(): void
    {
        $m1 = $this->mufla('MUF-001');
        $m2 = $this->mufla('MUF-002');

        // Un tramo de paso: entra por una mufla y sale por la otra
        $paso = $this->cable('PASO', 12, 1, 12);
        $a = $this->cable('A', 12, 1, 12);
        $b = $this->cable('B', 12, 1, 12);

        $gestor = app(FiberPlantManager::class);
        $gestor->fusionar($m1, $a->strands->first(), $paso->strands->first());
        $gestor->fusionar($m2, $paso->strands->first()->fresh(), $b->strands->first());

        $this->assertSame(2, \App\Models\Splice::count());
    }

    /** @test */
    public function no_se_puede_fusionar_un_hilo_consigo_mismo(): void
    {
        $mufla = $this->mufla('MUF-001');
        $hilo = $this->cable('A', 12, 1, 12)->strands->first();

        $this->expectException(RuntimeException::class);

        app(FiberPlantManager::class)->fusionar($mufla, $hilo, $hilo);
    }

    /** @test */
    public function la_mufla_no_admite_mas_fusiones_de_las_que_caben(): void
    {
        // Una bandeja de 2 fusiones
        $mufla = $this->mufla('MUF-001', trayCount: 1, splicesPerTray: 2);
        $a = $this->cable('A', 12, 1, 12);
        $b = $this->cable('B', 12, 1, 12);

        $gestor = app(FiberPlantManager::class);
        $gestor->fusionar($mufla, $a->strands[0], $b->strands[0]);
        $gestor->fusionar($mufla->fresh(), $a->strands[1], $b->strands[1]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no tiene espacio/');

        $gestor->fusionar($mufla->fresh(), $a->strands[2], $b->strands[2]);
    }

    // ==================== Splitters ====================

    /** @test */
    public function montar_un_splitter_crea_todas_sus_salidas(): void
    {
        $mufla = $this->mufla('MUF-001');
        $entrada = $this->cable('A', 12, 1, 12)->strands->first();

        $splitter = app(FiberPlantManager::class)->montarSplitter($mufla, [
            'ratio' => '1:8',
            'input_strand_id' => $entrada->id,
        ]);

        $this->assertSame(8, $splitter->outputs->count());
        $this->assertSame(0, $splitter->salidasUsadas());
        // Y la entrada queda en uso, aunque no haya ninguna salida puesta
        $this->assertTrue($entrada->fresh()->estaEnUso());
    }

    /**
     * @test
     *
     * Un hilo con los DOS extremos comprometidos no admite nada más.
     *
     * Lo que no se puede es conectar un tercer elemento a un hilo que
     * ya entra por una mufla y sale por otra: no hay por dónde.
     */
    public function un_hilo_con_los_dos_extremos_ocupados_no_admite_nada_mas(): void
    {
        $m1 = $this->mufla('MUF-001');
        $m2 = $this->mufla('MUF-002');

        $paso = $this->cable('PASO', 12, 1, 12);
        $a = $this->cable('A', 12, 1, 12);
        $b = $this->cable('B', 12, 1, 12);
        $troncal = $this->cable('TRC', 12, 1, 12);

        $gestor = app(FiberPlantManager::class);

        // El hilo de paso queda fusionado por sus dos extremos
        $gestor->fusionar($m1, $a->strands[0], $paso->strands[0]);
        $gestor->fusionar($m2, $paso->strands[0]->fresh(), $b->strands[0]);

        $splitter = $gestor->montarSplitter($m1->fresh(), [
            'ratio' => '1:4',
            'input_strand_id' => $troncal->strands[0]->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no está libre/');

        $gestor->conectarSalida($splitter->outputs->first(), $paso->strands[0]->fresh());
    }

    /**
     * @test
     *
     * Un hilo SÍ puede salir de un splitter y alimentar una caja.
     *
     * Es el caso más común de toda la red —el splitter reparte y cada
     * salida se va por una fibra hasta su caja— y el modelo tiene que
     * admitirlo: son los dos extremos del mismo hilo.
     */
    public function un_hilo_puede_salir_de_un_splitter_y_alimentar_una_caja(): void
    {
        $mufla = $this->mufla('MUF-001');
        $troncal = $this->cable('TRC', 12, 1, 12);
        $distribucion = $this->cable('DIS', 8, 1, 8);

        $gestor = app(FiberPlantManager::class);

        $splitter = $gestor->montarSplitter($mufla, [
            'ratio' => '1:8',
            'input_strand_id' => $troncal->strands[0]->id,
        ]);

        $gestor->conectarSalida($splitter->outputs->first(), $distribucion->strands[0]);

        $caja = $this->caja();
        $gestor->alimentarCaja($caja, $distribucion->strands[0]->fresh());

        $hilo = $distribucion->strands[0]->fresh();

        $this->assertSame(2, $hilo->conexiones());
        $this->assertTrue($hilo->estaEnUso());
        $this->assertFalse($hilo->estaLibre());
        // Y ya no admite un tercero
        $this->assertFalse($hilo->estaDisponible());
    }

    // ==================== Impacto de un corte ====================

    /**
     * Arma una red pequeña pero completa:
     *
     *   OLT ──TRC(12)──▶ MUF-001 ──[splitter 1:8]──▶ DIS(8) ──▶ NAP001
     *                                            └──▶ DIS(8) ──▶ NAP002
     *
     * @return array{mufla: SpliceClosure, troncal: FiberCable, distribucion: FiberCable, cajas: array<int, NapBox>}
     */
    private function redDeEjemplo(): array
    {
        $gestor = app(FiberPlantManager::class);

        $mufla = $this->mufla('MUF-001');
        $troncal = $this->cable('TRC-001', 12, 1, 12, desde: $this->olt, hasta: $mufla);
        $distribucion = $this->cable('DIS-001', 8, 1, 8, desde: $mufla);

        $splitter = $gestor->montarSplitter($mufla, [
            'ratio' => '1:8',
            'input_strand_id' => $troncal->strands->first()->id,
        ]);

        $cajas = [];

        foreach ([0, 1] as $i) {
            $gestor->conectarSalida($splitter->outputs[$i], $distribucion->strands[$i]);

            $caja = $this->caja();
            $gestor->alimentarCaja($caja, $distribucion->strands[$i]->fresh());
            $cajas[] = $caja->fresh();
        }

        return compact('mufla', 'troncal', 'distribucion', 'cajas');
    }

    /** @test */
    public function abrir_una_mufla_deja_sin_servicio_a_lo_que_cuelga_de_ella(): void
    {
        $red = $this->redDeEjemplo();

        // Un cliente en cada caja
        $contratos = [];

        foreach ($red['cajas'] as $caja) {
            $contrato = $this->contrato();
            app(OdnManager::class)->asignarPuerto($contrato, $caja->ports->first());
            $contratos[] = $contrato;
        }

        $impacto = app(FiberPathTracer::class)->impactoDeMufla($red['mufla']);

        $this->assertSame(2, $impacto['total_cajas']);
        $this->assertSame(2, $impacto['total_clientes']);

        $numeros = array_column($impacto['contratos'], 'numero');

        foreach ($contratos as $contrato) {
            $this->assertContains($contrato->numero_visible, $numeros);
        }
    }

    /** @test */
    public function cortar_el_troncal_tumba_todo_lo_que_va_detras(): void
    {
        $red = $this->redDeEjemplo();

        $impacto = app(FiberPathTracer::class)->impactoDeCable($red['troncal']);

        $this->assertSame(2, $impacto['total_cajas']);
        $this->assertStringContainsString('TRC-001', $impacto['accion']);
    }

    /**
     * @test
     *
     * Una caja que NO depende del elemento cortado no puede aparecer.
     *
     * Es el error que haría inútil la herramienta: si el listado
     * incluye clientes que no se van a caer, nadie se fía de él.
     */
    public function una_caja_alimentada_por_otro_camino_no_aparece_en_el_impacto(): void
    {
        $red = $this->redDeEjemplo();
        $gestor = app(FiberPlantManager::class);

        // Segunda rama, colgada de OTRA mufla y de otro troncal
        $otraMufla = $this->mufla('MUF-002');
        $otroTroncal = $this->cable('TRC-002', 12, 1, 12, desde: $this->olt, hasta: $otraMufla);
        $otraDist = $this->cable('DIS-002', 8, 1, 8, desde: $otraMufla);

        $gestor->fusionar($otraMufla, $otroTroncal->strands->first(), $otraDist->strands->first());

        $cajaAparte = $this->caja();
        $gestor->alimentarCaja($cajaAparte, $otraDist->strands->first()->fresh());

        $contratoAparte = $this->contrato();
        app(OdnManager::class)->asignarPuerto($contratoAparte, $cajaAparte->fresh()->ports->first());

        $impacto = app(FiberPathTracer::class)->impactoDeMufla($red['mufla']);

        $codigos = array_column($impacto['cajas'], 'codigo');

        $this->assertNotContains($cajaAparte->code, $codigos);
        $this->assertSame(2, $impacto['total_cajas']);
        // Y da contexto: cuántas cajas hay alcanzables en total
        $this->assertSame(3, $impacto['cajas_en_la_red']);
    }

    /** @test */
    public function la_ruta_de_una_caja_va_de_la_cabecera_hasta_ella(): void
    {
        $red = $this->redDeEjemplo();

        $ruta = app(FiberPathTracer::class)->rutaDeCaja($red['cajas'][0]);

        $this->assertCount(2, $ruta);
        // De la cabecera hacia el cliente: primero el troncal
        $this->assertSame('TRC-001', $ruta[0]['cable']);
        $this->assertSame('DIS-001', $ruta[1]['cable']);
        $this->assertSame('OLT OLT cabecera', $ruta[0]['desde']);
    }

    /** @test */
    public function una_caja_sin_hilo_asignado_no_tiene_ruta(): void
    {
        $caja = $this->caja();

        $this->assertSame([], app(FiberPathTracer::class)->rutaDeCaja($caja));
    }

    // ==================== Pantallas ====================

    /**
     * @test
     *
     * Prueba de humo de todas las pantallas del módulo.
     *
     * No comprueba contenido: comprueba que abren. Un error de sintaxis
     * en un Blade no lo ve nadie hasta que alguien entra a esa página, y
     * este módulo trae ocho vistas nuevas.
     */
    public function todas_las_pantallas_de_la_planta_abren(): void
    {
        $mufla = $this->mufla('MUF-001');
        $cable = $this->cable('TRC-001', 12, 1, 12, desde: $this->olt, hasta: $mufla);

        foreach ([
            route('closures.index'),
            route('closures.create'),
            route('closures.show', $mufla),
            route('closures.edit', $mufla),
            route('cables.index'),
            route('cables.create'),
            route('cables.show', $cable),
            route('cables.edit', $cable),
            // El mapa ahora pinta también las muflas
            route('naps.map'),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        // Y los dos endpoints JSON
        $this->getJson(route('closures.map_data'))->assertOk();
        $this->getJson(route('closures.impact', $mufla))->assertOk();
        $this->getJson(route('cables.impact', $cable))->assertOk();
    }

    /** @test */
    public function se_puede_registrar_una_fusion_desde_la_pantalla(): void
    {
        $mufla = $this->mufla('MUF-001');
        $a = $this->cable('A', 12, 1, 12, hasta: $mufla);
        $b = $this->cable('B', 12, 1, 12, desde: $mufla);

        $this->post(route('splices.store', $mufla), [
            'strand_a_id' => $a->strands[0]->id,
            'strand_b_id' => $b->strands[0]->id,
            'tray' => 1,
            'loss_db' => 0.04,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(1, \App\Models\Splice::count());
    }

    /**
     * @test
     *
     * La mufla exige dirección y punto en el mapa, igual que las cajas:
     * una mufla que no se encuentra en campo no sirve de nada.
     */
    public function la_mufla_exige_direccion_y_punto_en_el_mapa(): void
    {
        $this->post(route('closures.store'), [
            'optical_network_id' => $this->red->id,
            'code' => 'MUF-999',
            'type' => 'aerea',
            'tray_count' => 4,
            'splices_per_tray' => 12,
            'status' => SpliceClosure::OPERATIVA,
        ])->assertSessionHasErrors(['address', 'latitude', 'longitude']);

        $this->assertSame(0, SpliceClosure::count());
    }

    /** @test */
    public function no_se_puede_borrar_una_mufla_con_fusiones(): void
    {
        $mufla = $this->mufla('MUF-001');
        $a = $this->cable('A', 12, 1, 12);
        $b = $this->cable('B', 12, 1, 12);

        app(FiberPlantManager::class)->fusionar($mufla, $a->strands[0], $b->strands[0]);

        $this->delete(route('closures.destroy', $mufla))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, SpliceClosure::count());
    }

    /** @test */
    public function una_mufla_de_otra_sucursal_no_se_puede_abrir(): void
    {
        $otra = Branch::factory()->create();

        $redAjena = OpticalNetwork::create([
            'branch_id' => $otra->id,
            'name' => 'Red ajena',
            'nap_prefix' => 'AJE',
            'nap_next_number' => 1,
            'active' => true,
            'user_id' => $this->admin->id,
        ]);

        $ajena = app(FiberPlantManager::class)->crearMufla($redAjena, [
            'code' => 'AJE-001',
            'type' => 'aerea',
            'tray_count' => 1,
            'splices_per_tray' => 12,
            'address' => 'Otra ciudad',
            'latitude' => 4.6,
            'longitude' => -74.0,
            'status' => SpliceClosure::OPERATIVA,
        ]);

        $this->get(route('closures.show', $ajena))->assertForbidden();
    }

    // ==================== Desmontar un splitter ====================

    /** @test */
    public function un_splitter_se_puede_desmontar(): void
    {
        $red = $this->redDeEjemplo();
        $splitter = $red['mufla']->splitters()->firstOrFail();

        $this->delete(route('splitters.destroy', $splitter))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, \App\Models\Splitter::count());
        // Sus salidas se van con él
        $this->assertSame(0, \App\Models\SplitterOutput::count());
    }

    /**
     * @test
     *
     * Desmontarlo suelta los hilos, no los borra.
     *
     * Es lo que pasa en la realidad: el splitter se saca de la bandeja
     * y los hilos quedan ahí dentro, disponibles para otra cosa.
     */
    public function desmontar_un_splitter_deja_los_hilos_sueltos(): void
    {
        $red = $this->redDeEjemplo();
        $splitter = $red['mufla']->splitters()->firstOrFail();

        $entrada = $splitter->inputStrand;
        $this->assertTrue($entrada->fresh()->estaEnUso());

        app(FiberPlantManager::class)->desmontarSplitter($splitter);

        // El hilo sigue existiendo y ahora está libre
        $this->assertNotNull($entrada->fresh());
        $this->assertSame(0, $entrada->fresh()->conexiones());
        $this->assertTrue($entrada->fresh()->estaLibre());

        // Y queda anotado quién lo hizo, con las salidas que tenía
        $this->assertTrue(
            \App\Models\Audit::where('action', 'splitters.deleted')->where('category', 'red')->exists()
        );
    }

    /**
     * @test
     *
     * Al desmontarlo, las cajas que colgaban dejan de tener camino.
     *
     * Es la comprobación de que el grafo y el borrado están de acuerdo:
     * si el impacto siguiera diciendo lo mismo, sería que el recorrido
     * está mirando datos viejos.
     */
    public function al_desmontar_el_splitter_las_cajas_pierden_su_camino(): void
    {
        $red = $this->redDeEjemplo();

        $antes = app(FiberPathTracer::class)->impactoDeCable($red['troncal']);
        $this->assertSame(2, $antes['total_cajas']);

        app(FiberPlantManager::class)->desmontarSplitter($red['mufla']->splitters()->firstOrFail());

        $despues = app(FiberPathTracer::class)->impactoDeCable($red['troncal']->fresh());

        $this->assertSame(0, $despues['total_cajas']);
        $this->assertSame(0, $despues['cajas_en_la_red']);
    }

    // ==================== Alimentación de la caja ====================

    /** @test */
    public function la_caja_se_puede_alimentar_desde_su_formulario(): void
    {
        $mufla = $this->mufla('MUF-001');
        $distribucion = $this->cable('DIS-001', 8, 1, 8, desde: $mufla);
        $caja = $this->caja();

        $this->put(route('naps.update', $caja), [
            'optical_network_id' => $this->red->id,
            'pon_port_id' => $caja->pon_port_id,
            'capacity' => $caja->capacity,
            'address' => $caja->address,
            'latitude' => $caja->latitude,
            'longitude' => $caja->longitude,
            'status' => NapBox::OPERATIVA,
            'feed_strand_id' => $distribucion->strands[0]->id,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame($distribucion->strands[0]->id, $caja->fresh()->feed_strand_id);
    }

    /**
     * @test
     *
     * Un hilo de OTRA red no puede alimentar esta caja: sería
     * documentar una fibra que físicamente no llega ahí.
     */
    public function no_se_puede_alimentar_una_caja_con_un_hilo_de_otra_red(): void
    {
        $otraRed = OpticalNetwork::create([
            'branch_id' => $this->branch->id,
            'name' => 'Red norte',
            'nap_prefix' => 'NRT',
            'nap_next_number' => 1,
            'active' => true,
            'user_id' => $this->admin->id,
        ]);

        $cableAjeno = app(FiberPlantManager::class)->crearCable($otraRed, [
            'code' => 'AJE-001',
            'type' => 'distribucion',
            'fiber_count' => 8,
            'buffer_count' => 1,
            'fibers_per_buffer' => 8,
        ])->load('strands');

        $caja = $this->caja();

        $this->put(route('naps.update', $caja), [
            'optical_network_id' => $this->red->id,
            'pon_port_id' => $caja->pon_port_id,
            'capacity' => $caja->capacity,
            'address' => $caja->address,
            'latitude' => $caja->latitude,
            'longitude' => $caja->longitude,
            'status' => NapBox::OPERATIVA,
            'feed_strand_id' => $cableAjeno->strands[0]->id,
        ])->assertForbidden();

        $this->assertNull($caja->fresh()->feed_strand_id);
    }

    /** @test */
    public function el_selector_solo_ofrece_hilos_con_un_extremo_libre(): void
    {
        $mufla = $this->mufla('MUF-001');
        $cable = $this->cable('DIS-001', 8, 1, 8, desde: $mufla);
        $otro = $this->cable('OTR-001', 8, 1, 8);
        $m2 = $this->mufla('MUF-002');

        $gestor = app(FiberPlantManager::class);

        // Un hilo con los dos extremos ocupados no debe ofrecerse
        $gestor->fusionar($mufla, $cable->strands[0], $otro->strands[0]);
        $gestor->fusionar($m2, $cable->strands[0]->fresh(), $otro->strands[1]);

        $hilos = $this->getJson(route('cables.strands', $cable))->assertOk()->json();

        $numeros = array_column($hilos, 'numero');

        $this->assertNotContains(1, $numeros, 'Un hilo con los dos extremos ocupados no puede ofrecerse.');
        $this->assertContains(2, $numeros);
        $this->assertCount(7, $hilos);
    }

    /**
     * @test
     *
     * Al editar, el hilo que ya tiene la caja debe seguir apareciendo.
     *
     * Si no, al abrir el formulario desaparecería del desplegable y se
     * perdería la asignación con solo pulsar Guardar.
     */
    public function el_hilo_ya_asignado_sigue_apareciendo_al_editar(): void
    {
        $mufla = $this->mufla('MUF-001');
        $cable = $this->cable('DIS-001', 8, 1, 8, desde: $mufla);
        $caja = $this->caja();

        app(FiberPlantManager::class)->alimentarCaja($caja, $cable->strands[0]);

        $hilos = $this->getJson(route('cables.strands', [
            'cable' => $cable->id,
            'actual' => $cable->strands[0]->id,
        ]))->assertOk()->json();

        $this->assertContains($cable->strands[0]->id, array_column($hilos, 'id'));
    }

    /** @test */
    public function la_ficha_de_la_caja_muestra_por_donde_le_llega(): void
    {
        $red = $this->redDeEjemplo();

        $ruta = $this->get(route('naps.show', $red['cajas'][0]))
            ->assertOk()
            ->viewData('ruta');

        $this->assertCount(2, $ruta);
        $this->assertSame('TRC-001', $ruta[0]['cable']);
    }

    // ==================== Trazabilidad ====================

    /** @test */
    public function todo_lo_de_la_planta_queda_en_la_bitacora(): void
    {
        $mufla = $this->mufla('MUF-001');
        $a = $this->cable('A', 12, 1, 12);
        $b = $this->cable('B', 12, 1, 12);

        app(FiberPlantManager::class)->fusionar($mufla, $a->strands[0], $b->strands[0]);

        $acciones = \App\Models\Audit::pluck('action');

        $this->assertTrue($acciones->contains('splice_closures.created'));
        $this->assertTrue($acciones->contains('fiber_cables.created'));
        $this->assertTrue($acciones->contains('splices.created'));

        $this->assertTrue(
            \App\Models\Audit::where('action', 'splices.created')->where('category', 'red')->exists()
        );
    }

    // ==================== Utilidades ====================

    private function cable(
        string $codigo,
        int $hilos,
        int $buffers,
        int $porBuffer,
        $desde = null,
        $hasta = null,
    ): FiberCable {
        $datos = [
            'code' => $codigo,
            'type' => 'distribucion',
            'fiber_count' => $hilos,
            'buffer_count' => $buffers,
            'fibers_per_buffer' => $porBuffer,
            'length_m' => 500,
        ];

        if ($desde) {
            $datos['from_type'] = $desde::class;
            $datos['from_id'] = $desde->id;
        }

        if ($hasta) {
            $datos['to_type'] = $hasta::class;
            $datos['to_id'] = $hasta->id;
        }

        return app(FiberPlantManager::class)->crearCable($this->red, $datos)->load('strands');
    }

    private function mufla(string $codigo, int $trayCount = 4, int $splicesPerTray = 12): SpliceClosure
    {
        return app(FiberPlantManager::class)->crearMufla($this->red, [
            'code' => $codigo,
            'type' => 'aerea',
            'tray_count' => $trayCount,
            'splices_per_tray' => $splicesPerTray,
            'address' => 'Calle 30 # 40-50',
            'latitude' => 6.24,
            'longitude' => -75.58,
            'status' => SpliceClosure::OPERATIVA,
        ]);
    }

    private function caja(): NapBox
    {
        $pon = PonPort::firstOrCreate(
            ['olt_id' => $this->olt->id, 'frame' => 0, 'slot' => 1, 'port' => 1],
            ['optical_network_id' => $this->red->id, 'max_onts' => 64, 'active' => true],
        );

        return app(OdnManager::class)->crearCaja($this->red, [
            'pon_port_id' => $pon->id,
            'capacity' => 8,
            'address' => 'Carrera 50 # 20-30',
            'latitude' => 6.25,
            'longitude' => -75.59,
            'status' => NapBox::OPERATIVA,
        ])->load('ports');
    }

    private function contrato(): Contract
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        return Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'user_id' => $this->admin->id,
        ]);
    }
}
