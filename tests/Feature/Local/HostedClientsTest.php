<?php

namespace Tests\Feature\Local;

use App\Models\Client;
use App\Models\LocalSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HostedClientsTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(array $over = []): array
    {
        return $over + ['id' => 7, 'name' => 'Pescado Sanur', 'timezone' => 'Asia/Makassar', 'quinos_email' => 'p@example.com',
            'quinos_password' => 'secret', 'quinos_company_id' => null, 'shift_assign_by' => 'open_time', 'shifts' => [],
            'has_mobile_api_token' => false];
    }

    protected function setUp(): void
    {
        parent::setUp();
        LocalSetting::create(['hosted_api_url' => 'https://host.example', 'runner_token' => str_repeat('s', 64)]);
    }

    public function test_creating_a_client_saves_it_on_hosted_and_keeps_a_local_copy(): void
    {
        $sent = null;
        Http::fake(function (Request $request) use (&$sent) {
            $sent = $request;

            return Http::response(['tenant' => $this->tenant()], 201);
        });

        $this->postJson('/api/hosted-clients', ['name' => 'Pescado Sanur', 'quinos_email' => 'p@example.com', 'quinos_password' => 'secret', 'timezone' => 'Asia/Makassar'])
            ->assertCreated()->assertJsonPath('data.id', 7)->assertJsonPath('data.has_quinos_password', true)
            ->assertJsonMissingPath('data.quinos_password');

        $this->assertSame('https://host.example/api/runner/v1/tenants', $sent->url());
        $this->assertSame('POST', $sent->method());
        $this->assertSame('Bearer '.str_repeat('s', 64), $sent->header('Authorization')[0]);
        $this->assertSame('Pescado Sanur', Client::find(7)->name); // same id as hosted
    }

    public function test_generating_a_key_returns_it_without_the_quinos_password(): void
    {
        Http::fake(['*/api/runner/v1/tenants/7/mobile-api-token' => Http::response([
            'token' => str_repeat('a', 40), 'url' => 'https://host.example/api/cloud2/x', 'tenant' => $this->tenant(['has_mobile_api_token' => true]),
        ])]);

        $this->postJson('/api/hosted-clients/7/mobile-api-key')->assertOk()
            ->assertJsonPath('token', str_repeat('a', 40))->assertJsonPath('data.has_mobile_api_token', true)
            ->assertJsonMissingPath('tenant')->assertJsonMissingPath('data.quinos_password');
    }

    public function test_hosted_validation_errors_and_failures_are_shown(): void
    {
        Http::fake(['*/api/runner/v1/tenants' => Http::response(['message' => 'Invalid.', 'errors' => ['quinos_email' => ['The quinos email must be valid.']]], 422)]);
        $this->postJson('/api/hosted-clients', ['name' => 'X', 'timezone' => 'Asia/Makassar'])
            ->assertUnprocessable()->assertJsonPath('errors.quinos_email.0', 'The quinos email must be valid.');

        Http::fake(['*/api/runner/v1/tenants/9/mobile-api-token' => Http::response(['message' => 'Not Found'], 404)]);
        $this->postJson('/api/hosted-clients/9/mobile-api-key')->assertUnprocessable()
            ->assertJsonPath('message', 'The hosted app does not support managing clients yet, or the client no longer exists. Deploy its latest version first.');
    }

    public function test_list_comes_from_hosted_and_refreshes_the_local_copy(): void
    {
        Http::fake(['*/api/runner/v1/tenants' => Http::response(['tenants' => [$this->tenant(['has_mobile_api_token' => true,
            'mobile_api_token' => str_repeat('b', 40), 'mobile_api_url' => 'https://host.example/api/cloud2/x'])]])]);

        $this->getJson('/api/hosted-clients')->assertOk()->assertJsonPath('data.0.has_mobile_api_token', true)
            ->assertJsonPath('data.0.mobile_api_token', str_repeat('b', 40))->assertJsonPath('data.0.mobile_api_url', 'https://host.example/api/cloud2/x')
            ->assertJsonMissingPath('data.0.quinos_password');
        $this->assertTrue(Client::whereKey(7)->exists());
    }
}
