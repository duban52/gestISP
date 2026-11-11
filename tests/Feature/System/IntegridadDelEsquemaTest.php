<?php

namespace Tests\Feature\System;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Que la base se niegue a destruir la contabilidad.
 *
 * POR QUÉ ESTO SE PRUEBA CONTRA EL ESQUEMA Y NO CONTRA UN CONTROLADOR
 * ------------------------------------------------------------------
 * Las guardas de los controladores cierran la puerta por la que entra
 * la gente, pero no son la única puerta: quedan las importaciones,
 * los comandos de consola, un `tinker` a las tres de la mañana y
 * cualquier código futuro. La regla de la clave foránea es la única
 * que se cumple siempre, venga de donde venga el borrado.
 *
 * LO QUE PASABA
 * -------------
 * El esquema encadenaba `ON DELETE CASCADE` hasta el final:
 *
 *     branches → contracts → invoices → payments
 *     users    → invoices
 *     users    → cash_registers → cash_register_transactions
 *
 * Borrar una sucursal se llevaba su facturación entera —incluidas
 * las facturas electrónicas transmitidas a la DIAN y las secuencias
 * de numeración autorizadas—. Borrar un usuario se llevaba las
 * facturas que hubiera emitido. Sin error: la base obedecía.
 *
 * Y lo más engañoso: `UserController::destroy` atrapa
 * `QueryException` para avisar de «registros asociados». Esa captura
 * NUNCA se disparaba para las facturas, porque con CASCADE no hay
 * excepción — hay borrado.
 */
class IntegridadDelEsquemaTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);
    }

    /** La regla de borrado que la base aplica hoy a esa columna. */
    private function reglaDeBorrado(string $tabla, string $columna): ?string
    {
        $fila = DB::selectOne(
            'SELECT rc.DELETE_RULE AS regla
               FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                 ON rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                AND rc.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA
              WHERE k.TABLE_SCHEMA = DATABASE()
                AND k.TABLE_NAME = ?
                AND k.COLUMN_NAME = ?
                AND k.REFERENCED_TABLE_NAME IS NOT NULL
              LIMIT 1',
            [$tabla, $columna],
        );

        return $fila->regla ?? null;
    }

    // ==================== Las reglas quedaron puestas ====================

    /** @dataProvider loQueNoSePuedeArrastrar */
    public function test_la_base_se_niega_a_borrar_en_cascada(string $tabla, string $columna): void
    {
        $this->assertSame(
            'RESTRICT',
            $this->reglaDeBorrado($tabla, $columna),
            "{$tabla}.{$columna} sigue arrastrando documentos al borrar su padre.",
        );
    }

    public static function loQueNoSePuedeArrastrar(): array
    {
        return [
            'contratos de una sucursal' => ['contracts', 'branch_id'],
            'contratos de un cliente' => ['contracts', 'client_id'],
            'facturas de un contrato' => ['invoices', 'contract_id'],
            'pagos de una factura' => ['payments', 'invoice_id'],
            'notas de una factura' => ['credit_debit_notes', 'invoice_id'],
            'retenciones de una factura' => ['payment_retentions', 'invoice_id'],
            'numeración de una sucursal' => ['invoice_numbering_sequences', 'branch_id'],
            'cajas de una sucursal' => ['cash_registers', 'branch_id'],
            'cajas de un usuario' => ['cash_registers', 'user_id'],
            'movimientos de caja' => ['cash_register_transactions', 'created_by'],
        ];
    }

    /** @dataProvider loQueSobreviveSinSuPadre */
    public function test_lo_informativo_se_queda_en_nulo(string $tabla, string $columna): void
    {
        // Perder quién emitió una factura es malo; perder la factura
        // es ilegal. Por eso aquí la regla es SET NULL y no RESTRICT:
        // el documento sobrevive a la baja de quien lo creó.
        $this->assertSame(
            'SET NULL',
            $this->reglaDeBorrado($tabla, $columna),
            "{$tabla}.{$columna} debería quedar en nulo, no arrastrar ni bloquear.",
        );
    }

    public static function loQueSobreviveSinSuPadre(): array
    {
        return [
            'la sede de origen del cliente' => ['clients', 'branch_id'],
            'quién emitió la factura' => ['invoices', 'user_id'],
        ];
    }

    // ==================== Y se cumplen de verdad ====================

    /**
     * La prueba que de verdad importa: no se comprueba la regla, se
     * intenta el borrado.
     */
    public function test_borrar_una_sucursal_con_contratos_lo_impide_la_base(): void
    {
        $contrato = $this->contrato();

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Sin pasar por el controlador: así se prueba que la
        // protección vive en la base y no solo en la pantalla.
        DB::table('branches')->where('id', $this->branch->id)->delete();

        $this->assertDatabaseHas('contracts', ['id' => $contrato->id]);
    }

    public function test_borrar_un_usuario_ya_no_se_lleva_sus_facturas(): void
    {
        $contrato = $this->contrato();

        $factura = Invoice::factory()->create([
            'contract_id' => $contrato->id,
            'client_id' => $contrato->client_id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $otro = User::factory()->create(['number_phone' => '3000000111']);
        $otro->branches()->attach($this->branch->id, [
            'role_id' => Role::where('name', 'superadministrador')->firstOrFail()->id,
        ]);

        $factura->update(['user_id' => $otro->id]);

        DB::table('user_branch')->where('user_id', $otro->id)->delete();
        DB::table('users')->where('id', $otro->id)->delete();

        // La factura sigue: lo único que se perdió es de quién era.
        $this->assertDatabaseHas('invoices', ['id' => $factura->id]);
        $this->assertNull($factura->fresh()->user_id);
    }

    public function test_la_caja_de_un_usuario_impide_darlo_de_baja(): void
    {
        // Una caja es contabilidad. No desaparece porque se dé de baja
        // al cajero.
        $otro = User::factory()->create(['number_phone' => '3000000222']);

        CashRegister::create([
            'branch_id' => $this->branch->id,
            'user_id' => $otro->id,
            'status' => 'abierta',
            'opening_balance' => 0,
            'opened_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('users')->where('id', $otro->id)->delete();
    }

    // ==================== Las pantallas lo explican ====================

    public function test_la_pantalla_no_deja_borrar_una_sucursal_con_operacion(): void
    {
        $this->contrato();

        $this->delete(route('branches.destroy', $this->branch))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('branches', ['id' => $this->branch->id]);
    }

    public function test_el_aviso_dice_que_hay_detras(): void
    {
        $this->contrato();

        $this->delete(route('branches.destroy', $this->branch));

        $this->assertStringContainsString('contratos', session('error'));
        $this->assertStringContainsString('DIAN', session('error'));
    }

    private function contrato(): Contract
    {
        $cliente = Client::factory()->create([
            'company_id' => $this->branch->company_id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        return Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => Plan::factory()->create([
                'branch_id' => $this->branch->id,
                'user_id' => $this->admin->id,
            ])->id,
            'user_id' => $this->admin->id,
        ]);
    }
}
