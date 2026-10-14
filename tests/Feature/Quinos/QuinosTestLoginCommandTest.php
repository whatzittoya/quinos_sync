<?php

namespace Tests\Feature\Quinos;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeQuinos;
use Tests\TestCase;

class QuinosTestLoginCommandTest extends TestCase
{
    use RefreshDatabase;

    private FakeQuinos $quinos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quinos = FakeQuinos::install();
    }

    private function client(array $attributes = []): Client
    {
        return Client::factory()->create($attributes + [
            'name' => 'Zali Uluwatu',
            'quinos_email' => $this->quinos->email,
            'quinos_password' => $this->quinos->password,
        ]);
    }

    public function test_it_shows_the_company_and_saves_its_id(): void
    {
        $client = $this->client();

        $this->artisan('quinos:test-login', ['client' => (string) $client->id])
            ->expectsOutputToContain('Signed in to Quinos as company “Zali Uluwatu” (id 224)')
            ->expectsOutputToContain('Sales types: DINE IN, GRAB')
            ->assertSuccessful();

        $this->assertSame('224', $client->fresh()->quinos_company_id);
    }

    public function test_it_finds_a_client_by_name(): void
    {
        $this->client();

        $this->artisan('quinos:test-login', ['client' => 'zali uluwatu'])->assertSuccessful();
    }

    public function test_a_failed_login_exits_with_an_error_and_no_secret(): void
    {
        $client = $this->client(['quinos_password' => 'wrong-password']);

        $this->artisan('quinos:test-login', ['client' => (string) $client->id])
            ->expectsOutputToContain('Quinos rejected the login')
            ->doesntExpectOutputToContain('wrong-password')
            ->assertFailed();
    }

    public function test_all_skips_clients_without_credentials(): void
    {
        $this->client();
        $this->client(['name' => 'No login', 'quinos_email' => null, 'quinos_password' => null]);
        $this->client(['name' => 'Disabled', 'active' => false]);

        $this->artisan('quinos:test-login', ['--all' => true])
            ->expectsOutputToContain('Zali Uluwatu')
            ->doesntExpectOutputToContain('No login')
            ->doesntExpectOutputToContain('Disabled')
            ->assertSuccessful();
    }

    public function test_it_needs_to_know_which_client(): void
    {
        $this->artisan('quinos:test-login')->expectsOutputToContain('Give a client id or name')->assertFailed();
        $this->artisan('quinos:test-login', ['client' => 'Nobody'])->expectsOutputToContain('No client matches')->assertFailed();
    }

    public function test_the_output_never_contains_the_pin_or_token(): void
    {
        $client = $this->client();

        $this->artisan('quinos:test-login', ['client' => (string) $client->id])
            ->doesntExpectOutputToContain('FAKE-PIN')
            ->doesntExpectOutputToContain('FAKE-TOKEN')
            ->doesntExpectOutputToContain($this->quinos->password)
            ->assertSuccessful();
    }
}
