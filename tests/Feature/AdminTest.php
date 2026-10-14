<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_users_cannot_use_admin_endpoints(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->forClient($client)->create();

        $this->actingAs($user)->getJson('/api/admin/clients')->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/clients', ['name' => 'X'])->assertForbidden();
    }

    public function test_creating_a_client_stores_the_quinos_password_encrypted_and_never_returns_it(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/api/admin/clients', [
            'name' => 'Zali Uluwatu',
            'quinos_email' => 'zali@example.com',
            'quinos_password' => 'plain-secret',
        ])->assertCreated()
            ->assertJsonPath('data.has_quinos_password', true)
            ->assertJsonMissingPath('data.quinos_password');

        $this->assertStringNotContainsString('plain-secret', $response->getContent());

        $id = $response->json('data.id');
        $raw = \DB::table('clients')->where('id', $id)->value('quinos_password');
        $this->assertNotSame('plain-secret', $raw);
        $this->assertSame('plain-secret', Client::find($id)->quinos_password);
    }

    public function test_new_clients_get_default_shifts(): void
    {
        $admin = User::factory()->admin()->create();

        $id = $this->actingAs($admin)->postJson('/api/admin/clients', ['name' => 'Zali'])
            ->assertCreated()->json('data.id');

        $this->assertSame(3, Client::find($id)->shifts()->count());
    }

    public function test_blank_password_on_update_keeps_the_stored_one(): void
    {
        $admin = User::factory()->admin()->create();
        $client = Client::factory()->create(['quinos_password' => 'keep-me']);

        $this->actingAs($admin)->putJson("/api/admin/clients/{$client->id}", [
            'name' => 'Renamed', 'quinos_password' => '',
        ])->assertOk();

        $client->refresh();
        $this->assertSame('Renamed', $client->name);
        $this->assertSame('keep-me', $client->quinos_password);
    }

    public function test_client_role_users_need_a_client(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'U', 'email' => 'u@example.com', 'password' => 'password123', 'role' => 'client',
        ])->assertUnprocessable()->assertJsonValidationErrors('client_id');
    }

    public function test_last_admin_cannot_be_demoted_or_delete_themselves(): void
    {
        $admin = User::factory()->admin()->create();
        $client = Client::factory()->create();

        $this->actingAs($admin)->putJson("/api/admin/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'client', 'client_id' => $client->id,
        ])->assertUnprocessable();

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$admin->id}")->assertUnprocessable();
        $this->assertTrue($admin->fresh()->isAdmin());
    }
}
