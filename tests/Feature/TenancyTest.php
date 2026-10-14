<?php

namespace Tests\Feature;

use App\Actions\CreateDefaultShifts;
use App\Models\Client;
use App\Models\ClientShift;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private Client $zali;

    private Client $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zali = Client::factory()->create(['name' => 'Zali']);
        $this->other = Client::factory()->create(['name' => 'Other']);
        (new CreateDefaultShifts)($this->zali);
        $this->other->shifts()->create(['label' => 'All day', 'starts_at' => '00:00', 'ends_at' => '24:00', 'position' => 0]);
    }

    public function test_client_user_only_sees_their_own_shifts(): void
    {
        $user = User::factory()->forClient($this->zali)->create();

        $this->actingAs($user)->getJson('/api/settings/shifts')
            ->assertOk()
            ->assertJsonCount(3, 'shifts');
    }

    public function test_client_user_cannot_switch_client_with_the_header(): void
    {
        $user = User::factory()->forClient($this->zali)->create();

        $this->actingAs($user)
            ->withHeader('X-Client-Id', (string) $this->other->id)
            ->getJson('/api/settings/shifts')
            ->assertOk()
            ->assertJsonCount(3, 'shifts')
            ->assertJsonMissing(['label' => 'All day']);
    }

    public function test_client_user_cannot_edit_another_clients_shifts(): void
    {
        $user = User::factory()->forClient($this->zali)->create();

        $this->actingAs($user)
            ->withHeader('X-Client-Id', (string) $this->other->id)
            ->putJson('/api/settings/shifts', [
                'shift_assign_by' => 'open_time',
                'shifts' => [['label' => 'Hacked', 'starts_at' => '00:00', 'ends_at' => '24:00']],
            ])->assertOk();

        $this->assertSame(['All day'], $this->other->shifts()->pluck('label')->all());
        $this->assertSame(['Hacked'], $this->zali->shifts()->pluck('label')->all());
    }

    public function test_admin_needs_to_pick_a_client(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/settings/shifts')->assertUnprocessable();

        $this->actingAs($admin)->withHeader('X-Client-Id', (string) $this->other->id)
            ->getJson('/api/settings/shifts')
            ->assertOk()
            ->assertJsonPath('shifts.0.label', 'All day');
    }

    public function test_unknown_client_header_falls_back_to_no_client(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withHeader('X-Client-Id', '99999')
            ->getJson('/api/settings/shifts')
            ->assertUnprocessable();
    }

    public function test_model_scope_fails_closed_when_no_client_is_selected(): void
    {
        $current = app(CurrentClient::class);

        // Not bound (console / queue): unscoped, jobs must filter themselves.
        $this->assertSame(4, ClientShift::count());

        $current->bind(null);
        $this->assertSame(0, ClientShift::count());

        $current->bind($this->other->id);
        $this->assertSame(1, ClientShift::count());

        $current->unbind();
    }

    public function test_run_as_restores_the_previous_state(): void
    {
        $current = app(CurrentClient::class);

        $count = $current->runAs($this->zali, fn () => ClientShift::count());

        $this->assertSame(3, $count);
        $this->assertFalse($current->isBound());
    }

    public function test_new_tenant_rows_get_the_current_client(): void
    {
        $current = app(CurrentClient::class);

        $shift = $current->runAs($this->other, fn () => ClientShift::create([
            'label' => 'Late', 'starts_at' => '22:00', 'ends_at' => '24:00', 'position' => 1,
        ]));

        $this->assertSame($this->other->id, $shift->client_id);
    }

    public function test_shift_validation_rejects_a_reversed_range(): void
    {
        $user = User::factory()->forClient($this->zali)->create();

        $this->actingAs($user)->putJson('/api/settings/shifts', [
            'shift_assign_by' => 'open_time',
            'shifts' => [['label' => 'Bad', 'starts_at' => '12:00', 'ends_at' => '10:00']],
        ])->assertUnprocessable()->assertJsonValidationErrors('shifts.0.ends_at');
    }
}
