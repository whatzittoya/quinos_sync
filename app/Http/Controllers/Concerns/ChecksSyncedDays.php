<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Client;
use App\Models\SyncDay;
use Carbon\CarbonImmutable;

trait ChecksSyncedDays
{
    /**
     * Sync state of every day of the range up to today (client time), for the package.
     *
     * @return list<array{date: string, status: string|null}> status: null = never queued
     */
    protected function dayStates(Client $client, string $package, string $from, string $to): array
    {
        $end = min($to, CarbonImmutable::now($client->timezone)->toDateString());

        $states = SyncDay::where('package', $package)
            ->whereBetween('date', [$from, $end])
            ->get()
            ->mapWithKeys(fn (SyncDay $d) => [$d->date->toDateString() => $d->status]);

        $days = [];

        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $end; $day = $day->addDay()) {
            $days[] = ['date' => $day->toDateString(), 'status' => $states[$day->toDateString()] ?? null];
        }

        return $days;
    }

    /**
     * The days of a dayStates() list that have no finished sync.
     *
     * @return list<array{date: string, status: string|null}>
     */
    protected function missingDays(array $days): array
    {
        return array_values(array_filter($days, fn ($d) => $d['status'] !== SyncDay::DONE));
    }
}
