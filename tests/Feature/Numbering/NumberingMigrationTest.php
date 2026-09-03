<?php

namespace Tests\Feature\Numbering;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\DocumentSequence;
use App\Models\Plan;
use App\Models\User;
use App\Services\ContractNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * El paso de los contadores viejos a `document_sequences`.
 *
 * LO QUE NO PUEDE PASAR
 * ---------------------
 * Que un contador migrado quede POR DEBAJO de un documento ya emitido.
 * Si eso ocurre, el próximo documento repite un número que ya existe —
 * y en contratos lo rechazaría el UNIQUE, pero solo después de que
 * alguien haya rellenado el formulario entero.
 *
 * De ahí que el contador salga del mayor de dos:
 *
 *     current_number = MAX(contador viejo, mayor consecutivo emitido)
 *
 * No es paranoia. El prefijo se puede cambiar, y hay contratos
 * importados de otros sistemas que traen su propio número. El
 * generador viejo ya hacía ese `max()` en cada reserva; la migración
 * tiene que preservar esa garantía.
 *
 * EL COMANDO NO ES OBLIGATORIO
 * ----------------------------
 * `DocumentNumberService` crea la serie que le falte arrancando desde
 * el mayor consecutivo realmente usado. Si el comando no se ejecuta,
 * la serie nace bien igualmente la primera vez que se pide un número.
 * Eso también se prueba aquí, porque es lo que hace que la migración
 * sea revisable sin ser un bloqueo.
 */
class NumberingMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function sucursal(string $prefijo, int $contador): Branch
    {
        return Branch::factory()->create([
            'contract_prefix' => $prefijo,
            'contract_next_number' => $contador,
        ]);
    }

    private function contrato(Branch $sucursal, ?string $numero = null): Contract
    {
        $autor = User::factory()->create();

        $plan = Plan::create([
            'name' => 'Plan ' . fake()->unique()->numerify('####'),
            'branch_id' => $sucursal->id,
            'user_id' => $autor->id,
        ]);

        $cliente = Client::factory()->create([
            'company_id' => $sucursal->company_id,
            'branch_id' => $sucursal->id,
            'user_id' => $autor->id,
        ]);

        return Contract::factory()->create([
            'branch_id' => $sucursal->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'contract_number' => $numero,
            'status' => 'Activo',
            'user_id' => $autor->id,
        ]);
    }

    private function serieDe(Branch $sucursal): ?DocumentSequence
    {
        return DocumentSequence::withoutGlobalScope('empresa')
            ->where('branch_id', $sucursal->id)
            ->where('document_type', DocumentSequence::CONTRATO)
            ->first();
    }

    // ==================== El simulacro ====================

    public function test_el_simulacro_no_escribe_nada(): void
    {
        // Un contador mal copiado repite números ya emitidos. Tiene que
        // poder revisarse ANTES de escribir.
        $sucursal = $this->sucursal('ENG', 25);

        $this->artisan('numeracion:migrar', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertNull($this->serieDe($sucursal), 'El simulacro creó la serie.');
    }

    public function test_el_simulacro_muestra_el_antes_y_el_despues(): void
    {
        $this->sucursal('ENG', 25);

        $this->artisan('numeracion:migrar', ['--dry-run' => true])
            ->expectsOutputToContain('SIMULACRO')
            ->expectsOutputToContain('ENG')
            ->assertSuccessful();
    }

    // ==================== La migración ====================

    public function test_migra_el_contador_de_la_sucursal(): void
    {
        $sucursal = $this->sucursal('ENG', 25);

        $this->artisan('numeracion:migrar')->assertSuccessful();

        $serie = $this->serieDe($sucursal);

        $this->assertNotNull($serie);
        $this->assertSame('ENG', $serie->prefix);
        $this->assertSame(25, $serie->current_number);
        // Seis dígitos, como siempre: ENG000026.
        $this->assertSame(6, $serie->padding);
    }

    public function test_gana_el_mayor_emitido_si_supera_al_contador(): void
    {
        // ESTE es el caso que la migración no puede fallar. Pasa con
        // contratos importados de otro sistema: traen su número y el
        // contador de la sucursal se quedó atrás.
        $sucursal = $this->sucursal('ENG', 5);

        $this->contrato($sucursal, 'ENG000900');

        $this->artisan('numeracion:migrar')->assertSuccessful();

        $this->assertSame(900, $this->serieDe($sucursal)->current_number);
    }

    public function test_gana_el_contador_si_supera_a_lo_emitido(): void
    {
        // Al revés también: puede haber contratos borrados, o el
        // contador puede ir por delante porque se reservó un número
        // que no llegó a usarse. Bajarlo repetiría ese número.
        $sucursal = $this->sucursal('ENG', 500);

        $this->contrato($sucursal, 'ENG000003');

        $this->artisan('numeracion:migrar')->assertSuccessful();

        $this->assertSame(500, $this->serieDe($sucursal)->current_number);
    }

    public function test_solo_cuenta_los_del_prefijo_actual(): void
    {
        // Si el prefijo cambió, los contratos viejos llevan el anterior
        // y sus números no dicen nada del consecutivo nuevo.
        $sucursal = $this->sucursal('NUE', 3);

        $this->contrato($sucursal, 'VIE000800');

        $this->artisan('numeracion:migrar')->assertSuccessful();

        $this->assertSame(3, $this->serieDe($sucursal)->current_number);
    }

    public function test_volver_a_ejecutarlo_no_retrocede_el_contador(): void
    {
        // El comando es idempotente y el contador SOLO sube. Si la
        // serie ya entregó números, un segundo pase no puede bajarla.
        $sucursal = $this->sucursal('ENG', 10);

        $this->artisan('numeracion:migrar')->assertSuccessful();

        $this->serieDe($sucursal)->update(['current_number' => 99]);

        $this->artisan('numeracion:migrar')->assertSuccessful();

        $this->assertSame(99, $this->serieDe($sucursal)->current_number);
    }

    public function test_verifica_que_nada_emitido_supere_el_contador(): void
    {
        $sucursal = $this->sucursal('ENG', 5);
        $this->contrato($sucursal, 'ENG000900');

        $this->artisan('numeracion:migrar')
            ->expectsOutputToContain('Verificación correcta')
            ->assertSuccessful();
    }

    // ==================== Después de migrar, se sigue numerando ====================

    public function test_el_siguiente_contrato_continua_donde_quedo(): void
    {
        $sucursal = $this->sucursal('ENG', 25);

        $this->artisan('numeracion:migrar')->assertSuccessful();

        $numero = app(ContractNumberGenerator::class)->siguiente($sucursal->id);

        $this->assertSame('ENG000026', $numero);
    }

    public function test_sin_migrar_la_serie_nace_bien_igualmente(): void
    {
        // El comando no es obligatorio para operar: es lo que permite
        // que sea revisable sin ser un bloqueo del despliegue.
        $sucursal = $this->sucursal('ENG', 40);

        $this->assertNull($this->serieDe($sucursal));

        $numero = app(ContractNumberGenerator::class)->siguiente($sucursal->id);

        // Arranca desde el mayor REALMENTE usado, que aquí es cero: no
        // hay contratos. El contador viejo de la sucursal no lo mira
        // porque la semilla mira los documentos, no la columna.
        $this->assertSame('ENG000001', $numero);
        $this->assertNotNull($this->serieDe($sucursal));
    }

    public function test_sin_migrar_tampoco_repite_un_numero_existente(): void
    {
        // Es la garantía que hace que no migrar sea seguro.
        $sucursal = $this->sucursal('ENG', 0);

        $this->contrato($sucursal, 'ENG000777');

        $numero = app(ContractNumberGenerator::class)->siguiente($sucursal->id);

        $this->assertSame('ENG000778', $numero);
    }

    // ==================== Cada sucursal, la suya ====================

    public function test_migra_una_serie_por_sucursal(): void
    {
        $empresa = Company::factory()->create();

        $norte = Branch::factory()->create([
            'company_id' => $empresa->id,
            'contract_prefix' => 'NOR',
            'contract_next_number' => 10,
        ]);

        $sur = Branch::factory()->create([
            'company_id' => $empresa->id,
            'contract_prefix' => 'SUR',
            'contract_next_number' => 77,
        ]);

        $this->artisan('numeracion:migrar')->assertSuccessful();

        $this->assertSame(10, $this->serieDe($norte)->current_number);
        $this->assertSame(77, $this->serieDe($sur)->current_number);
    }

    // ==================== El prefijo se sigue editando en la sucursal ====================

    public function test_cambiar_el_prefijo_de_la_sucursal_llega_a_la_serie(): void
    {
        // Sin esto, cambiar el prefijo se guardaría en la columna y los
        // contratos nuevos seguirían saliendo con el viejo — un cambio
        // que no hace nada y no avisa.
        $sucursal = $this->sucursal('VIE', 5);

        $this->artisan('numeracion:migrar')->assertSuccessful();

        $sucursal->update(['contract_prefix' => 'NUE']);

        $this->assertSame('NUE', $this->serieDe($sucursal)->prefix);

        $numero = app(ContractNumberGenerator::class)->siguiente($sucursal->id);

        $this->assertSame('NUE000006', $numero);
    }
}
