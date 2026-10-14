<?php

namespace Tests\Feature\Sync;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Services\Quinos\QuinosClient;
use App\Sync\PurchaseSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeQuinos;
use Tests\Support\QuinosDocs as Doc;
use Tests\TestCase;

class PurchaseSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeQuinos $quinos;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quinos = FakeQuinos::install();
        $this->client = Client::factory()->create([
            'quinos_email' => $this->quinos->email,
            'quinos_password' => $this->quinos->password,
        ]);
    }

    private function sync(array $dates, bool $force = false): array
    {
        return (new PurchaseSync(QuinosClient::for($this->client->fresh()), $this->client))->sync($dates, $force);
    }

    /** Orders in the fake: each also gets a detail page with one line. */
    private function orders(array $orders): void
    {
        $this->quinos->lists['/purchaseOrders'] = $orders;

        foreach ($orders as $order) {
            $this->quinos->details['/purchaseOrders/'.$order['id']] = Doc::orderDetail($order);
        }
    }

    public function test_it_stores_documents_dated_on_the_requested_days_only(): void
    {
        $this->orders([Doc::order(3, '2026-10-09'), Doc::order(2, '2026-10-08'), Doc::order(1, '2026-10-05')]);
        $this->quinos->lists['/goodsReceipts'] = [Doc::receipt(30, '2026-10-09', 3, 300), Doc::receipt(20, '2026-10-05')];
        $this->quinos->lists['/purchaseInvoices'] = [Doc::invoice(300, '2026-10-09', [30]), Doc::invoice(200, '2026-10-01')];

        $counts = $this->sync(['2026-10-08', '2026-10-09']);

        $this->assertSame(['2026-10-08' => 1, '2026-10-09' => 3], $counts);
        $this->assertSame([2, 3], PurchaseOrder::orderBy('quinos_id')->pluck('quinos_id')->all());
        $this->assertSame([30], GoodsReceipt::pluck('quinos_id')->all());
        $this->assertSame([300], PurchaseInvoice::pluck('quinos_id')->all());
    }

    public function test_purchase_orders_keep_their_lines_and_links(): void
    {
        $this->orders([Doc::order(3, '2026-10-09')]);
        $this->quinos->lists['/goodsReceipts'] = [];
        $this->quinos->lists['/purchaseInvoices'] = [];

        $this->sync(['2026-10-09']);

        $order = PurchaseOrder::with('lines')->firstOrFail();
        $this->assertSame('PO.10003', $order->no);
        $this->assertSame('2026-10-09', $order->date->toDateString());
        $this->assertSame('PASAR', $order->contact_name);
        $this->assertSame('KITCHEN', $order->warehouse_name);
        $this->assertSame(36630.0, (float) $order->total);
        $this->assertCount(1, $order->lines);
        $this->assertSame('INV-170', $order->lines[0]->code);
        $this->assertSame(0.5, (float) $order->lines[0]->received);
        $this->assertSame($this->client->id, $order->lines[0]->client_id);
    }

    public function test_goods_receipts_and_invoices_are_linked_by_quinos_ids(): void
    {
        $this->quinos->lists['/purchaseOrders'] = [];
        $this->quinos->lists['/goodsReceipts'] = [Doc::receipt(30, '2026-10-09', 3, 300), Doc::receipt(31, '2026-10-09', 3, 301)];
        $this->quinos->lists['/purchaseInvoices'] = [Doc::invoice(301, '2026-10-09', [31]), Doc::invoice(300, '2026-10-09', [30])];

        $this->sync(['2026-10-09']);

        $receipt = GoodsReceipt::with('lines')->where('quinos_id', 30)->firstOrFail();
        $this->assertSame(3, (int) $receipt->purchase_order_quinos_id);
        $this->assertSame(300, (int) $receipt->purchase_invoice_quinos_id);
        $this->assertCount(1, $receipt->lines);
        $this->assertSame(0.5, (float) $receipt->lines[0]->quantity);

        $invoice = PurchaseInvoice::where('quinos_id', 300)->firstOrFail();
        $this->assertSame([30], $invoice->receipt_ids);
        $this->assertSame(18315.0, (float) $invoice->balance);
        $this->assertFalse($invoice->direct);
    }

    public function test_direct_invoices_are_marked_direct(): void
    {
        $this->quinos->lists['/purchaseOrders'] = [];
        $this->quinos->lists['/goodsReceipts'] = [];
        $this->quinos->lists['/purchaseInvoices'] = [Doc::invoice(10, '2026-10-09', [], 33300000)];

        $this->sync(['2026-10-09']);

        $this->assertTrue(PurchaseInvoice::firstOrFail()->direct);
    }

    public function test_unchanged_orders_are_not_fetched_again_but_changed_or_forced_ones_are(): void
    {
        $this->quinos->lists['/goodsReceipts'] = [];
        $this->quinos->lists['/purchaseInvoices'] = [];
        $this->orders([Doc::order(3, '2026-10-09')]);

        $this->sync(['2026-10-09']);
        $this->assertCount(1, $this->quinos->requests('GET', '/purchaseOrders/3'));

        $this->sync(['2026-10-09']);
        $this->assertCount(1, $this->quinos->requests('GET', '/purchaseOrders/3'), 'same updated_at: no new detail request');

        $this->orders([Doc::order(3, '2026-10-09', updated: '2026-10-09T12:00:00.000000Z')]);
        $this->sync(['2026-10-09']);
        $this->assertCount(2, $this->quinos->requests('GET', '/purchaseOrders/3'), 'updated_at changed: refetched');

        $this->sync(['2026-10-09'], force: true);
        $this->assertCount(3, $this->quinos->requests('GET', '/purchaseOrders/3'), 'force: always refetched');
    }

    public function test_syncing_twice_does_not_duplicate_anything(): void
    {
        $this->orders([Doc::order(3, '2026-10-09')]);
        $this->quinos->lists['/goodsReceipts'] = [Doc::receipt(30, '2026-10-09', 3, 300)];
        $this->quinos->lists['/purchaseInvoices'] = [Doc::invoice(300, '2026-10-09', [30])];

        $this->sync(['2026-10-09']);
        $this->sync(['2026-10-09'], force: true);

        $this->assertSame(1, PurchaseOrder::count());
        $this->assertSame(1, \DB::table('purchase_order_lines')->count());
        $this->assertSame(1, GoodsReceipt::count());
        $this->assertSame(1, \DB::table('goods_receipt_lines')->count());
        $this->assertSame(1, PurchaseInvoice::count());
    }

    public function test_documents_deleted_in_quinos_are_deleted_here_for_the_synced_days_only(): void
    {
        $this->quinos->lists['/goodsReceipts'] = [];
        $this->quinos->lists['/purchaseInvoices'] = [];
        $this->orders([Doc::order(3, '2026-10-09'), Doc::order(2, '2026-10-08'), Doc::order(1, '2026-10-07')]);
        $this->sync(['2026-10-07', '2026-10-08', '2026-10-09']);
        $this->assertSame(3, PurchaseOrder::count());

        // Orders 3 and 1 are deleted in Quinos; only 8 and 9 October are re-synced.
        $this->orders([Doc::order(2, '2026-10-08')]);
        $this->sync(['2026-10-08', '2026-10-09']);

        $this->assertSame([1, 2], PurchaseOrder::orderBy('quinos_id')->pluck('quinos_id')->all(), '3 gone, 1 (7 Oct) untouched');
    }

    public function test_a_limited_pass_stops_at_the_lookback_window(): void
    {
        config(['sync.lookback_days' => 10]);
        $this->quinos->lists['/goodsReceipts'] = [];
        $this->quinos->lists['/purchaseInvoices'] = [];

        // Newest first; ids 7..1 created on 7 Oct ... 1 Oct, 2 per page. The lookback starts 10 days before 15 Oct = 5 Oct.
        $orders = [];
        foreach ([7, 6, 5, 4, 3, 2, 1] as $id) {
            $orders[] = Doc::order($id, '2026-10-15', created: sprintf('2026-10-%02dT09:00:00.000000Z', $id));
        }
        $this->orders($orders);

        $this->sync(['2026-10-15']);

        // Page 1 (7,6), page 2 (5,4): newest on page 2 is 5 Oct = cutoff, not before it -> read page 3 (3,2), newest 3 Oct < 5 Oct -> stop.
        $this->assertCount(3, $this->quinos->requests('GET', '/purchaseOrders'));

        $before = count($this->quinos->requests('GET', '/purchaseOrders'));
        $this->sync(['2026-10-15'], force: true);
        $this->assertCount($before + 4, $this->quinos->requests('GET', '/purchaseOrders'), 'a forced pass reads all 4 pages');
        $this->assertSame(7, PurchaseOrder::count(), 'the forced pass also stored the orders beyond the window');
    }

    public function test_a_limited_pass_does_not_delete_what_it_never_looked_at(): void
    {
        config(['sync.lookback_days' => 10]);
        $this->quinos->lists['/goodsReceipts'] = [];
        $this->quinos->lists['/purchaseInvoices'] = [];

        // An old, back-dated order stored by an earlier full pass.
        $old = Doc::order(1, '2026-10-15', created: '2026-08-01T09:00:00.000000Z');
        $this->orders([$old]);
        $this->sync(['2026-10-15'], force: true);
        $this->assertSame(1, PurchaseOrder::count());

        // Now Quinos lists only newer orders in the window; the old one is outside what a normal pass reads.
        $this->orders([Doc::order(9, '2026-10-15', created: '2026-10-14T09:00:00.000000Z')]);
        $this->sync(['2026-10-15']);
        $this->assertSame([1, 9], PurchaseOrder::orderBy('quinos_id')->pluck('quinos_id')->all());

        // A forced pass reads everything, sees order 1 is gone, and deletes it.
        $this->sync(['2026-10-15'], force: true);
        $this->assertSame([9], PurchaseOrder::pluck('quinos_id')->all());
    }

    public function test_no_days_means_nothing_to_do(): void
    {
        $this->assertSame([], $this->sync([]));
        $this->assertCount(0, $this->quinos->log);
    }
}
