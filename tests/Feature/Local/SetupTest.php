<?php
namespace Tests\Feature\Local;

use App\Http\Controllers\Api\SetupController;
use App\Models\LocalSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x',32))]);
    }
    public function test_first_run_does_not_require_login_and_requires_connection_settings(): void
    {
        $this->getJson('/api/setup/status')->assertOk()->assertJsonPath('ready',false);
        $this->postJson('/api/setup',[])->assertUnprocessable()->assertJsonValidationErrors(['db_database','hosted_api_url','runner_token']);
    }
    public function test_ready_requires_a_configured_token_and_no_admin_user(): void
    {
        LocalSetting::create(['hosted_api_url'=>'https://host.example','runner_token'=>str_repeat('s',64)]);
        $this->getJson('/api/setup/status')->assertOk()->assertJsonPath('ready',true);
        $this->getJson('/api/local-runner/runs')->assertOk();
        $this->postJson('/api/setup',[])->assertUnprocessable();
    }
    public function test_setup_failure_is_safe_and_retryable(): void
    {
        $controller = \Mockery::mock(SetupController::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $controller->shouldReceive('ensureDatabase')->andThrow(new \PDOException('password=private-secret'));
        $this->app->instance(SetupController::class,$controller);
        $response=$this->postJson('/api/setup',[
            'db_host'=>'127.0.0.1','db_port'=>3306,'db_database'=>'new_database','db_username'=>'root',
            'db_password'=>'private-secret','hosted_api_url'=>'https://host.example','runner_token'=>str_repeat('s',64),
        ])->assertUnprocessable();
        $this->assertStringNotContainsString('private-secret',$response->getContent());
        $this->getJson('/api/setup/status')->assertJsonPath('ready',false);
    }
    public function test_env_password_round_trips_special_characters(): void
    {
        $method=new \ReflectionMethod(SetupController::class,'envValue');
        $method->setAccessible(true);
        $password=' tricky$1${HOME}#"\\secret\' ';
        $value=$method->invoke(new SetupController,$password);
        $this->assertSame($password,\Dotenv\Dotenv::parse('DB_PASSWORD='.$value)['DB_PASSWORD']);
    }
}
