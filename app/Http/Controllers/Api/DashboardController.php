<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SyncDay;
use Carbon\CarbonImmutable;

/** How current each client's data is: days behind and last sync, per report. */
class DashboardController extends Controller
{
    private const REPORTS = ['purchase' => 'Purchase', 'sales' => 'Sales', 'pos' => 'Mobile API'];

    public function __invoke(): array
    {
        $days = SyncDay::query()
            ->where('status', SyncDay::DONE)
            ->selectRaw('client_id, package, max(date) as latest, max(synced_at) as pulled')
            ->groupBy('client_id', 'package')
            ->get()
            ->groupBy('client_id');

        return ['data' => Client::orderBy('name')->get()->map(function (Client $client) use ($days) {
            $today = CarbonImmutable::now($client->timezone)->startOfDay();
            $rows = ($days[$client->id] ?? collect())->keyBy('package');

            $reports = [];

            foreach (self::REPORTS as $package => $label) {
                $row = $rows[$package] ?? null;
                $latest = $row?->latest ? CarbonImmutable::parse($row->latest, $client->timezone)->startOfDay() : null;
                // The newest synced day is never later than today, so a future-dated row counts as up to date.
                $reports[] = [
                    'key' => $package,
                    'label' => $label,
                    'latest_date' => $latest?->toDateString(),
                    'days_behind' => $latest ? max(0, (int) $latest->diffInDays($today)) : null,
                    'last_synced_at' => $row?->pulled ? CarbonImmutable::parse($row->pulled)->toIso8601String() : null,
                ];
            }

            return [
                'id' => $client->id,
                'name' => $client->name,
                'timezone' => $client->timezone,
                'scheduled' => $client->scheduled !== false,
                'master_synced_at' => $client->master_synced_at?->toIso8601String(),
                'reports' => $reports,
            ];
        })->values()];
    }
}
