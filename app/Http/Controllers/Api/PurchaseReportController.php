<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ChecksSyncedDays;
use App\Http\Controllers\Concerns\UsesCurrentClient;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;

/**
 * Purchase detail report, one row per invoice per line item (see docs/reports-spec.md).
 * Purchase orders without an invoice yet give their own lines, without invoice amounts.
 */
class PurchaseReportController extends Controller
{
    use ChecksSyncedDays, UsesCurrentClient;

    public function __invoke(Request $request): array
    {
        $client = $this->currentClient();

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        // The report only reads what is already in the database, so every day must have been synced.
        $daysSync = $this->dayStates($client, 'purchase', $data['from'], $data['to']);
        $missing = $this->missingDays($daysSync);

        if ($missing !== []) {
            return ['from' => $data['from'], 'to' => $data['to'], 'days_sync' => $daysSync, 'missing' => $missing, 'rows' => []];
        }

        $orders = PurchaseOrder::with('lines')
            ->whereBetween('date', [$data['from'], $data['to']])
            ->orderBy('date')->orderBy('no')
            ->get();

        $receipts = GoodsReceipt::with('lines')
            ->whereIn('purchase_order_quinos_id', $orders->pluck('quinos_id'))
            ->get()
            ->groupBy('purchase_order_quinos_id');

        $invoices = PurchaseInvoice::whereIn('quinos_id', $receipts->flatten()->pluck('purchase_invoice_quinos_id')->filter()->unique())
            ->get()
            ->keyBy('quinos_id');

        $rows = [];

        foreach ($orders as $order) {
            $byInvoice = ($receipts[$order->quinos_id] ?? collect())
                ->filter(fn ($gr) => $gr->purchase_invoice_quinos_id !== null && isset($invoices[$gr->purchase_invoice_quinos_id]))
                ->groupBy('purchase_invoice_quinos_id');

            if ($byInvoice->isEmpty()) {
                foreach ($order->lines as $line) {
                    $rows[] = $this->row($order, null, $line);
                }

                continue;
            }

            // Lines, quantities and the Total come from the purchase order (what was ordered);
            // only the amount due comes from the invoice. An invoice lists the order lines
            // that were received on its goods receipts.
            foreach ($byInvoice as $invoiceId => $grs) {
                $received = $grs->flatMap->lines->pluck('item_quinos_id')->filter()->unique();
                $lines = $order->lines->filter(fn ($line) => $received->contains($line->item_quinos_id));

                foreach ($lines->isEmpty() ? $order->lines : $lines as $line) {
                    $rows[] = $this->row($order, $invoices[$invoiceId], $line);
                }
            }
        }

        return ['from' => $data['from'], 'to' => $data['to'], 'days_sync' => $daysSync, 'missing' => [], 'rows' => $rows];
    }

    private function row(PurchaseOrder $order, ?PurchaseInvoice $invoice, $line): array
    {
        return [
            'contact_name' => $order->contact_name,
            'po_no' => $order->no,
            'invoice_no' => $invoice?->no,
            'invoice_date' => $order->date?->toDateString(),
            'due_date' => $order->date?->toDateString(),
            'total' => $order->total,
            'amount_due' => $invoice?->balance,
            'item' => $line->name,
            'item_code' => $line->code,
            'quantity' => (float) $line->quantity,
            'unit_amount' => (float) $line->unit_price,
            'account_code' => $line->category,
            'tax_type' => (float) $line->tax_rate == 0.0 ? 'Tax Exempt' : $line->tax_code,
            'tax_amount' => (float) $line->tax_amount,
            'currency' => 'IDR',
            'type' => 'Bill',
            'status' => $invoice === null ? 'Not invoiced' : ((float) $invoice->balance > 0 ? 'Awaiting Payment' : 'Paid'),
        ];
    }
}
