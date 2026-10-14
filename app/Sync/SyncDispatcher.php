<?php

namespace App\Sync;

use App\Jobs\SyncClientDays;
use App\Models\Client;
use App\Models\SyncDay;
use Illuminate\Support\Facades\Cache;

/**
 * Starts syncs. Days that are already waiting or running are not started twice.
 *
 * From a web request on a host that can answer first (php-fpm, LiteSpeed) the sync runs right
 * after the response is sent, so no worker or cron is needed. Otherwise, or when another sync
 * of the same client is running, it goes to the queue and a worker picks it up.
 */
class SyncDispatcher
{
    /**
     * @param  list<string>  $dates  Y-m-d
     * @param  list<string>|null  $packages  reports to sync (see config sync.packages); null = all
     * @return array{queued: list<string>, skipped: list<string>}
     */
    public function days(Client $client, array $dates, bool $force = false, ?array $packages = null): array
    {
        $dates = array_values(array_unique($dates));
        sort($dates);

        $packages = $packages === null
            ? config('sync.packages')
            : array_values(array_intersect(config('sync.packages'), $packages));

        // A report that is already waiting or running on a day is not queued again.
        $busy = SyncDay::where('client_id', $client->id)
            ->whereIn('date', $dates)
            ->whereIn('package', $packages)
            ->whereIn('status', [SyncDay::PENDING, SyncDay::RUNNING])
            ->get()
            ->groupBy(fn (SyncDay $d) => $d->date->toDateString())
            ->map(fn ($rows) => $rows->pluck('package')->all());

        $queued = [];
        $skipped = [];
        $groups = []; // packages to sync => dates

        foreach ($dates as $date) {
            $todo = array_values(array_diff($packages, $busy[$date] ?? []));

            if ($todo === []) {
                $skipped[] = $date;

                continue;
            }

            $queued[] = $date;
            $groups[implode(',', $todo)][] = $date;

            foreach ($todo as $package) {
                SyncDay::updateOrCreate(
                    ['client_id' => $client->id, 'package' => $package, 'date' => $date],
                    ['status' => SyncDay::PENDING, 'error' => null],
                );
            }
        }

        foreach ($groups as $key => $groupDates) {
            $this->start(new SyncClientDays($client->id, $groupDates, $force, null, explode(',', $key)));
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Refresh only the reference data (items, departments, ...).
     *
     * @param  list<string>|null  $kinds  keys of MasterSync::KINDS, null = all
     */
    public function master(Client $client, ?array $kinds = null): void
    {
        $this->start(new SyncClientDays($client->id, [], true, $kinds));
    }

    private function start(SyncClientDays $job): void
    {
        // Best: queue the job and let a background worker pick it up (no web time limit).
        if (WorkerLauncher::canSpawn()) {
            dispatch($job);
            WorkerLauncher::ensureRunning();

            return;
        }

        if (! $this->canRunAfterResponse()) {
            dispatch($job);

            return;
        }

        app()->terminating(function () use ($job) {
            // Same lock as the job's WithoutOverlapping middleware, which inline runs would skip.
            $lock = Cache::lock('laravel-queue-overlap:'.SyncClientDays::class.':quinos-sync-'.$job->clientId, 3900);

            if (! $lock->get()) {
                dispatch($job); // another sync is running: a worker waits for it

                return;
            }

            $lock->release(); // the job takes the lock itself through its middleware

            ignore_user_abort(true);
            @set_time_limit(0);

            dispatch_sync($job);
        });
    }

    private function canRunAfterResponse(): bool
    {
        return config('sync.run_after_response')
            && ! app()->runningInConsole()
            && (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'));
    }
}
