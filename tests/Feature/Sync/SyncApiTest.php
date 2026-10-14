<?php

namespace Tests\Feature\Sync;

use App\Jobs\SyncClientDays;
use App\Models\Client;
use App\Models\SyncDay;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeQuinos;
use Tests\Support\QuinosDocs as Doc;
use Tests\TestCase;

class SyncApiTest extends TestCase
{
    use RefreshDatabase;

    private Client $zali;

    private Client $other;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freeze('2026-10-09 12:00:00');

        $this->zali = Client::factory()->create(['timezone' => 'Asia/Makassar']);
        $this->other = Client::factory()->create(['timezone' => 'Asia/Makassar']);
        $this->user = User::factory()->forClient($this->zali)->create();
    }

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

    // -------------------------------------------------------------- calendar

    public function test_the_calendar_shows_the_state_of_each_day(): void
    {
        SyncDay::create(['client_id' => $this->zali->id, 'package' => 'purchase', 'date' => '2026-10-08', 'status' => 'done', 'synced_at' => now(), 'rows' => 7]);
        SyncDay::create(['client_id' => $this->zali->id, 'package' => 'purchase', 'date' => '2026-10-09', 'status' => 'failed', 'error' => 'Quinos rejected the login']);
        SyncDay::create(['client_id' => $this->zali->id, 'package' => 'purchase', 'date' => '2026-09-30', 'status' => 'done']);

        $response = $this->withHeader('X-Client-Id', (string) $this->zali->id)->getJson('/api/sync/calendar?month=2026-10')->assertOk();

        $response->assertJsonPath('today', '2026-10-09')
            ->assertJsonPath('packages', ['purchase', 'sales'])
            ->assertJsonPath('days.2026-10-08.purchase.status', 'done')
            ->assertJsonPath('days.2026-10-08.purchase.rows', 7)
            ->assertJsonPath('days.2026-10-09.purchase.error', 'Quinos rejected the login')
            ->assertJsonMissingPath('days.2026-09-30');
    }

    public function test_the_calendar_never_shows_another_clients_days(): void
    {
        SyncDay::create(['client_id' => $this->other->id, 'package' => 'purchase', 'date' => '2026-10-08', 'status' => 'done']);

        $this->withHeader('X-Client-Id', (string) $this->zali->id)
            ->getJson('/api/sync/calendar?month=2026-10')
            ->assertOk()
            ->assertJsonMissingPath('days.2026-10-08');
    }

    public function test_the_calendar_needs_a_valid_month_and_a_chosen_client(): void
    {
        $this->getJson('/api/sync/calendar?month=2026-10')->assertUnprocessable();
        $this->withHeader('X-Client-Id', (string) $this->zali->id)->getJson('/api/sync/calendar?month=nope')->assertUnprocessable();
    }

    // ------------------------------------------------------------ queue days

    public function test_choosing_days_queues_one_job_and_marks_them_pending(): void
    {
        Queue::fake();

        $this->withHeader('X-Client-Id', (string) $this->zali->id)->postJson('/api/sync/days', ['dates' => ['2026-10-09', '2026-10-08']])
            ->assertStatus(202)
            ->assertJsonPath('queued', ['2026-10-08', '2026-10-09'])
            ->assertJsonPath('skipped', []);

        Queue::assertPushed(SyncClientDays::class, 1);
        Queue::assertPushed(SyncClientDays::class, fn ($job) => $job->clientId === $this->zali->id
            && $job->dates === ['2026-10-08', '2026-10-09'] && $job->force === false);

        $this->assertSame(4, SyncDay::where('client_id', $this->zali->id)->where('status', 'pending')->count()); // 2 days x 2 reports
    }

    public function test_days_that_are_already_waiting_are_not_queued_twice(): void
    {
        Queue::fake();

        $this->withHeader('X-Client-Id', (string) $this->zali->id)->postJson('/api/sync/days', ['dates' => ['2026-10-08']])->assertStatus(202);
        $this->withHeader('X-Client-Id', (string) $this->zali->id)->postJson('/api/sync/days', ['dates' => ['2026-10-08', '2026-10-07']])
            ->assertStatus(202)
            ->assertJsonPath('queued', ['2026-10-07'])
            ->assertJsonPath('skipped', ['2026-10-08']);

        Queue::assertPushed(SyncClientDays::class, 2);
    }

    public function test_resync_is_passed_on_as_force(): void
    {
        Queue::fake();

        $this->withHeader('X-Client-Id', (string) $this->zali->id)->postJson('/api/sync/days', ['dates' => ['2026-10-08'], 'force' => true])->assertStatus(202);

        Queue::assertPushed(SyncClientDays::class, fn ($job) => $job->force === true);
    }

    public function test_it_rejects_future_days_bad_dates_and_too_many_days(): void
    {
        Queue::fake();
        $post = fn (array $dates) => $this->withHeader('X-Client-Id', (string) $this->zali->id)->postJson('/api/sync/days', ['dates' => $dates]);

        $post(['2026-10-10'])->assertUnprocessable();                      // tomorrow
        $post(['yesterday'])->assertUnprocessable();
        $post([])->assertUnprocessable();
        $post(['2026-10-08', '2026-10-08'])->assertUnprocessable();         // duplicates

        $many = collect(range(0, 62))->map(fn ($i) => CarbonImmutable::parse('2026-10-09')->subDays($i)->toDateString())->all();
        $post($many)->assertUnprocessable();                                // 63 days

        Queue::assertNothingPushed();
    }

    public function test_today_in_the_clients_timezone_counts_even_when_utc_is_still_yesterday(): void
    {
        Queue::fake();
        $this->freeze('2026-10-09 05:00:00'); // 21:00 on 8 Oct in UTC, already 9 Oct in Bali

        $this->withHeader('X-Client-Id', (string) $this->zali->id)->postJson('/api/sync/days', ['dates' => ['2026-10-09']])->assertStatus(202);
    }

    public function test_a_client_without_a_quinos_login_cannot_sync(): void
    {
        Queue::fake();
        $this->zali->update(['quinos_email' => null, 'quinos_password' => null]);

        $this->withHeader('X-Client-Id', (string) $this->zali->id)->postJson('/api/sync/days', ['dates' => ['2026-10-08']])
            ->assertUnprocessable()
            ->assertJsonPath('message', "Save this client's Quinos email and password first.");
        $this->withHeader('X-Client-Id', (string) $this->zali->id)->postJson('/api/sync/master')->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    public function test_the_master_data_can_be_refreshed_on_its_own(): void
    {
        Queue::fake();

        $this->withHeader('X-Client-Id', (string) $this->zali->id)->postJson('/api/sync/master')->assertStatus(202);

        Queue::assertPushed(SyncClientDays::class, fn ($job) => $job->dates === [] && $job->force === true);
    }

    // ------------------------------------------------------------ end to end

    public function test_from_click_to_green_day(): void
    {
        // Tests run the queue inline (QUEUE_CONNECTION=sync), like a worker would.
        \Illuminate\Support\Facades\Http::fake(['*/api/runner/v1/*' => \Illuminate\Support\Facades\Http::response(['counts' => []])]);
        $quinos = FakeQuinos::install();
        \App\Models\LocalSetting::create(['hosted_api_url' => 'https://host.example', 'runner_token' => str_repeat('s', 64)]);
        $this->zali->update(['quinos_email' => $quinos->email, 'quinos_password' => $quinos->password]);

        $quinos->lists['/departments'] = [['id' => 1415, 'name' => 'FOOD', 'categories' => []]];
        $quinos->lists['/items'] = [['id' => 1, 'code' => 'A', 'name' => 'A', 'category_id' => 1, 'category' => ['department_id' => 1415]]];
        $quinos->lists['/contacts'] = [['id' => 1437, 'name' => 'PASAR', 'supplier' => true]];
        $quinos->lists['/purchaseOrders'] = [Doc::order(3, '2026-10-08')];
        $quinos->details['/purchaseOrders/3'] = Doc::orderDetail(Doc::order(3, '2026-10-08'));
        $quinos->lists['/goodsReceipts'] = [Doc::receipt(30, '2026-10-08', 3, 300)];
        $quinos->lists['/purchaseInvoices'] = [Doc::invoice(300, '2026-10-08', [30])];

        $this->withHeader('X-Client-Id', (string) $this->zali->id)->postJson('/api/sync/days', ['dates' => ['2026-10-08']])->assertStatus(202);

        $this->withHeader('X-Client-Id', (string) $this->zali->id)->getJson('/api/sync/calendar?month=2026-10')
            ->assertJsonPath('days.2026-10-08.purchase.status', 'done')
            ->assertJsonPath('days.2026-10-08.purchase.rows', 3)
            ->assertJsonPath('days.2026-10-08.purchase.partial', false)
            ->assertJsonPath('days.2026-10-08.purchase.error', null);

        $this->assertNotNull($this->zali->fresh()->master_synced_at);
    }

    public function test_the_job_fails_days_of_a_client_that_was_disabled_meanwhile(): void
    {
        Queue::fake();
        $this->withHeader('X-Client-Id', (string) $this->zali->id)->postJson('/api/sync/days', ['dates' => ['2026-10-08']])->assertStatus(202);

        $this->zali->update(['active' => false]);
        (new SyncClientDays($this->zali->id, ['2026-10-08']))->handle(app(\App\Sync\SyncRunner::class));

        $day = SyncDay::where('client_id', $this->zali->id)->firstOrFail();
        $this->assertSame('failed', $day->status);
        $this->assertStringContainsString('disabled', $day->error);
    }
}
