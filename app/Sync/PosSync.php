<?php

namespace App\Sync;

use App\Exceptions\Quinos\QuinosRequestException;
use App\Models\Client;
use App\Models\PosTransaction;
use App\Services\Quinos\QuinosClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Builds the mobile app's transactions (see PosTransactions) from six POS reports, one day per request
 * like SalesSync. A synced day is replaced as a whole, which also removes bills deleted in Quinos.
 */
class PosSync
{
    private const REPORTS = [
        'summary' => '/reports/pos/transactionSummaryReport',
        'items' => '/reports/pos/transactionItemDetailReport',
        'payments' => '/reports/pos/paymentDetailReport',
        'voidItems' => '/reports/pos/voidItemReport',
        'voidTransactions' => '/reports/pos/voidTransactionReport',
        'focLines' => '/reports/pos/focDetailReport',
    ];

    public function __construct(private readonly QuinosClient $quinos, private readonly Client $client) {}

    /**
     * @param  list<string>  $dates  Y-m-d
     * @return array<string, int> number of bills stored per date
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
        $reports = [];
        foreach (self::REPORTS as $key => $path) {
            $page = $this->quinos->post($path, [
                'from' => $date,
                'to' => CarbonImmutable::parse($date)->format('Y-m-d\T00:00:00.000\Z'),
                'outlet_id' => null,
            ]);
            // Some reports answer in props.result, others in props.rows.
            $rows = $page->props['result'] ?? $page->props['rows'] ?? null;

            if (! is_array($rows)) {
                throw new QuinosRequestException("Quinos returned no {$key} report for {$date}.");
            }
            $reports[$key] = $rows;
        }

        $builder = new PosTransactions;
        $documents = $builder->build($date, ...$reports);

        foreach ($builder->warnings as $warning) {
            Log::warning("POS sync {$this->client->name} {$date}: {$warning}");
        }

        DB::transaction(function () use ($documents, $date) {
            PosTransaction::where('client_id', $this->client->id)->where('date', $date)->delete();

            foreach ($documents as $document) {
                PosTransaction::updateOrCreate(
                    ['client_id' => $this->client->id, 'quinos_id' => $document['quinos_id']],
                    $document,
                );
            }
        });

        return count($documents);
    }
}
