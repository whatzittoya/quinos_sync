<?php
namespace Tests\Feature\Local;

use App\Models\LocalSetting;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SetupMysqlTest extends TestCase
{
    public function test_wizard_migrates_mysql_and_encrypts_token_without_creating_users(): void
    {
        if (config('database.default') !== 'mysql') $this->markTestSkipped('Run with a fresh MySQL *_test database.');
        $db=config('database.connections.mysql');
        $this->assertStringEndsWith('_test',$db['database']);
        $path=sys_get_temp_dir().'/quinos-setup-'.bin2hex(random_bytes(8));
        mkdir($path,0700);
        copy(base_path('.env.example'),$path.'/.env');
        $this->app->useEnvironmentPath($path);
        config(['app.key'=>'base64:'.base64_encode(str_repeat('x',32))]);
        try {
            $this->getJson('/api/setup/status')->assertOk()->assertJsonPath('ready',false);
            $token=str_repeat('test-token-',7);
            $this->postJson('/api/setup',[
                'db_host'=>$db['host'],'db_port'=>$db['port'],'db_database'=>$db['database'],
                'db_username'=>$db['username'],'db_password'=>$db['password'],
                'hosted_api_url'=>'https://host.example','runner_token'=>$token,
            ])->assertOk()->assertJsonPath('ok',true);
            $this->assertGreaterThan(0,DB::table('migrations')->count());
            $this->assertSame(0,DB::table('users')->count());
            $this->assertSame($token,LocalSetting::first()->runner_token);
            $this->assertNotSame($token,DB::table('local_settings')->value('runner_token'));
            $this->getJson('/api/setup/status')->assertOk()->assertJsonPath('ready',true);
            $this->getJson('/api/local-runner/runs')->assertOk();
            $this->postJson('/api/setup',[])->assertUnprocessable();
            $values=\Dotenv\Dotenv::parse(file_get_contents($path.'/.env'));
            $this->assertSame($db['database'],$values['DB_DATABASE']);
            $this->assertSame($db['password'],$values['DB_PASSWORD']);
        } finally { unlink($path.'/.env'); rmdir($path); }
    }
}
