<?php

namespace Tests\Feature\Sync;

use App\Models\Client;
use App\Models\PosTransaction;
use App\Runner\LocalSyncService;
use App\Runner\Payload;
use App\Services\Quinos\QuinosClient;
use App\Sync\PosSync;
use App\Sync\PosTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeQuinos;
use Tests\TestCase;

class PosSyncTest extends TestCase
{
    use RefreshDatabase;

    /** Report rows with the real field names (values made up), one dine-in split bill and one with a void. */
    private function reports(): array
    {
        $header = fn (array $over) => $over + [
            'company_id' => 224, 'outlet_id' => 516, 'outlet_name' => 'Zali.Uluwatu', 'brand' => 'ZALI ULUWATU',
            'sales_type' => 'DINE IN', 'table_no' => 'S2', 'pax' => 2, 'date' => '2026-10-01', 'open_time' => '2026-10-01 12:00:00',
            'submit_time' => '2026-10-01 12:01:00', 'submit_pos' => 'POS1', 'open_by' => 'ANA', 'close_by' => 'DEVI',
            'customer_code' => '', 'customer_name' => '', 'notes' => '', 'shift' => 2, 'shift_name' => 'Lunch', 'foc' => 0,
            'void_transaction' => 0, 'void_reason' => '', 'discount' => 0, 'serv_chg' => 0, 'tax1' => 0, 'tax2' => 0,
            'rounding' => 0, 'other_charge' => 0, 'payments' => [],
        ];
        $item = fn (array $over) => $over + [
            'outlet_name' => 'Zali.Uluwatu', 'date' => '2026-10-01', 'sales_type' => 'DINE IN', 'department' => 'FOOD', 'category' => 'MAIN',
            'discount_code' => '', 'discount' => 0, 'serv_chg' => 0, 'tax1' => 0, 'tax2' => 0, 'staff_name' => 'ANA', 'payments' => '["CASH"]',
        ];

        return [
            'summary' => ['result' => [
                $header(['id' => 10748700, 'invoice_no' => 'INV-000093', 'close_time' => '2026-10-01 23:00:00', 'subtotal' => 100000, 'total' => 117700]),
                $header(['id' => 10748660, 'invoice_no' => 'INV-000053', 'close_time' => '2026-10-01 13:00:00', 'subtotal' => 120000, 'serv_chg' => 8400, 'tax1' => 12840, 'total' => 141240]),
            ]],
            'items' => ['result' => [
                $item(['invoice_no' => 'INV-000053', 'code' => 'F01', 'name' => 'NASI GORENG', 'quantity' => 2, 'unit_price' => 60000, 'serv_chg' => 8400, 'tax1' => 12840, 'order_time' => '2026-10-01 12:05:00']),
                $item(['invoice_no' => 'INV-000053', 'department' => 'OTHER', 'category' => 'MODIFIER', 'code' => 'MOD17', 'name' => 'EXTRA SPICY', 'quantity' => 1, 'unit_price' => 0, 'order_time' => '2026-10-01 12:05:01']),
                $item(['invoice_no' => 'INV-000093', 'code' => 'F10', 'name' => 'FISH', 'quantity' => 1, 'unit_price' => 100000, 'order_time' => '2026-10-01 22:00:00']),
                $item(['invoice_no' => 'INV-000093', 'department' => 'FOOD', 'category' => 'SIDE', 'code' => 'SLS-SIDE-09', 'name' => 'HOMEMADE PICKLES', 'quantity' => 0, 'unit_price' => 15000, 'order_time' => '2026-10-01 22:01:00']),
            ]],
            'payments' => ['rows' => [
                ['id' => 1, 'invoice_no' => 'INV-000053', 'code' => 'CASH', 'name' => 'CASH', 'amount' => 119000],
                ['id' => 2, 'invoice_no' => 'INV-000053', 'code' => 'BNI', 'name' => 'BNI', 'amount' => 22240],
                ['id' => 3, 'invoice_no' => 'INV-000093', 'code' => 'QRIS BNI', 'name' => 'QRIS BNI', 'amount' => 117700],
            ]],
            'voidItems' => ['result' => [
                ['code' => 'SLS-SIDE-09', 'name' => 'HOMEMADE PICKLES', 'category' => 'SIDE', 'quantity' => -1, 'amount' => -15000,
                    'void_reason' => 'CANCEL ORDER', 'staff_name' => 'BUDI', 'order_time' => '2026-10-01 22:38:40', 'transaction_id' => 10748700,
                    'transaction' => ['id' => 10748700, 'table_no' => 'S2', 'invoice_no' => 'INV-000093', 'sales_type' => 'DINE IN']],
            ]],
            'voidTransactions' => ['result' => [
                $header(['id' => 10750000, 'invoice_no' => 'INV-001209', 'sales_type' => 'TAKE AWAY', 'close_time' => '2026-10-01 18:20:00',
                    'void_transaction' => 1, 'void_reason' => 'CHANGE PAYMENT W GOJEK', 'subtotal' => 214000, 'total' => 253055]),
            ]],
        ];
    }

    private function build(): array
    {
        $r = $this->reports();
        $documents = (new PosTransactions)->build('2026-10-01', $r['summary']['result'], $r['items']['result'],
            $r['payments']['rows'], $r['voidItems']['result'], $r['voidTransactions']['result']);

        return collect($documents)->keyBy('invoice_no')->all();
    }

    public function test_bills_follow_the_old_api_conventions(): void
    {
        $bill = $this->build()['INV-000053'];
        $t = $bill['transaction'];
        $lines = $bill['transaction_lines'];

        $this->assertSame(Payload::POS_TRANSACTION, array_keys($t));
        $this->assertSame(['10748660', 'INV-000053', 'ZALI.ULUWATU', 'Lunch', 'ANA', '0', null, null], [
            $t['id'], $t['transaction_id'], $t['store_code'], $t['shift_name'], $t['open_staff_code'], $t['void_transaction'], $t['void_reason'], $t['extra_charge'],
        ]);
        $this->assertSame(['10748660000', '10748660001', '10748660002', '10748660003'], array_column($lines, 'id'));
        $this->assertSame(['10748660'], array_values(array_unique(array_column($lines, 'transaction_id'))));
        $this->assertSame(['0', '1', 'CASH', 'CARD'], array_column($lines, 'line_code'));
        $this->assertNull($lines[0]['amount']);
        $this->assertNull($lines[1]['modifier']);

        $cash = $lines[2];
        $this->assertSame(['CASH', '1', '119000', 'DEVI', '2026-10-01 13:00:00', '', null], [
            $cash['payment_code'], $cash['quantity'], $cash['amount'], $cash['staff_code'], $cash['order_time'], $cash['remark'], $cash['unit_price'],
        ]);
        foreach ($lines as $line) {
            $this->assertSame(Payload::POS_LINE, array_keys($line));
        }
    }

    public function test_void_report_rows_replace_the_netted_quantity_zero_rows(): void
    {
        $lines = $this->build()['INV-000093']['transaction_lines'];
        $pickles = array_values(array_filter($lines, fn ($l) => $l['item_code'] === 'SLS-SIDE-09'));

        $this->assertCount(1, $pickles);
        $this->assertSame(['1', '-1', '15000', 'CANCEL ORDER', 'FOOD'], [
            $pickles[0]['void_line'], $pickles[0]['quantity'], $pickles[0]['unit_price'], $pickles[0]['void_reason'], $pickles[0]['department_code'],
        ]);
        $this->assertSame('OTHER', end($lines)['line_code']); // QRIS is OTHER in the old API
    }

    public function test_voided_bills_are_included_without_lines(): void
    {
        $bill = $this->build()['INV-001209'];

        $this->assertSame('1', $bill['transaction']['void_transaction']);
        $this->assertSame('CHANGE PAYMENT W GOJEK', $bill['transaction']['void_reason']);
        $this->assertSame([], $bill['transaction_lines']);
    }

    public function test_every_document_passes_the_hosted_import_validation(): void
    {
        foreach ($this->build() as $document) {
            $this->assertSame($document, Payload::clean('pos_transactions', $document));
        }
    }

    public function test_sync_reads_the_five_reports_and_replaces_the_day(): void
    {
        $quinos = FakeQuinos::install();
        $client = Client::factory()->create(['quinos_email' => $quinos->email, 'quinos_password' => $quinos->password]);
        foreach ($this->reports() as $key => $props) {
            $path = ['summary' => 'transactionSummaryReport', 'items' => 'transactionItemDetailReport', 'payments' => 'paymentDetailReport',
                'voidItems' => 'voidItemReport', 'voidTransactions' => 'voidTransactionReport'][$key];
            $quinos->details['/reports/pos/'.$path] = $props;
        }
        PosTransaction::create(['client_id' => $client->id, 'quinos_id' => 1, 'date' => '2026-10-01', 'transaction' => [], 'transaction_lines' => []]);

        $counts = (new PosSync(QuinosClient::for($client), $client))->sync(['2026-10-01']);

        $this->assertSame(['2026-10-01' => 3], $counts);
        $this->assertSame(['INV-000053', 'INV-001209', 'INV-000093'], PosTransaction::orderBy('close_time')->pluck('invoice_no')->all());
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-01T00:00:00.000Z', 'outlet_id' => null],
            $quinos->requests('POST', '/reports/pos/paymentDetailReport')[0]['body']);
        $stored = PosTransaction::where('invoice_no', 'INV-000053')->first();
        $this->assertSame(Payload::POS_TRANSACTION, array_keys($stored->transaction)); // key order survives storage
    }

    public function test_push_uploads_stored_bills_as_the_pos_package_in_small_chunks(): void
    {
        config(['runner.pos_chunk_size' => 2]);
        $client = Client::factory()->create();
        \App\Models\LocalSetting::create(['hosted_api_url' => 'https://host.example', 'runner_token' => str_repeat('s', 64)]);
        foreach ($this->build() as $document) {
            PosTransaction::create(['client_id' => $client->id] + $document);
        }
        $sent = [];
        Http::fake(function (HttpRequest $request) use (&$sent) {
            $sent[] = ['url' => $request->url(), 'body' => json_decode($request->body(), true)];

            return Http::response(['counts' => ['pos_transactions' => 3]]);
        });

        $this->assertSame(3, app(LocalSyncService::class)->push($client, 'pos', ['2026-10-01']));

        $this->assertSame('pos', $sent[0]['body']['package']);
        $this->assertSame(2, $sent[0]['body']['chunk_count']);
        $chunks = array_values(array_filter($sent, fn ($r) => str_contains($r['url'], '/chunks/')));
        $documents = array_merge(...array_map(fn ($r) => $r['body']['tables']['pos_transactions'], $chunks));
        $this->assertCount(3, $documents);
        foreach ($documents as $document) {
            $this->assertSame($document, Payload::clean('pos_transactions', $document));
        }
        $bill = collect($documents)->firstWhere('invoice_no', 'INV-000053');
        $this->assertSame('', $bill['transaction']['customer_code']); // "" survives storage and upload
        $this->assertNull($bill['transaction']['customer_name']);
    }

    public function test_payment_line_codes(): void
    {
        $this->assertSame(['CASH', 'CARD', 'CARD', 'CARD', 'OTHER', 'OTHER', 'OTHER', 'OTHER'], array_map(
            [PosTransactions::class, 'paymentLineCode'],
            ['CASH', 'BNI', 'CREDIT CARD', 'DEBIT CARD', 'QR', 'QRIS BNI', 'GOJEK', 'BANK TRANSFER'],
        ));
    }
}
