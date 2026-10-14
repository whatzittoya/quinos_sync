<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function spa(): static
    {
        // Sanctum only starts a session for requests coming from the SPA origin.
        return $this->withHeaders(['Referer' => config('app.url')]);
    }

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        $client = Client::factory()->create();
        User::factory()->forClient($client)->create(['email' => 'a@example.com', 'password' => 'correct-horse']);

        $this->spa()->postJson('/api/login', ['email' => 'a@example.com', 'password' => 'correct-horse'])
            ->assertOk()
            ->assertJsonPath('data.email', 'a@example.com')
            ->assertJsonPath('data.role', 'client')
            ->assertJsonPath('data.client.id', $client->id)
            ->assertJsonMissingPath('data.password');
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->admin()->create(['email' => 'a@example.com', 'password' => 'correct-horse']);

        $this->spa()->postJson('/api/login', ['email' => 'a@example.com', 'password' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_user_of_a_disabled_client_cannot_log_in(): void
    {
        $client = Client::factory()->create(['active' => false]);
        User::factory()->forClient($client)->create(['email' => 'a@example.com', 'password' => 'correct-horse']);

        $this->spa()->postJson('/api/login', ['email' => 'a@example.com', 'password' => 'correct-horse'])
            ->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_api_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/settings/shifts')->assertUnauthorized();
    }

    public function test_me_returns_the_signed_in_user(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
    }
}
