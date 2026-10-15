<?php

namespace App\Sync;

use App\Exceptions\Quinos\QuinosException;
use App\Models\Client;
use App\Models\SyncDay;
use App\Services\Quinos\QuinosClient;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Runs the data packages for a set of days and records the outcome of each
 * (client, package, day) in sync_days, which the calendar shows.
 */
class SyncRunner
{
    /**
     * @param  list<string>  $dates  Y-m-d; empty = only refresh the master data
     * @param  list<string>|null  $masterKinds  with no dates: which reference data to refresh (null = all)
     * @param  list<string>|null  $packages  which data packages (reports) to sync; null = all
     */
    public function run(Client $client, array $dates, bool $force = false, ?array $masterKinds = null, ?array $packages = null): void
    {
        $quinos = QuinosClient::for($client);
        $packages = $packages === null
            ? config('sync.packages')
            : array_values(array_intersect(config('sync.packages'), $packages));

        $this->mark($client, $packages, $dates, ['status' => SyncDay::RUNNING, 'error' => null]);

        try {
            $master = new MasterSync($quinos, $client);

            if ($dates === [] || $force || $master->isStale()) {
                SyncProgress::set($client->id, 'Refreshing master data');
                $master->sync($dates === [] ? $masterKinds : null);
            }
        } catch (Throwable $e) {
            $this->mark($client, $packages, $dates, ['status' => SyncDay::FAILED, 'error' => $this->message($e)]);

            return;
        }

        foreach ($packages as $package) {
            if ($dates === []) {
                break;
            }

            try {
                SyncProgress::set($client->id, 'Syncing '.$package);
                $counts = $this->package($package, $quinos, $client)->sync($dates, $force);
            } catch (Throwable $e) {
                $this->mark($client, [$package], $dates, ['status' => SyncDay::FAILED, 'error' => $this->message($e)]);

                continue;
            }

            foreach ($dates as $date) {
                $this->mark($client, [$package], [$date], [
                    'status' => SyncDay::DONE,
                    'synced_at' => now(),
                    'rows' => $counts[$date] ?? 0,
                    'partial' => $this->isPartial($client, $date),
                    'error' => null,
                ]);
            }
        }
    }

    /** A day is partial until it is over in the client's timezone. */
    public function isPartial(Client $client, string $date): bool
    {
        return CarbonImmutable::now($client->timezone)->lt(CarbonImmutable::parse($date, $client->timezone)->endOfDay());
    }

    private function package(string $package, QuinosClient $quinos, Client $client): object
    {
        return match ($package) {
            'purchase' => new PurchaseSync($quinos, $client),
            'sales' => new SalesSync($quinos, $client),
            default => throw new \InvalidArgumentException("Unknown sync package {$package}"),
        };
    }

    /** Only Quinos errors have messages that are safe and useful to show. */
    private function message(Throwable $e): string
    {
        if ($e instanceof QuinosException) {
            return mb_substr($e->getMessage(), 0, 480);
        }

        report($e);

        return 'Unexpected error while syncing. See the application log.';
    }

    /**
     * @param  list<string>  $packages
     * @param  list<string>  $dates
     */
    private function mark(Client $client, array $packages, array $dates, array $values): void
    {
        if ($dates === []) {
            return;
        }

        SyncDay::where('client_id', $client->id)
            ->whereIn('package', $packages)
            ->whereIn('date', $dates)
            ->update($values + ['updated_at' => now()]);
    }
}
