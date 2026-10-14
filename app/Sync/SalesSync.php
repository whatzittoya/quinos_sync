<?php

namespace App\Sync;

use App\Exceptions\Quinos\QuinosRequestException;
use App\Models\Client;
use App\Models\SalesTransaction;
use App\Services\Quinos\QuinosClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Copies POS transactions from the Quinos transaction detail report.
 *
 * The report has no paging, so one request is made per day (from and to are the
 * same date; `to` is inclusive of that whole day). A synced day is replaced as a
 * whole, which also removes transactions deleted in Quinos.
 */
class SalesSync
{
    private const REPORT = '/reports/pos/transactionDetailReport';

    public function __construct(private readonly QuinosClient $quinos, private readonly Client $client) {}

    /**
     * @param  list<string>  $dates  Y-m-d
     * @return array<string, int> number of transactions stored per date
     */
    public function sync(array $dates, bool $force = false): array
    {
        $counts = [];

        foreach (array_values(array_unique($dates)) as $date) {
            $counts[$date] = $this->day($date);
        }

        return $counts;
    }

    private function day(string $date): int
    {
        $page = $this->quinos->post(self::REPORT, [
            'from' => $date,
            'to' => CarbonImmutable::parse($date)->format('Y-m-d\T00:00:00.000\Z'),
            'outlet_id' => null,
        ]);

        $result = $page->props['result'] ?? null;

        if (! is_array($result)) {
            throw new QuinosRequestException("Quinos returned no sales report for {$date}.");
        }

        DB::transaction(function () use ($result, $date) {
            SalesTransaction::where('client_id', $this->client->id)->where('date', $date)->delete();

            foreach ($result as $row) {
                $transaction = SalesTransaction::updateOrCreate(
                    ['client_id' => $this->client->id, 'quinos_id' => $row['id']],
                    [
                        'date' => $date,
                        'invoice_no' => Parse::str($row['invoice_no'] ?? null),
                        'outlet_name' => Parse::str($row['outlet_name'] ?? null),
                        'sales_type' => Parse::str($row['sales_type'] ?? null, 50),
                        'pax' => (int) ($row['pax'] ?? 0),
                        'subtotal' => Parse::num($row['subtotal'] ?? 0),
                        'discount' => Parse::num($row['discount'] ?? 0),
                        'serv_chg' => Parse::num($row['serv_chg'] ?? 0),
                        'tax' => Parse::num($row['tax1'] ?? 0) + Parse::num($row['tax2'] ?? 0),
                        'total' => Parse::num($row['total'] ?? 0),
                        'open_time' => $this->localTime($row['open_time'] ?? null),
                        'close_time' => $this->localTime($row['close_time'] ?? null),
                    ],
                );

                $transaction->lines()->delete();

                $lines = collect($row['lines'] ?? [])
                    ->whereIn('type', ['ITEM', 'DISCOUNT'])
                    ->map(fn (array $line) => [
                        'client_id' => $this->client->id,
                        'type' => $line['type'],
                        'code' => Parse::str($line['code'] ?? null),
                        'name' => Parse::str($line['name'] ?? '') ?? '',
                        'quantity' => Parse::num($line['quantity'] ?? 0),
                        'unit_price' => Parse::num($line['unit_price'] ?? 0),
                        'amount' => Parse::num($line['amount'] ?? 0),
                    ])
                    ->all();

                if ($lines !== []) {
                    $transaction->lines()->insert(array_map(fn ($l) => $l + ['sales_transaction_id' => $transaction->id], $lines));
                }
            }
        });

        return count($result);
    }

    /** "2026-10-01 09:41:19" stays as is: outlet time, no conversion. */
    private function localTime(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $value) ? substr($value, 0, 19) : null;
    }
}
