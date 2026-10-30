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
    public function test_update_database_applies_pending_migrations_without_re_entering_settings(): void
    {
        LocalSetting::create(['hosted_api_url'=>'https://host.example','runner_token'=>str_repeat('s',64)]);
        $this->getJson('/api/setup/status')->assertJsonPath('pending_migrations',0);
        $this->artisan('migrate:rollback',['--step'=>1,'--force'=>true]); // as if a git pull brought a new migration
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('pos_transactions'));
        $this->getJson('/api/setup/status')->assertJsonPath('pending_migrations',1);

        $this->postJson('/api/setup/migrate')->assertOk()->assertJsonPath('applied',1)->assertJsonPath('message','Database updated (1 change)');

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('pos_transactions'));
        $this->getJson('/api/setup/status')->assertJsonPath('pending_migrations',0);
        $this->postJson('/api/setup/migrate')->assertOk()->assertJsonPath('message','Database is already up to date.');
    }
    public function test_saving_settings_keeps_the_saved_token_and_password_when_left_blank(): void
    {
        LocalSetting::create(['hosted_api_url'=>'https://host.example','runner_token'=>str_repeat('s',64)]);
        config(['database.connections.mysql.host'=>'127.0.0.1','database.connections.mysql.port'=>3306,'database.connections.mysql.database'=>'db_quinos_sync',
            'database.connections.mysql.username'=>'root','database.connections.mysql.password'=>'saved-secret']);
        $seen = [];
        $controller = \Mockery::mock(SetupController::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $controller->shouldReceive('ensureDatabase')->andReturnUsing(function ($data) use (&$seen) { $seen[] = $data['db_password']; throw new \PDOException('stop here'); });
        $this->app->instance(SetupController::class,$controller);
        $form = ['db_host'=>'127.0.0.1','db_port'=>3306,'db_database'=>'db_quinos_sync','db_username'=>'root','hosted_api_url'=>'https://host.example'];

        $this->postJson('/api/setup',$form)->assertUnprocessable()->assertJsonMissingValidationErrors(['runner_token','db_password']);
        $this->postJson('/api/setup',['db_database'=>'other_database']+$form)->assertUnprocessable();

        $this->assertSame(['saved-secret',''],$seen); // same connection keeps it; another database needs it entered
    }
    public function test_env_password_round_trips_special_characters(): void
    {
        $method=new \ReflectionMethod(SetupController::class,'envValue');
        $method->setAccessible(true);
        $password=' tricky$1${HOME}#"\\secret\' ';
        $value=$method->invoke(new SetupController,$password);
        $this->assertSame($password,\Dotenv\Dotenv::parse('DB_PASSWORD='.$value)['DB_PASSWORD']);
    }

    /** @dataProvider mysqlFailures */
    public function test_setup_says_what_mysql_failed_with(int $code, string $host, string $expected): void
    {
        $e = new \PDOException('SQLSTATE[HY000] [' . $code . '] password=private-secret');
        $e->errorInfo = ['HY000', $code, 'x'];
        $controller = \Mockery::mock(SetupController::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $controller->shouldReceive('ensureDatabase')->andThrow($e);
        $this->app->instance(SetupController::class, $controller);

        $response = $this->postJson('/api/setup', [
            'db_host' => $host, 'db_port' => 3306, 'db_database' => 'd', 'db_username' => 'root',
            'db_password' => 'private-secret', 'hosted_api_url' => 'https://host.example', 'runner_token' => str_repeat('s', 64),
        ])->assertUnprocessable();

        $this->assertStringContainsString("MySQL error {$code}", $response->json('message'));
        $this->assertStringContainsString($expected, $response->json('message'));
        $this->assertStringNotContainsString('private-secret', $response->getContent());
    }

    public static function mysqlFailures(): array
    {
        return [
            'wrong password' => [1045, '127.0.0.1', 'username or password is wrong'],
            'not running on localhost' => [2002, 'localhost', 'try 127.0.0.1'],
            'not running on an ip' => [2002, '127.0.0.1', 'host and port are right'],
        ];
    }
}
