<?php

namespace Tests\Feature\Sync;

use App\Jobs\SyncClientDays;
use App\Models\Client;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncNightlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 02:30 on 10 Oct in Bali is still 9 Oct 18:30 in UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-10 02:30:00', 'Asia/Makassar'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 02:30:00', 'Asia/Makassar'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_it_queues_yesterday_and_today_for_clients_with_a_quinos_login(): void
    {
        Queue::fake();

        $zali = Client::factory()->create(['name' => 'Zali', 'timezone' => 'Asia/Makassar']);
        Client::factory()->create(['name' => 'No login', 'quinos_email' => null, 'quinos_password' => null]);
        Client::factory()->create(['name' => 'Disabled', 'active' => false]);

        $this->artisan('sync:nightly')->expectsOutputToContain('Zali: queued 2 day(s)')->assertSuccessful();

        Queue::assertPushed(SyncClientDays::class, 1);
        Queue::assertPushed(SyncClientDays::class, fn ($job) => $job->clientId === $zali->id
            && $job->dates === ['2026-10-09', '2026-10-10'] && $job->force === false);
    }

    public function test_each_client_gets_days_in_its_own_timezone(): void
    {
        Queue::fake();

        $bali = Client::factory()->create(['timezone' => 'Asia/Makassar']);
        $london = Client::factory()->create(['timezone' => 'Europe/London']); // still 9 Oct 19:30 there (BST)

        $this->artisan('sync:nightly')->assertSuccessful();

        Queue::assertPushed(SyncClientDays::class, fn ($job) => $job->clientId === $bali->id && $job->dates === ['2026-10-09', '2026-10-10']);
        Queue::assertPushed(SyncClientDays::class, fn ($job) => $job->clientId === $london->id && $job->dates === ['2026-10-08', '2026-10-09']);
    }

    public function test_the_days_option_widens_the_window(): void
    {
        Queue::fake();
        $client = Client::factory()->create(['timezone' => 'Asia/Makassar']);

        $this->artisan('sync:nightly', ['--days' => 3])->assertSuccessful();

        Queue::assertPushed(SyncClientDays::class, fn ($job) => $job->clientId === $client->id
            && $job->dates === ['2026-10-08', '2026-10-09', '2026-10-10']);
    }

    public function test_it_says_so_when_there_is_nothing_to_do(): void
    {
        Queue::fake();

        $this->artisan('sync:nightly')->expectsOutputToContain('No active client has a Quinos login')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_schedule_runs_the_worker_every_minute_and_the_nightly_sync(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

        $worker = $events->first(fn ($e) => str_contains($e->command, 'queue:work'));
        $nightly = $events->first(fn ($e) => str_contains($e->command, 'sync:nightly'));

        $this->assertNotNull($worker);
        $this->assertSame('* * * * *', $worker->expression);
        $this->assertStringContainsString('--stop-when-empty', $worker->command);
        $this->assertTrue($worker->withoutOverlapping, 'a long first sync must not be started twice');

        $this->assertNotNull($nightly);
        $this->assertSame('30 2 * * *', $nightly->expression);
    }
}
