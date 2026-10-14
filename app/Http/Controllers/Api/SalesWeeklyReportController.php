<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ChecksSyncedDays;
use App\Http\Controllers\Concerns\UsesCurrentClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Department;
use App\Models\Item;
use App\Models\SalesTransaction;
use App\Models\SyncDay;
use Carbon\CarbonImmutable;
use App\Reports\SalesWeeklyWorkbook;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Weekly summary sales report (see docs/reports-spec.md, report 2): per day one row per
 * shift plus a TOTAL row, then a block with the totals of the whole range.
 */
class SalesWeeklyReportController extends Controller
{
    use ChecksSyncedDays, UsesCurrentClient;

    /** Sales types with a column in the report. Others (e.g. EXTRA) are left out and counted in a warning. */
    public const TYPES = ['DINE IN', 'GOJEK', 'GRAB', 'TAKE AWAY', 'EVENT/CATERING'];

    /** Department name in Quinos => report column. Other departments (e.g. INVENTORY) are not sales. */
    private const DEPARTMENT_COLUMN = ['FOOD' => 'food', 'BEVERAGE' => 'beverage', 'OTHER' => 'others'];

    public function __invoke(Request $request): array
    {
        return $this->report($this->currentClient(), $this->range($request));
    }

    /** The same report as an Excel file laid out like the printed one. */
    public function export(Request $request): StreamedResponse
    {
        $client = $this->currentClient();
        $report = $this->report($client, $this->range($request));

        abort_if($report['missing'] !== [], 422, 'Some days in this range are not synced yet.');

        $spreadsheet = SalesWeeklyWorkbook::make($client->name, $report, CarbonImmutable::now($client->timezone));
        $name = 'Weekly-Summary-Sales_'.Str::slug($client->name).'_'.$report['from'].'_'.$report['to'].'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @return array{from: string, to: string} */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        abort_if(CarbonImmutable::parse($data['from'])->diffInDays($data['to']) > 62, 422, 'Pick at most 63 days.');

        return $data;
    }

    private function report(Client $client, array $data): array
    {
        $daysSync = $this->dayStates($client, 'sales', $data['from'], $data['to']);
        $missing = $this->missingDays($daysSync);

        if ($missing !== []) {
            return ['from' => $data['from'], 'to' => $data['to'], 'days_sync' => $daysSync, 'missing' => $missing, 'days' => [], 'total' => null, 'warnings' => []];
        }

        $shifts = $client->shifts()->orderBy('position')->get();
        $departmentOf = $this->departmentByItemCode();

        $transactions = SalesTransaction::with('lines')
            ->whereBetween('date', [$data['from'], $data['to']])
            ->get()
            ->groupBy(fn ($t) => $t->date->toDateString());

        // When each day was last pulled from Quinos, in the client's time (the printed report shows it next to the date).
        $pulledAt = SyncDay::where('package', 'sales')
            ->whereBetween('date', [$data['from'], $data['to']])
            ->get()
            ->mapWithKeys(fn (SyncDay $d) => [$d->date->toDateString() => $d->synced_at?->setTimezone($client->timezone)->format('Y-m-d H:i:s')]);

        $warnings = ['unassigned' => 0, 'excluded_types' => [], 'unknown_codes' => []];
        $days = [];
        $overall = [];

        for ($day = CarbonImmutable::parse($data['from']); $day->toDateString() <= $data['to']; $day = $day->addDay()) {
            $date = $day->toDateString();
            $cells = [];

            foreach ($transactions[$date] ?? [] as $t) {
                if (! in_array($t->sales_type, self::TYPES, true)) {
                    $warnings['excluded_types'][$t->sales_type ?? '(none)'] = ($warnings['excluded_types'][$t->sales_type ?? '(none)'] ?? 0) + 1;

                    continue;
                }

                $label = $this->shiftLabel($shifts, $client->shift_assign_by === 'close_time' ? $t->close_time : $t->open_time);

                if ($label === null) {
                    $warnings['unassigned']++;

                    continue;
                }

                $this->add($cells[$label], $t, $departmentOf, $warnings);
                $this->add($overall[$label], $t, $departmentOf, $warnings);
            }

            $days[] = ['date' => $date, 'synced_at' => $pulledAt[$date] ?? null, 'rows' => $this->rows($shifts, $cells)];
        }

        return [
            'from' => $data['from'],
            'to' => $data['to'],
            'days_sync' => $daysSync,
            'missing' => [],
            'types' => self::TYPES,
            'days' => $days,
            'total' => ['rows' => $this->rows($shifts, $overall)],
            'partial_days' => SyncDay::where('package', 'sales')->where('partial', true)
                ->whereBetween('date', [$data['from'], $data['to']])->pluck('date')->map->toDateString()->all(),
            'warnings' => [
                'unassigned' => $warnings['unassigned'],
                'excluded_types' => $warnings['excluded_types'],
                'unknown_codes' => array_slice(array_keys($warnings['unknown_codes']), 0, 20),
            ],
        ];
    }

    /** @return array<string, string> item code => FOOD|BEVERAGE|OTHER (lower-case column name) */
    private function departmentByItemCode(): array
    {
        $columns = Department::pluck('name', 'quinos_id')
            ->map(fn ($name) => self::DEPARTMENT_COLUMN[mb_strtoupper(trim($name))] ?? null);

        return Item::whereNotNull('code')->get(['code', 'department_quinos_id'])
            ->mapWithKeys(fn ($item) => [$item->code => $columns[$item->department_quinos_id] ?? null])
            ->all();
    }

    private function shiftLabel($shifts, ?string $time): ?string
    {
        if ($time === null) {
            return null;
        }

        $hm = substr($time, 11, 5);

        foreach ($shifts as $shift) {
            if ($hm >= $shift->starts_at && $hm < $shift->ends_at) {
                return $shift->label;
            }
        }

        return null;
    }

    /** Adds one transaction to a shift's running sums. */
    private function add(?array &$cell, SalesTransaction $t, array $departmentOf, array &$warnings): void
    {
        $cell ??= [
            'cover' => array_fill_keys(self::TYPES, 0),
            'revenue' => array_fill_keys(self::TYPES, 0.0),
            'gross' => 0.0,
            'food' => 0.0, 'beverage' => 0.0, 'others' => 0.0,
        ];

        $cell['cover'][$t->sales_type] += $t->pax;
        $cell['revenue'][$t->sales_type] += (float) $t->subtotal - (float) $t->discount;
        $cell['gross'] += (float) $t->subtotal;

        $amount = ['food' => 0.0, 'beverage' => 0.0, 'others' => 0.0];

        foreach ($t->lines->where('type', 'ITEM') as $line) {
            // Modifiers and free items have no price; voided lines are not sales.
            if ((float) $line->unit_price <= 0 || (float) $line->quantity <= 0) {
                continue;
            }

            $column = $departmentOf[$line->code] ?? null;

            if ($column === null) {
                if (! array_key_exists($line->code, $departmentOf)) {
                    $warnings['unknown_codes'][$line->code] = true;
                }

                continue;
            }

            $amount[$column] += (float) $line->quantity * (float) $line->unit_price;
        }

        // The printed report shows Food and Beverage net of discounts. A discount is named after
        // what it applies to ("15% FOOD", "20% F&B"); one that names both is split by value.
        foreach ($t->lines->where('type', 'DISCOUNT') as $discount) {
            $name = mb_strtoupper($discount->name.' '.$discount->code);
            $food = str_contains($name, 'FOOD');
            $beverage = str_contains($name, 'BEV');

            $share = match (true) {
                $food && ! $beverage => ['food' => 1.0, 'beverage' => 0.0],
                $beverage && ! $food => ['food' => 0.0, 'beverage' => 1.0],
                ($amount['food'] + $amount['beverage']) > 0 => [
                    'food' => $amount['food'] / ($amount['food'] + $amount['beverage']),
                    'beverage' => $amount['beverage'] / ($amount['food'] + $amount['beverage']),
                ],
                default => ['food' => 0.0, 'beverage' => 0.0],
            };

            $amount['food'] -= (float) $discount->amount * $share['food'];
            $amount['beverage'] -= (float) $discount->amount * $share['beverage'];
        }

        foreach ($amount as $column => $value) {
            $cell[$column] += $value;
        }
    }

    /** Shift rows (empty shifts left out) followed by a TOTAL row. */
    private function rows($shifts, array $cells): array
    {
        $rows = [];

        foreach ($shifts as $shift) {
            if (isset($cells[$shift->label])) {
                $rows[] = $this->row($shift->label, $cells[$shift->label]);
            }
        }

        if ($rows === []) {
            return [];
        }

        $total = $this->row('TOTAL', [
            'cover' => $this->sumBy($cells, 'cover'),
            'revenue' => $this->sumBy($cells, 'revenue'),
            'gross' => array_sum(array_column($cells, 'gross')),
            'food' => array_sum(array_column($cells, 'food')),
            'beverage' => array_sum(array_column($cells, 'beverage')),
            'others' => array_sum(array_column($cells, 'others')),
        ]);

        // As in the printed report: the TOTAL row averages the shift averages.
        $total['average_check'] = round(array_sum(array_column($rows, 'average_check')) / count($rows), 2);

        return [...$rows, $total];
    }

    private function row(string $label, array $cell): array
    {
        $covers = array_sum($cell['cover']);

        return [
            'label' => $label,
            'cover' => $cell['cover'] + ['total' => $covers],
            'revenue' => array_map(fn ($v) => round($v, 2), $cell['revenue']) + ['total' => round($cell['gross'], 2)],
            'food' => round($cell['food'], 2),
            'beverage' => round($cell['beverage'], 2),
            'fnb' => round($cell['food'] + $cell['beverage'], 2),
            'others' => round($cell['others'], 2),
            'average_check' => $covers > 0 ? round($cell['gross'] / $covers, 2) : 0.0,
        ];
    }

    private function sumBy(array $cells, string $key): array
    {
        return collect(self::TYPES)
            ->mapWithKeys(fn ($type) => [$type => array_sum(array_column(array_column($cells, $key), $type))])
            ->all();
    }
}
