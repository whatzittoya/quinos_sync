<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\UsesCurrentClient;
use App\Http\Controllers\Controller;
use App\Models\SyncDay;
use App\Jobs\SyncClientDays;
use App\Models\Client;
use App\Runner\HostedStatusPuller;
use App\Support\CurrentClient;
use App\Sync\MasterSync;
use App\Sync\WorkerLauncher;
use App\Sync\SyncDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class SyncController extends Controller
{
    use UsesCurrentClient;

    /** Sync state of every day in a month, per data package. */
    public function calendar(Request $request): array
    {
        $client = $this->currentClient();
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        $start = CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01', $client->timezone)->startOfDay();

        $days = [];

        SyncDay::where('client_id', $client->id)
            ->whereBetween('date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->get()
            ->each(function (SyncDay $day) use (&$days) {
                $days[$day->date->toDateString()][$day->package] = [
                    'status' => $day->status,
                    'synced_at' => $day->synced_at?->toIso8601String(),
                    'rows' => $day->rows,
                    'partial' => $day->partial,
                    'error' => $day->error,
                ];
            });

        return [
            'month' => $data['month'],
            'today' => CarbonImmutable::now($client->timezone)->toDateString(),
            'packages' => config('sync.packages'),
            'master_synced_at' => $client->master_synced_at?->toIso8601String(),
            // Each kind of reference data with the time it was last refreshed (the sync rewrites updated_at on every row).
            'master' => app(CurrentClient::class)->runAs($client, fn () => collect(MasterSync::KINDS)->map(fn ($kind, $key) => [
                'key' => $key,
                'label' => $kind[0],
                'synced_at' => ($latest = $kind[1]::query()->max('updated_at')) ? CarbonImmutable::parse($latest)->toIso8601String() : null,
            ])->values()->all()),
            'days' => $days,
        ];
    }

    /** Queue a sync (or, with force, a full re-sync) of the chosen days, for all reports or only the chosen ones. */
    public function days(Request $request, SyncDispatcher $dispatcher): JsonResponse
    {
        $client = $this->currentClient();

        $data = $request->validate([
            'dates' => ['required', 'array', 'min:1', 'max:'.config('sync.max_days_per_request')],
            'dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
            'force' => ['sometimes', 'boolean'],
            'packages' => ['sometimes', 'array', 'min:1'],
            'packages.*' => ['string', 'distinct', Rule::in(config('sync.packages'))],
        ]);

        $this->requireQuinosLogin($client);

        $today = CarbonImmutable::now($client->timezone)->toDateString();

        abort_if(collect($data['dates'])->contains(fn ($d) => $d > $today), 422, 'Days in the future cannot be synced.');

        return response()->json(
            $dispatcher->days($client, $data['dates'], $request->boolean('force'), $data['packages'] ?? null),
            202,
        );
    }

    /**
     * Pull which days the hosted app has already synced (see HostedStatusPuller): for the chosen client,
     * or for every client when none is chosen. The pages call this on their own, so a hosted app that
     * cannot be reached is reported but is not an error.
     */
    public function pullStatus(HostedStatusPuller $puller): array
    {
        $clients = request()->hasHeader('X-Client-Id') ? [$this->currentClient()] : Client::all();
        $result = ['pulled' => 0, 'on_hosted' => 0, 'error' => null];

        try {
            foreach ($clients as $client) {
                $r = $puller->pull($client);
                $result['pulled'] += $r['pulled'];
                $result['on_hosted'] += $r['on_hosted'];
            }
        } catch (RuntimeException $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /** Refresh reference data only: all of it, or just the chosen kinds. */
    public function master(Request $request, SyncDispatcher $dispatcher): JsonResponse
    {
        $client = $this->currentClient();

        $data = $request->validate([
            'kinds' => ['sometimes', 'array', 'min:1'],
            'kinds.*' => ['string', 'distinct', 'in:'.implode(',', array_keys(MasterSync::KINDS))],
        ]);

        $this->requireQuinosLogin($client);
        $dispatcher->master($client, $data['kinds'] ?? null);

        return response()->json(['queued' => true], 202);
    }

    /**
     * Give up on days that are waiting: their queued jobs are removed and the days go back to
     * "not synced". A day that is syncing right now is left alone, unless it has not moved for
     * a long time, which means the process behind it died.
     */
    public function cancel(): JsonResponse
    {
        $client = $this->currentClient();

        $jobs = 0;

        foreach (DB::table('jobs')->whereNull('reserved_at')->get(['id', 'payload']) as $row) {
            $payload = json_decode($row->payload, true);

            if (($payload['displayName'] ?? null) !== SyncClientDays::class) {
                continue;
            }

            $job = @unserialize($payload['data']['command'] ?? '', ['allowed_classes' => [SyncClientDays::class]]);

            if ($job instanceof SyncClientDays && $job->clientId === $client->id) {
                $jobs += DB::table('jobs')->where('id', $row->id)->delete();
            }
        }

        $stuck = now()->subMinutes(20);

        $days = SyncDay::where('client_id', $client->id)
            ->where(fn ($q) => $q->where('status', SyncDay::PENDING)
                ->orWhere(fn ($q) => $q->where('status', SyncDay::RUNNING)->where('updated_at', '<', $stuck)))
            ->delete();

        $stillRunning = SyncDay::where('client_id', $client->id)->where('status', SyncDay::RUNNING)->count();

        return response()->json(['cancelled_days' => $days, 'removed_jobs' => $jobs, 'still_running' => $stillRunning]);
    }

    /** Start a background worker for whatever is waiting in the queue. */
    public function runNow(): JsonResponse
    {
        $this->currentClient();

        $running = WorkerLauncher::ensureRunning();

        return response()->json([
            'running' => $running,
            'waiting_jobs' => DB::table('jobs')->count(),
            'message' => $running ? null : 'This server cannot start a background worker. Add the cron job, or cancel and try again.',
        ]);
    }

    private function requireQuinosLogin($client): void
    {
        abort_unless($client->hasQuinosCredentials(), 422, 'Save this client\'s Quinos email and password first.');
    }
}
