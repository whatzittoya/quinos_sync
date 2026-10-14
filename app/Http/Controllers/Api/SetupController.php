<?php

namespace App\Http\Controllers\Api;

use App\Models\LocalSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class SetupController
{
    public function status(): array
    {
        $migrated = false;
        $ready = false;
        $hostedApiUrl = null;
        $tokenConfigured = false;
        try { $setting = LocalSetting::first(); $hostedApiUrl = $setting?->hosted_api_url; $tokenConfigured = filled($setting?->runner_token); } catch (Throwable) { /* database may not be ready */ }
        try {
            $migrated = Schema::hasTable('local_settings') && Schema::hasTable('local_sync_runs');
            if ($migrated) {
                $settings = LocalSetting::first();
                $ready = $settings && filled($settings->hosted_api_url) && filled($settings->runner_token);
            }
        } catch (Throwable) { /* First run may have no configured database. */ }
        return ['ready' => (bool) $ready, 'migrated' => $migrated, 'database' => ['host' => env('DB_HOST', '127.0.0.1'), 'port' => (int) env('DB_PORT', 3306), 'database' => env('DB_DATABASE', ''), 'username' => env('DB_USERNAME', 'root')], 'hosted_api_url' => $hostedApiUrl ?: env('RUNNER_API_URL', 'https://quinos.qaccounting.id'), 'token_configured' => $migrated && $tokenConfigured];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'db_host' => ['required', 'regex:/^[A-Za-z0-9.\-:]+$/', 'max:255'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'regex:/^[A-Za-z0-9_]+$/', 'max:64'],
            'db_username' => ['required', 'string', 'max:128'],
            'db_password' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n\x00]/'],
            'hosted_api_url' => ['required', 'url:http,https', 'max:500'],
            'runner_token' => ['required', 'string', 'min:32', 'max:255'],
        ]);
        $data['db_password'] ??= '';
        $lock = fopen(storage_path('framework/setup.lock'), 'c');
        abort_unless($lock && flock($lock, LOCK_EX | LOCK_NB), 409, 'Setup is already running.');
        $stage = 'checking MySQL';
        try {
            @set_time_limit(180);
            $currentToken = null;
            if (blank($data['runner_token'] ?? null)) {
                try { $currentToken = LocalSetting::first()?->runner_token; } catch (Throwable) { /* replace an unreadable old token */ }
            }
            $this->ensureDatabase($data);
            config(['database.default' => 'mysql', 'database.connections.mysql.url' => null,
                'database.connections.mysql.unix_socket' => '',
                'database.connections.mysql.host' => $data['db_host'],
                'database.connections.mysql.port' => $data['db_port'],
                'database.connections.mysql.database' => $data['db_database'],
                'database.connections.mysql.username' => $data['db_username'],
                'database.connections.mysql.password' => $data['db_password']]);
            DB::purge('mysql');
            $stage = 'running migrations';
            if (Artisan::call('migrate', ['--force' => true]) !== 0) {
                throw new RuntimeException('Migration failed.');
            }
            if (blank($data['runner_token'] ?? null) && blank($currentToken)) throw new RuntimeException('Enter the hosted runner API token.');
            $stage = 'saving hosted connection';
            $token = filled($data['runner_token'] ?? null) ? $data['runner_token'] : $currentToken;
            DB::table('local_settings')->updateOrInsert(['id' => 1], [
                'hosted_api_url' => rtrim($data['hosted_api_url'], '/'), 'runner_token' => Crypt::encryptString($token),
                'updated_at' => now(), 'created_at' => now(),
            ]);
            $stage = 'writing local configuration';
            $this->writeEnv($data);
            return response()->json(['ok' => true, 'message' => 'Setup complete.']);
        } catch (PDOException) {
            return response()->json(['message' => 'Could not connect to MySQL. Check that MySQL is running and the database username, password, host and port are correct. The user must have permission to create tables.'], 422);
        } catch (Throwable $e) {
            report($e);
            $message = preg_replace('/(password|token|secret)[^:]*:\s*[^,}]+/i', '$1: [hidden]', $e->getMessage());
            return response()->json(['message' => 'Setup failed while '.$stage.'. '.$message], 422);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function ensureDatabase(array $data): void
    {
        $dsn = "mysql:host={$data['db_host']};port={$data['db_port']};charset=utf8mb4";
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10];
        try {
            // Existing databases need no CREATE DATABASE privilege.
            new PDO($dsn.';dbname='.$data['db_database'], $data['db_username'], $data['db_password'], $options);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1049) throw $e;
            $pdo = new PDO($dsn, $data['db_username'], $data['db_password'], $options);
            $pdo->exec('CREATE DATABASE `'.$data['db_database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
    }

    protected function writeEnv(array $data): void
    {
        $path = app()->environmentFilePath();
        $content = is_file($path) ? file_get_contents($path) : file_get_contents(base_path('.env.example'));
        foreach (['DB_CONNECTION' => 'mysql', 'DATABASE_URL' => '', 'DB_SOCKET' => '',
            'DB_HOST' => $data['db_host'], 'DB_PORT' => $data['db_port'],
            'DB_DATABASE' => $data['db_database'], 'DB_USERNAME' => $data['db_username'],
            'DB_PASSWORD' => $data['db_password'], 'APP_DEBUG' => 'false'] as $key => $value) {
            $line = $key.'='.$this->envValue((string) $value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $content = preg_match($pattern, $content)
                ? preg_replace_callback($pattern, fn () => $line, $content)
                : rtrim($content)."\n{$line}\n";
        }
        if (file_put_contents($path, $content, LOCK_EX) === false) throw new RuntimeException('Configuration file is not writable.');
        @chmod($path, 0600);
    }

    protected function envValue(string $value): string
    {
        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
