<?php
namespace Tests\Feature\Local;

use App\Models\Client;
use App\Models\LocalSyncRun;
use App\Models\LocalSyncRunItem;
use App\Models\SyncDay;
use App\Runner\LocalSyncService;
use App\Runner\RunnerApi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulerPagesTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $name, array $extra = []): Client
    {
        return tap((new Client)->forceFill($extra + ['name' => $name, 'timezone' => 'Asia/Makassar', 'shift_assign_by' => 'open_time', 'active' => true]))->save();
    }

    public function test_dashboard_reports_days_behind_and_last_sync_per_client(): void
    {
        $a = $this->client('Alpha');
        $b = $this->client('Beta');
        $today = CarbonImmutable::now('Asia/Makassar')->startOfDay();
        SyncDay::create(['client_id' => $a->id, 'package' => 'sales', 'date' => $today->subDays(3)->toDateString(), 'status' => 'done', 'synced_at' => now()]);
        SyncDay::create(['client_id' => $a->id, 'package' => 'purchase', 'date' => $today->toDateString(), 'status' => 'done', 'synced_at' => now()]);
        SyncDay::create(['client_id' => $a->id, 'package' => 'sales', 'date' => $today->toDateString(), 'status' => 'failed']);

        $rows = collect($this->getJson('/api/dashboard')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(0, collect($rows['Alpha']['reports'])->firstWhere('key', 'purchase')['days_behind']);
        $this->assertSame(3, collect($rows['Alpha']['reports'])->firstWhere('key', 'sales')['days_behind']);
        $this->assertNotNull(collect($rows['Alpha']['reports'])->firstWhere('key', 'sales')['last_synced_at']);
        $this->assertNull(collect($rows['Beta']['reports'])->firstWhere('key', 'sales')['days_behind']);
    }

    public function test_choosing_clients_for_the_scheduler(): void
    {
        $a = $this->client('Alpha');
        $b = $this->client('Beta');

        $this->putJson('/api/local-runner/scheduler/clients', ['client_ids' => [$b->id]])->assertOk()->assertJsonPath('scheduled', 1);

        $this->assertFalse($a->fresh()->scheduled);
        $this->assertTrue($b->fresh()->scheduled);
        $this->getJson('/api/clients')->assertOk()->assertJsonPath('data.0.name', 'Alpha')->assertJsonPath('data.0.scheduled', false);
    }

    public function test_scheduled_run_skips_unchecked_clients_but_manual_run_does_not(): void
    {
        $this->client('Alpha', ['id' => 7, 'scheduled' => false]);
        $cfg = ['id' => 7, 'name' => 'Alpha', 'quinos_email' => 'a@x.test', 'quinos_password' => 'p', 'timezone' => 'Asia/Makassar', 'shift_assign_by' => 'open_time', 'shifts' => []];
        $api = \Mockery::mock(RunnerApi::class);
        $api->shouldReceive('tenants')->andReturn([$cfg]);

        $run = (new LocalSyncService($api))->run(null, true, null, false, 'scheduled');

        $this->assertSame('scheduled', $run->trigger);
        $this->assertSame('success', $run->status, (string) $run->error);
        $this->assertSame(0, $run->items()->count());
        $this->assertFalse(Client::find(7)->scheduled, 'the saved choice survives a refresh from the hosted app');
    }

    public function test_log_can_be_filtered_by_client_date_and_trigger(): void
    {
        $scheduled = LocalSyncRun::create(['run_uuid' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'success', 'trigger' => 'scheduled']);
        $manual = LocalSyncRun::create(['run_uuid' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'failed', 'trigger' => 'manual']);
        $item = fn ($run, $tenant, $name, $when, $status) => LocalSyncRunItem::create([
            'local_sync_run_id' => $run->id, 'tenant_id' => $tenant, 'tenant_name' => $name, 'package' => 'sales',
            'dates' => ['2026-10-13'], 'status' => $status, 'rows' => 5, 'started_at' => $when,
        ]);
        $item($scheduled, 1, 'Alpha', '2026-10-13 02:30:00', 'success');
        $item($manual, 2, 'Beta', '2026-10-14 10:00:00', 'failed');

        $names = fn (string $q) => collect($this->getJson('/api/local-runner/logs?'.$q)->assertOk()->json('data'))->pluck('client')->all();

        $this->assertSame(['Beta', 'Alpha'], $names(''));
        $this->assertSame(['Alpha'], $names('client_id=1'));
        $this->assertSame(['Beta'], $names('from=2026-10-14'));
        $this->assertSame(['Alpha'], $names('to=2026-10-13'));
        $this->assertSame(['Alpha'], $names('trigger=scheduled'));
        $this->assertSame(['Beta'], $names('status=failed'));
        $this->getJson('/api/local-runner/logs?trigger=scheduled')->assertJsonPath('data.0.trigger', 'scheduled')->assertJsonPath('meta.total', 1);
    }

    public function test_sync_calendar_is_for_the_chosen_client_only(): void
    {
        $a = $this->client('Alpha');
        $b = $this->client('Beta');
        SyncDay::create(['client_id' => $a->id, 'package' => 'sales', 'date' => '2026-10-02', 'status' => 'done', 'rows' => 4]);
        SyncDay::create(['client_id' => $b->id, 'package' => 'sales', 'date' => '2026-10-03', 'status' => 'done', 'rows' => 9]);

        $days = $this->getJson('/api/sync/calendar?month=2026-10', ['X-Client-Id' => $a->id])->assertOk()->json('days');

        $this->assertSame(['2026-10-02'], array_keys($days));
        $this->getJson('/api/sync/calendar?month=2026-10')->assertStatus(422);
    }

    public function test_pull_status_marks_hosted_synced_days_green_without_touching_local_state(): void
    {
        $zali = $this->client('Zali');
        SyncDay::create(['client_id' => $zali->id, 'package' => 'sales', 'date' => '2026-09-30', 'status' => 'failed', 'error' => 'x']);
        SyncDay::create(['client_id' => $zali->id, 'package' => 'sales', 'date' => '2026-09-28', 'status' => 'done', 'rows' => 99]);
        \App\Models\LocalSetting::create(['hosted_api_url' => 'https://host.example', 'runner_token' => str_repeat('s', 64)]);
        \Illuminate\Support\Facades\Http::fake(['*/sync-days' => \Illuminate\Support\Facades\Http::response(['days' => [
            ['package' => 'sales', 'date' => '2026-09-28', 'rows' => 5, 'partial' => false, 'synced_at' => '2026-09-29T01:00:00+00:00'],
            ['package' => 'sales', 'date' => '2026-09-29', 'rows' => 12, 'partial' => false, 'synced_at' => '2026-09-30T01:00:00+00:00'],
            ['package' => 'sales', 'date' => '2026-09-30', 'rows' => 7, 'partial' => true, 'synced_at' => '2026-09-30T20:00:00+00:00'],
            ['package' => 'other', 'date' => '2026-09-30', 'rows' => 1, 'partial' => false, 'synced_at' => null],
        ]])]);

        $this->postJson('/api/sync/pull-status', [], ['X-Client-Id' => $zali->id])->assertOk()->assertJsonPath('pulled', 2)->assertJsonPath('on_hosted', 4);

        $day = fn ($d) => SyncDay::where('client_id', $zali->id)->where('package', 'sales')->whereDate('date', $d)->first();
        $this->assertSame(99, $day('2026-09-28')->rows, 'a day already done here is not overwritten');
        $this->assertSame('done', $day('2026-09-29')->status);
        $this->assertSame(12, $day('2026-09-29')->rows);
        $this->assertSame('done', $day('2026-09-30')->status, 'a failed local day takes the hosted result');
        $this->assertTrue($day('2026-09-30')->partial);
    }

    public function test_pull_status_for_all_clients_reports_an_unreachable_hosted_app_instead_of_failing(): void
    {
        $this->client('Zali');
        $this->client('Other');
        \App\Models\LocalSetting::create(['hosted_api_url' => 'https://host.example', 'runner_token' => str_repeat('s', 64)]);
        \Illuminate\Support\Facades\Http::fake(['*/sync-days' => \Illuminate\Support\Facades\Http::response('', 500)]);

        $response = $this->postJson('/api/sync/pull-status')->assertOk()->assertJsonPath('pulled', 0);

        $this->assertStringContainsString('HTTP 500', $response->json('error'));
    }

    public function test_page_tells_the_frontend_its_sub_folder_so_xampp_htdocs_works(): void
    {
        $page = $this->withServerVariables(['SCRIPT_NAME' => '/local_sync/index.php', 'SCRIPT_FILENAME' => base_path('index.php'), 'PHP_SELF' => '/local_sync/index.php'])
            ->get('/local_sync/dashboard');

        $page->assertOk()->assertSee('<meta name="app-base" content="/local_sync">', false);
    }

    public function test_page_has_an_empty_base_when_served_from_the_root(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee('<meta name="app-base" content="">', false);
    }

    public function test_scheduler_accepts_a_time_with_seconds_from_the_browser(): void
    {
        $this->postJson('/api/local-runner/scheduler', ['kind' => 'reports', 'action' => 'create', 'time' => '06:00:00'])
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors('time'); // fails later only because Windows Task Scheduler is not here
        $this->postJson('/api/local-runner/scheduler', ['kind' => 'reports', 'action' => 'create', 'time' => '6am'])
            ->assertJsonValidationErrors('time');
    }

    public function test_scheduled_task_uses_php_exe_not_apaches_httpd_exe(): void
    {
        $dir = sys_get_temp_dir().'/phpbin_'.uniqid();
        mkdir($dir.'/apache', 0777, true);
        mkdir($dir.'/php', 0777, true);
        touch($dir.'/apache/httpd.exe');
        touch($dir.'/php/php.exe');

        $picked = \App\Services\WindowsTaskScheduler::pickPhp([null, '', $dir.'/apache/httpd.exe', $dir.'/missing/php.exe', $dir.'/php/php.exe']);

        $this->assertSame($dir.'/php/php.exe', $picked);
        $this->expectException(\RuntimeException::class);
        \App\Services\WindowsTaskScheduler::pickPhp([$dir.'/apache/httpd.exe']);
    }

    public function test_a_day_with_no_documents_still_uploads_one_empty_chunk(): void
    {
        $client = $this->client('Zali');
        \App\Models\LocalSetting::create(['hosted_api_url' => 'https://host.example', 'runner_token' => str_repeat('s', 64)]);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['counts' => ['sales_transactions' => 0]])]);

        $rows = (new LocalSyncService(app(RunnerApi::class)))->push($client, 'sales', ['2026-10-13']);

        $this->assertSame(0, $rows);
        $sent = collect(\Illuminate\Support\Facades\Http::recorded())->map(fn ($pair) => $pair[0]);
        $begin = $sent->first(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/imports'));
        $chunk = $sent->first(fn ($r) => $r->method() === 'PUT');
        $this->assertSame(1, $begin['chunk_count']);
        $this->assertStringEndsWith('/chunks/0', $chunk->url());
        $this->assertSame(['sales_transactions' => []], $chunk['tables']);
    }

    public function test_calendar_shows_what_a_running_sync_is_doing(): void
    {
        $a = $this->client('Alpha');
        $b = $this->client('Beta');
        \App\Sync\SyncProgress::set($a->id, 'Reading /items, page 4');

        $this->getJson('/api/sync/calendar?month=2026-10', ['X-Client-Id' => $a->id])->assertJsonPath('progress', 'Reading /items, page 4');
        $this->getJson('/api/sync/calendar?month=2026-10', ['X-Client-Id' => $b->id])->assertJsonPath('progress', null);
    }

    public function test_scheduled_run_catches_up_on_days_that_are_missing_or_were_partial(): void
    {
        \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Makassar'));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-15 10:00:00', 'Asia/Makassar'));
        config(['runner.catchup_days' => 7]);
        $c = $this->client('Zali');
        $day = fn ($date, $package, $status, $partial = false) => SyncDay::create(['client_id' => $c->id, 'package' => $package, 'date' => $date, 'status' => $status, 'partial' => $partial]);
        $day('2026-10-13', 'sales', 'done');
        $day('2026-10-12', 'sales', 'done', true); // synced before the day ended
        $day('2026-10-11', 'sales', 'failed');
        $day('2026-10-10', 'sales', 'done');
        $day('2026-10-09', 'sales', 'done');
        $day('2026-10-08', 'sales', 'done');
        $day('2026-10-14', 'sales', 'done'); // yesterday is always included anyway

        $method = new \ReflectionMethod(LocalSyncService::class, 'reportDates');
        $method->setAccessible(true);
        $service = new LocalSyncService(\Mockery::mock(RunnerApi::class));
        $today = \Carbon\CarbonImmutable::now('Asia/Makassar');

        $this->assertSame(['2026-10-11', '2026-10-12', '2026-10-14', '2026-10-15'], $method->invoke($service, $c, 'sales', $today));
        $this->assertSame(['2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'], $method->invoke($service, $c, 'purchase', $today));

        config(['runner.catchup_days' => 0]);
        $this->assertSame(['2026-10-14', '2026-10-15'], $method->invoke($service, $c, 'purchase', $today));
        \Carbon\CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
    }

    public function test_a_failed_package_reports_the_real_reason(): void
    {
        $c = $this->client('Alpha');
        SyncDay::create(['client_id' => $c->id, 'package' => 'purchase', 'date' => '2026-10-14', 'status' => 'failed', 'error' => 'Quinos rejected the login']);
        $method = new \ReflectionMethod(LocalSyncService::class, 'assertDone');
        $method->setAccessible(true);

        $this->expectExceptionMessage('Purchase sync did not complete for 2026-10-14: Quinos rejected the login');
        $method->invoke(new LocalSyncService(\Mockery::mock(RunnerApi::class)), $c, 'purchase', ['2026-10-14']);
    }

    public function test_rows_of_a_dead_run_stop_showing_as_running(): void
    {
        $old = LocalSyncRun::create(['run_uuid' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'running', 'started_at' => now()->subHours(5)]);
        $fresh = LocalSyncRun::create(['run_uuid' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'running', 'started_at' => now()->subMinutes(10)]);
        $item = fn ($run) => LocalSyncRunItem::create(['local_sync_run_id' => $run->id, 'tenant_id' => 1, 'tenant_name' => 'A', 'package' => 'master', 'dates' => [], 'status' => 'running', 'started_at' => $run->started_at]);
        $oldItem = $item($old);
        $freshItem = $item($fresh);
        $api = \Mockery::mock(RunnerApi::class);
        $api->shouldReceive('tenants')->andReturn([]);

        (new LocalSyncService($api))->run();

        $this->assertSame('failed', $oldItem->fresh()->status);
        $this->assertStringContainsString('Interrupted', $oldItem->fresh()->error);
        $this->assertSame('failed', $old->fresh()->status);
        $this->assertSame('running', $freshItem->fresh()->status, 'a run that is still young is left alone');
    }

    public function test_two_syncs_of_one_client_do_not_run_at_once(): void
    {
        config(['sync.lock_wait' => 1]);
        $c = $this->client('Alpha');
        $lock = \Illuminate\Support\Facades\Cache::lock('quinos-sync-client:'.$c->id, 60);
        $this->assertTrue($lock->get());

        $this->expectException(\Illuminate\Contracts\Cache\LockTimeoutException::class);
        (new \App\Sync\SyncRunner)->run($c, ['2026-10-14'], false, null, ['sales']);
    }

    public function test_scheduled_run_marks_days_done_and_uploads_them(): void
    {
        \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::parse('2026-10-09 12:00:00', 'Asia/Makassar'));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-09 12:00:00', 'Asia/Makassar'));
        config(['runner.catchup_days' => 0]);
        \App\Models\LocalSetting::create(['hosted_api_url' => 'https://host.example', 'runner_token' => str_repeat('s', 64)]);
        // Registered before FakeQuinos, whose catch-all would otherwise answer the hosted calls too.
        \Illuminate\Support\Facades\Http::fake([
            '*/api/runner/v1/tenants' => \Illuminate\Support\Facades\Http::response(['tenants' => [[
                'id' => 5, 'name' => 'Zali', 'quinos_email' => 'zali@example.com', 'quinos_password' => 'secret-quinos-password',
                'quinos_company_id' => null, 'timezone' => 'Asia/Makassar', 'shift_assign_by' => 'open_time', 'shifts' => [],
            ]]]),
            '*/api/runner/v1/imports*' => \Illuminate\Support\Facades\Http::response(['counts' => []]),
        ]);
        $quinos = \Tests\Support\FakeQuinos::install();
        $quinos->lists['/departments'] = [['id' => 1415, 'name' => 'FOOD', 'categories' => []]];
        $quinos->lists['/items'] = [];
        $quinos->lists['/contacts'] = [];
        $quinos->lists['/purchaseOrders'] = [\Tests\Support\QuinosDocs::order(3, '2026-10-08')];
        $quinos->details['/purchaseOrders/3'] = \Tests\Support\QuinosDocs::orderDetail(\Tests\Support\QuinosDocs::order(3, '2026-10-08'));
        $quinos->lists['/goodsReceipts'] = [];
        $quinos->lists['/purchaseInvoices'] = [];

        $run = app(LocalSyncService::class)->run(null, false, null, true, 'scheduled');

        $purchase = $run->items()->where('package', 'purchase')->first();
        $this->assertSame('success', $purchase->status, (string) $purchase->error);
        $this->assertSame(['2026-10-08', '2026-10-09'], $purchase->dates);
        $this->assertSame(2, SyncDay::where('client_id', 5)->where('package', 'purchase')->where('status', 'done')->count());
        \Carbon\CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
    }

    public function test_pull_status_survives_hosted_being_unreachable(): void
    {
        $this->client('Zali');
        \App\Models\LocalSetting::create(['hosted_api_url' => 'https://host.example', 'runner_token' => str_repeat('s', 64)]);
        \Illuminate\Support\Facades\Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Timeout was reached'));

        $this->postJson('/api/sync/pull-status')->assertOk()->assertJsonPath('pulled', 0)
            ->assertJsonPath('error', 'Could not reach the hosted app. Check this PC\'s internet connection.');
    }

    public function test_upload_retries_a_dropped_connection_but_not_a_server_answer(): void
    {
        config(['runner.retry_delay_ms' => 0, 'runner.retries' => 3]);
        \App\Models\LocalSetting::create(['hosted_api_url' => 'https://host.example', 'runner_token' => str_repeat('s', 64)]);
        $calls = 0;
        $reject = false;
        \Illuminate\Support\Facades\Http::fake(function () use (&$calls, &$reject) {
            $calls++;
            if ($reject) {
                return \Illuminate\Support\Facades\Http::response(['message' => 'No'], 422);
            }
            if ($calls < 3) {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Timeout was reached');
            }

            return \Illuminate\Support\Facades\Http::response(['tenants' => [['id' => 1]]]);
        });

        $this->assertSame([['id' => 1]], app(RunnerApi::class)->tenants());
        $this->assertSame(3, $calls, 'two dropped connections are retried, the third attempt answers');

        $reject = true;
        $calls = 0;
        try {
            app(RunnerApi::class)->begin('id', 1, 'sales', ['2026-10-14'], now()->toIso8601String(), null, [['tables' => []]]);
            $this->fail('A 422 must not be swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('No', $e->getMessage());
        }
        $this->assertSame(1, $calls, 'a server answer is not retried');
    }
}
