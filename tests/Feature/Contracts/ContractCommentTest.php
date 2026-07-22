<?php

namespace Tests\Feature\Contracts;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractComment;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Comentarios/notas internas sobre un contrato desde su detalle.
 */
class ContractCommentTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;
    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $role = Role::where('name', 'superadministrador')->firstOrFail();

        $this->user = User::factory()->create();
        $this->user->assignRole($role);
        $this->user->branches()->attach($this->branch->id, ['role_id' => $role->id]);

        $plan = Plan::create([
            'name' => 'Plan 100M',
            'user_id' => $this->user->id,
            'branch_id' => $this->branch->id,
        ]);

        $client = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
        ]);

        $this->contract = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'status' => 'Activo',
            'user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $role->id,
        ]);
    }

    public function test_agrega_un_comentario_al_contrato(): void
    {
        $respuesta = $this->post(route('contractComments.store', $this->contract), [
            'body' => 'El cliente acordó pagar el día 15.',
        ]);

        $respuesta->assertRedirect();
        $respuesta->assertSessionHas('success');

        $this->assertDatabaseHas('contract_comments', [
            'contract_id' => $this->contract->id,
            'user_id' => $this->user->id,
            'body' => 'El cliente acordó pagar el día 15.',
        ]);
    }

    public function test_el_comentario_no_puede_estar_vacio(): void
    {
        $respuesta = $this->post(route('contractComments.store', $this->contract), [
            'body' => '',
        ]);

        $respuesta->assertSessionHasErrors('body');
        $this->assertSame(0, ContractComment::count());
    }

    public function test_elimina_un_comentario(): void
    {
        $comment = $this->contract->comments()->create([
            'user_id' => $this->user->id,
            'body' => 'Comentario a borrar',
        ]);

        $respuesta = $this->delete(route('contractComments.destroy', $comment));

        $respuesta->assertRedirect();
        $this->assertDatabaseMissing('contract_comments', ['id' => $comment->id]);
    }

    public function test_el_detalle_del_contrato_muestra_los_comentarios(): void
    {
        $this->contract->comments()->create([
            'user_id' => $this->user->id,
            'body' => 'Nota visible en el detalle',
        ]);

        $respuesta = $this->get(route('contracts.show', $this->contract));

        $respuesta->assertOk();
        $respuesta->assertSee('Nota visible en el detalle');
    }
}
