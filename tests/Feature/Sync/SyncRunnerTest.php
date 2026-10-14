<?php

namespace Tests\Feature\Sync;

use App\Models\Client;
use App\Models\SyncDay;
use App\Sync\SyncDispatcher;
use App\Sync\SyncRunner;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeQuinos;
use Tests\Support\QuinosDocs as Doc;
use Tests\TestCase;

class SyncRunnerTest extends TestCase
{
    use RefreshDatabase;

    private FakeQuinos $quinos;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freeze('2026-10-09 12:00:00');

        $this->quinos = FakeQuinos::install();
        $this->client = Client::factory()->create([
            'quinos_email' => $this->quinos->email,
            'quinos_password' => $this->quinos->password,
            'timezone' => 'Asia/Makassar',
        ]);

        $this->quinos->lists['/departments'] = [['id' => 1415, 'name' => 'FOOD', 'categories' => []]];
        $this->quinos->lists['/items'] = [['id' => 1, 'code' => 'A', 'name' => 'A', 'category_id' => 1, 'category' => ['department_id' => 1415]]];
        $this->quinos->lists['/contacts'] = [['id' => 1437, 'name' => 'PASAR', 'supplier' => true]];
        $this->quinos->lists['/purchaseOrders'] = [];
        $this->quinos->lists['/goodsReceipts'] = [Doc::receipt(30, '2026-10-08'), Doc::receipt(31, '2026-10-09')];
        $this->quinos->lists['/purchaseInvoices'] = [];
    }

    /** Carbon and CarbonImmutable keep separate test clocks: set both. */
    private function freeze(string $localTime): void
    {
        Carbon::setTestNow(Carbon::parse($localTime, 'Asia/Makassar'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($localTime, 'Asia/Makassar'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Create the pending rows the way a real request would, without running the job. */
    private function pending(array $dates): void
    {
        Queue::fake();
        (new SyncDispatcher)->days($this->client, $dates);
        Queue::swap(app('queue')); // jobs are not needed, only the rows
    }

    private function day(string $date): SyncDay
    {
        return SyncDay::where('client_id', $this->client->id)->where('package', 'purchase')->whereDate('date', $date)->firstOrFail();
    }

    public function test_days_end_up_done_with_their_document_counts(): void
    {
        $this->pending(['2026-10-08', '2026-10-09']);

        (new SyncRunner)->run($this->client, ['2026-10-08', '2026-10-09']);

        $this->assertSame('done', $this->day('2026-10-08')->status);
        $this->assertSame(1, $this->day('2026-10-08')->rows);
        $this->assertSame(1, $this->day('2026-10-09')->rows);
        $this->assertNotNull($this->day('2026-10-08')->synced_at);
        $this->assertNull($this->day('2026-10-08')->error);
    }

    public function test_a_day_is_partial_until_it_is_over_in_the_clients_timezone(): void
    {
        $this->pending(['2026-10-08', '2026-10-09']);

        (new SyncRunner)->run($this->client, ['2026-10-08', '2026-10-09']);

        $this->assertFalse($this->day('2026-10-08')->partial, 'yesterday is complete');
        $this->assertTrue($this->day('2026-10-09')->partial, 'today may still get documents');

        // The next day, syncing 9 October again completes it.
        $this->freeze('2026-10-10 03:00:00');
        (new SyncRunner)->run($this->client, ['2026-10-09']);

        $this->assertFalse($this->day('2026-10-09')->partial);
    }

    public function test_master_data_is_refreshed_only_when_stale_or_forced(): void
    {
        $this->pending(['2026-10-08']);
        (new SyncRunner)->run($this->client, ['2026-10-08']);
        $this->assertCount(1, $this->quinos->requests('GET', '/departments'), 'first run: never synced, so refreshed');

        (new SyncRunner)->run($this->client->fresh(), ['2026-10-08']);
        $this->assertCount(1, $this->quinos->requests('GET', '/departments'), 'fresh: skipped');

        (new SyncRunner)->run($this->client->fresh(), ['2026-10-08'], force: true);
        $this->assertCount(2, $this->quinos->requests('GET', '/departments'), 'forced: refreshed');
    }

    public function test_a_login_problem_fails_the_days_with_a_readable_message(): void
    {
        $this->quinos->sessionNeverValid = true;
        $this->pending(['2026-10-08', '2026-10-09']);

        (new SyncRunner)->run($this->client, ['2026-10-08', '2026-10-09']);

        $day = $this->day('2026-10-08');
        $this->assertSame('failed', $day->status);
        $this->assertStringContainsString('captcha', $day->error);
        $this->assertStringNotContainsString($this->quinos->password, $day->error);
    }

    public function test_a_failing_package_marks_its_days_failed_and_keeps_the_message_safe(): void
    {
        // Quinos lists an order but has no page for it.
        $this->quinos->lists['/purchaseOrders'] = [Doc::order(3, '2026-10-09')];
        $this->pending(['2026-10-09']);

        (new SyncRunner)->run($this->client, ['2026-10-09']);

        $day = $this->day('2026-10-09');
        $this->assertSame('failed', $day->status);
        $this->assertStringContainsString('HTTP 404', $day->error);
    }

    public function test_a_failed_day_can_be_run_again_and_clears_its_error(): void
    {
        $this->quinos->lists['/purchaseOrders'] = [Doc::order(3, '2026-10-09')];
        $this->pending(['2026-10-09']);
        (new SyncRunner)->run($this->client, ['2026-10-09']);
        $this->assertSame('failed', $this->day('2026-10-09')->status);

        $this->quinos->details['/purchaseOrders/3'] = Doc::orderDetail(Doc::order(3, '2026-10-09'));
        (new SyncRunner)->run($this->client->fresh(), ['2026-10-09']);

        $day = $this->day('2026-10-09');
        $this->assertSame('done', $day->status);
        $this->assertNull($day->error);
    }

    public function test_no_days_only_refreshes_master_data(): void
    {
        (new SyncRunner)->run($this->client, []);

        $this->assertCount(1, $this->quinos->requests('GET', '/departments'));
        $this->assertCount(0, $this->quinos->requests('GET', '/goodsReceipts'));
        $this->assertSame(0, SyncDay::count());
    }
}
