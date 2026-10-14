<?php

namespace App\Runner;

use App\Models\Client;
use App\Models\SyncDay;

/**
 * Marks the days the hosted app already holds as synced here too, so the calendar and dashboard show them.
 * Only status is pulled, not documents. Days this machine has done, queued or is running are left alone;
 * days that failed here take the hosted result.
 */
class HostedStatusPuller
{
    public function __construct(private RunnerApi $api) {}

    /** @return array{pulled: int, on_hosted: int} */
    public function pull(Client $client): array
    {
        $days = $this->api->syncDays($client->id);
        $pulled = 0;

        foreach ($days as $day) {
            if (! in_array($day['package'], config('sync.packages'), true)) {
                continue;
            }

            $key = ['client_id' => $client->id, 'package' => $day['package'], 'date' => $day['date']];
            $local = SyncDay::where($key)->first();

            if ($local && in_array($local->status, [SyncDay::DONE, SyncDay::PENDING, SyncDay::RUNNING], true)) {
                continue;
            }

            SyncDay::updateOrCreate($key, ['status' => SyncDay::DONE, 'rows' => (int) $day['rows'], 'partial' => (bool) $day['partial'], 'synced_at' => $day['synced_at'], 'error' => null]);
            $pulled++;
        }

        return ['pulled' => $pulled, 'on_hosted' => count($days)];
    }
}
