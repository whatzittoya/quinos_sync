<?php

namespace App\Sync;

use App\Exceptions\Quinos\QuinosRequestException;
use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Services\Quinos\QuinosClient;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Copies purchase orders, goods receipts and purchase invoices dated on the
 * requested days. One pass over each Quinos list serves every requested day.
 *
 *  - Lists are newest-created first, so the pass stops once it is past the
 *    lookback window (see config/sync.php). `$force` reads the whole list.
 *  - Purchase orders need a detail request for their lines; it is skipped when
 *    Quinos reports the same updated_at as last time (unless `$force`).
 *  - Documents dated on a requested day that Quinos no longer lists were
 *    deleted there, so they are deleted here too.
 */
class PurchaseSync
{
    public function __construct(private readonly QuinosClient $quinos, private readonly Client $client) {}

    /**
     * @param  list<string>  $dates  Y-m-d
     * @return array<string, int> number of documents stored per date
     */
    public function sync(array $dates, bool $force = false): array
    {
        $dates = array_values(array_unique($dates));
        sort($dates);

        if ($dates === []) {
            return [];
        }

        $wanted = array_flip($dates);
        $counts = array_fill_keys($dates, 0);
        $cutoff = $force ? null : CarbonImmutable::parse($dates[0])->subDays(config('sync.lookback_days'))->startOfDay();

        $kinds = [
            ['/purchaseOrders', PurchaseOrder::class, fn (array $row) => $this->storePurchaseOrder($row, $force)],
            ['/goodsReceipts', GoodsReceipt::class, fn (array $row) => $this->storeGoodsReceipt($row)],
            ['/purchaseInvoices', PurchaseInvoice::class, fn (array $row) => $this->storePurchaseInvoice($row)],
        ];

        foreach ($kinds as [$path, $model, $store]) {
            $seen = [];

            foreach ($this->matching($path, $wanted, $cutoff) as $row) {
                $store($row);
                $seen[] = $row['id'];
                $counts[Parse::date($row['date'])]++;
            }

            $this->prune($model, $dates, $seen, $cutoff);
        }

        return $counts;
    }

    /**
     * Rows of a list whose date is one of the wanted days.
     *
     * @param  array<string, int>  $wanted
     * @return Generator<int, array>
     */
    private function matching(string $path, array $wanted, ?CarbonImmutable $cutoff): Generator
    {
        foreach ($this->quinos->pages($path, ['search' => '']) as $page) {
            $newest = null;

            foreach ($page->rows() as $row) {
                $created = Parse::time($row['created_at'] ?? null);
                $newest = $created !== null && ($newest === null || $created->gt($newest)) ? $created : $newest;

                $date = Parse::date($row['date'] ?? null);

                if ($date !== null && isset($wanted[$date])) {
                    yield $row;
                }
            }

            // Everything further down is older than the lookback window.
            if ($cutoff !== null && $newest !== null && $newest->lt($cutoff)) {
                return;
            }
        }
    }

    private function storePurchaseOrder(array $row, bool $force): void
    {
        $updatedAt = Parse::time($row['updated_at'] ?? null);

        $existing = PurchaseOrder::where('client_id', $this->client->id)->where('quinos_id', $row['id'])->first();

        if (! $force && $existing !== null && $updatedAt !== null
            && $existing->quinos_updated_at?->equalTo($updatedAt) && $existing->lines()->exists()) {
            $existing->forceFill(['synced_at' => now()])->save();

            return;
        }

        // The list row has no lines: ask for the full document.
        $detail = $this->quinos->get('/purchaseOrders/'.$row['id'])->props['purchaseOrder'] ?? null;

        if (! is_array($detail)) {
            throw new QuinosRequestException("Quinos returned no purchase order for id {$row['id']}.");
        }

        DB::transaction(function () use ($detail) {
            $order = PurchaseOrder::updateOrCreate(
                ['client_id' => $this->client->id, 'quinos_id' => $detail['id']],
                $this->header($detail) + [
                    'pr_no' => Parse::str($detail['pr_no'] ?? null),
                    'delivery_date' => Parse::date($detail['delivery_date'] ?? null),
                ],
            );

            $order->lines()->delete();

            foreach ($detail['lines'] ?? [] as $line) {
                $order->lines()->create($this->line($line) + ['received' => Parse::num($line['received'] ?? 0)]);
            }
        });
    }

    private function storeGoodsReceipt(array $row): void
    {
        DB::transaction(function () use ($row) {
            $receipt = GoodsReceipt::updateOrCreate(
                ['client_id' => $this->client->id, 'quinos_id' => $row['id']],
                $this->header($row) + [
                    'po_no' => Parse::str($row['po_no'] ?? null),
                    'purchase_order_quinos_id' => $row['purchase_order_id'] ?? null,
                    'purchase_invoice_quinos_id' => $row['purchase_invoice_id'] ?? null,
                    'by_system' => (bool) ($row['by_system'] ?? false),
                ],
            );

            $receipt->lines()->delete();

            foreach ($row['lines'] ?? [] as $line) {
                $receipt->lines()->create($this->line($line));
            }
        });
    }

    private function storePurchaseInvoice(array $row): void
    {
        PurchaseInvoice::updateOrCreate(
            ['client_id' => $this->client->id, 'quinos_id' => $row['id']],
            $this->header($row) + [
                'direct' => (bool) ($row['direct'] ?? false),
                'receipt_ids' => array_values($row['receipts'] ?? []),
                'balance' => Parse::num($row['balance'] ?? 0),
            ],
        );
    }

    /** Columns shared by every purchase document. */
    private function header(array $doc): array
    {
        return [
            'no' => Parse::str($doc['no'] ?? '') ?? '',
            'ref_no' => Parse::str($doc['ref_no'] ?? null),
            'date' => Parse::date($doc['date']),
            'contact_quinos_id' => $doc['contact']['id'] ?? $doc['contact_id'] ?? null,
            'contact_name' => Parse::str($doc['contact']['name'] ?? null),
            'warehouse_quinos_id' => $doc['warehouse']['id'] ?? $doc['warehouse_id'] ?? null,
            'warehouse_name' => Parse::str($doc['warehouse']['name'] ?? null),
            'status' => Parse::str($doc['status'] ?? null, 50),
            'closed' => (bool) ($doc['closed'] ?? false),
            'subtotal' => Parse::num($doc['subtotal'] ?? 0),
            'tax_amount' => Parse::num($doc['tax_amount'] ?? 0),
            'total' => Parse::num($doc['total'] ?? 0),
            'quinos_created_at' => Parse::time($doc['created_at'] ?? null),
            'quinos_updated_at' => Parse::time($doc['updated_at'] ?? null),
            'synced_at' => now(),
        ];
    }

    /** Columns shared by every document line. */
    private function line(array $line): array
    {
        return [
            'client_id' => $this->client->id,
            'quinos_id' => $line['id'] ?? null,
            'item_quinos_id' => $line['item_id'] ?? null,
            'code' => Parse::str($line['code'] ?? null),
            'name' => Parse::str($line['name'] ?? '') ?? '',
            'category' => Parse::str($line['category'] ?? null),
            'uom' => Parse::str($line['uom'] ?? null, 50),
            'quantity' => Parse::num($line['quantity'] ?? 0),
            'unit_price' => Parse::num($line['unit_price'] ?? 0),
            'subtotal' => Parse::num($line['subtotal'] ?? 0),
            'tax_code' => Parse::str($line['tax_code'] ?? null, 50),
            'tax_rate' => Parse::num($line['tax_rate'] ?? 0),
            'tax_amount' => Parse::num($line['tax_amount'] ?? 0),
        ];
    }

    /**
     * Remove documents of the synced days that Quinos did not list any more.
     *
     * A limited pass only read documents created since `$cutoff`, so it may only
     * judge those: older ones were simply not looked at.
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $dates
     * @param  list<int>  $seen
     */
    private function prune(string $model, array $dates, array $seen, ?CarbonImmutable $cutoff): void
    {
        $model::where('client_id', $this->client->id)
            ->whereIn('date', $dates)
            ->whereNotIn('quinos_id', $seen)
            ->when($cutoff !== null, fn ($query) => $query->where('quinos_created_at', '>=', $cutoff))
            ->delete();
    }
}
