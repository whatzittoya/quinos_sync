<?php

namespace App\Sync;

use App\Runner\Payload;

/**
 * Builds bills in the mobile app's format (the old quinoscloud getTransactionByDate API) from the POS reports.
 *
 * Joined on invoice_no:
 *   transactionSummaryReport     full header of every closed bill (shift, rounding, staff, ...)
 *   transactionItemDetailReport  item lines (department, category, discount, service, tax)
 *   paymentDetailReport          one row per payment, with its amount
 *   voidItemReport               voided lines (negative quantity and a reason)
 *   voidTransactionReport        voided bills (same shape as the summary)
 *   focDetailReport              free (FOC) bills: raw lines joined with their header
 *
 * The first three leave voided and FOC bills out. As in the old API, voided bills get void_transaction "1" and
 * FOC bills (compliment, wastage, staff meals) "2"; neither is revenue. Every output value is a string or null, following the old API:
 * line transaction_id is the header id, item line_code counts from "0", item amount and modifier are null,
 * payment lines have quantity "1", the cashier and the close time.
 */
class PosTransactions
{
    private const CARD_PAYMENTS = ['BCA', 'BNI', 'BRI', 'MANDIRI', 'CIMB', 'PERMATA', 'DANAMON', 'MEGA', 'BTN',
        'VISA', 'MASTER', 'AMEX', 'JCB', 'DEBIT', 'CREDIT', 'EDC', 'CARD'];

    /** @var list<string> */
    public array $warnings = [];

    /**
     * @return list<array{quinos_id: int, date: string, invoice_no: ?string, store_code: ?string, close_time: ?string, transaction: array, transaction_lines: list<array>}>
     */
    public function build(string $date, array $summary, array $items, array $payments, array $voidItems, array $voidTransactions, array $focLines = []): array
    {
        $this->warnings = [];
        $itemsBy = $this->byInvoice($items);
        $paymentsBy = $this->byInvoice($payments);
        $voidItemsBy = $this->byInvoice($voidItems);
        $documents = [];

        foreach ($summary as $h) {
            $invoice = $this->invoice($h);
            $lines = $this->itemLines($itemsBy[$invoice] ?? [], $voidItemsBy[$invoice] ?? []);

            $pays = $paymentsBy[$invoice] ?? [];
            $paid = array_sum(array_map(fn ($p) => (float) ($p['amount'] ?? 0), $pays));
            if (abs($paid - (float) ($h['total'] ?? 0)) > 1) {
                $this->warnings[] = "{$invoice}: payments {$paid} do not add up to the total {$h['total']}";
            }
            foreach ($pays as $p) {
                $lines[] = $this->paymentLine($p, $h);
            }

            $documents[$invoice] = $this->document($date, $h, $lines);
        }

        foreach ($voidTransactions as $h) {
            $invoice = $this->invoice($h);
            $documents[$invoice] ??= $this->document($date, ['void_transaction' => 1] + $h, []);
        }

        // A voided FOC bill stays voided ("1"); the rest become "2".
        foreach ($this->byInvoice($focLines) as $invoice => $rows) {
            if (isset($documents[$invoice])) {
                continue;
            }
            $h = $rows[0]['transaction'] ?? $rows[0];
            $items = array_filter($rows, fn ($r) => strtoupper((string) ($r['type'] ?? '')) !== 'PAYMENT');
            $lines = array_map(fn ($r) => array_merge($this->itemLine($r), ['void_line' => $this->s($r['void_line'] ?? null) ?? '0']), array_values($items));
            usort($lines, fn ($a, $b) => strcmp((string) $a['order_time'], (string) $b['order_time']));
            foreach ($lines as $i => &$line) {
                $line['line_code'] = (string) $i;
            }
            unset($line);
            foreach ($rows as $r) {
                if (strtoupper((string) ($r['type'] ?? '')) === 'PAYMENT') {
                    $lines[] = $this->paymentLine($r, $h);
                }
            }
            $documents[$invoice] = $this->document($date, $h + ['foc' => 1], $lines);
        }

        $documents = array_values($documents);
        usort($documents, fn ($a, $b) => strcmp((string) $a['close_time'], (string) $b['close_time']));

        return $documents;
    }

    public static function paymentLineCode(?string $name): string
    {
        $name = strtoupper(trim((string) $name));

        if ($name === 'CASH' || $name === 'TUNAI') {
            return 'CASH';
        }
        // The old API sends QR payments as OTHER, even when the QR name contains a bank.
        if (str_starts_with($name, 'QR')) {
            return 'OTHER';
        }
        foreach (self::CARD_PAYMENTS as $card) {
            if (str_contains($name, $card)) {
                return 'CARD';
            }
        }

        return 'OTHER';
    }

    private function document(string $date, array $h, array $lines): array
    {
        $transaction = $this->header($h);

        // Lines point at the header id, as in the old API. The reports expose no line ids, so they are
        // made from the header id and the position, numeric like the old ones.
        foreach ($lines as $i => &$line) {
            $line['transaction_id'] = $transaction['id'];
            $line['id'] = ctype_digit((string) $transaction['id']) ? (string) ((int) $transaction['id'] * 1000 + $i) : $transaction['id'].'-'.$i;
        }
        unset($line);

        return [
            'quinos_id' => (int) $h['id'],
            'date' => $date,
            'invoice_no' => $transaction['transaction_id'],
            'store_code' => $transaction['store_code'],
            'close_time' => $transaction['close_time'],
            'transaction' => $transaction,
            'transaction_lines' => array_values($lines),
        ];
    }

    private function header(array $h): array
    {
        $void = (int) ($h['void_transaction'] ?? 0) === 1;
        $foc = ! $void && (int) ($h['foc'] ?? 0) === 1;
        // The old API puts the name typed on the bill in `name`; Quinos reports it as customer_name, and only a
        // bill with a customer from the customer list (customer_code set) keeps customer_name.
        $typedName = $this->orNull($h['customer_name'] ?? null);

        return $this->ordered(Payload::POS_TRANSACTION, [
            'id' => $this->s($h['id'] ?? null),
            'company_id' => $this->s($h['company_id'] ?? null),
            'store_code' => $this->filled($h['outlet_name'] ?? null) ? strtoupper((string) $h['outlet_name']) : null,
            'brand' => $this->orNull($h['brand'] ?? null) ?? ($this->filled($h['outlet_name'] ?? null) ? strtoupper((string) $h['outlet_name']) : null),
            'transaction_id' => $this->s($h['invoice_no'] ?? null),
            'date' => $this->s($h['date'] ?? null),
            'sales_type' => $this->s($h['sales_type'] ?? null),
            'table_name' => $this->s($h['table_no'] ?? null),
            'pax' => $this->s($h['pax'] ?? null) ?? '0',
            'customer_code' => $this->s($h['customer_code'] ?? null) ?? '',
            'customer_name' => $this->filled($h['customer_code'] ?? null) ? $typedName : null,
            'delivery_address' => $this->orNull($h['delivery_address'] ?? null),
            'pickup_time' => $this->orNull($h['pickup_time'] ?? null),
            'driver_code' => $this->orNull($h['delivery_to'] ?? null),
            'open_staff_code' => $this->orNull($h['open_by'] ?? null),
            'open_time' => $this->s($h['open_time'] ?? null),
            'cashier_code' => $this->s($h['close_by'] ?? null),
            'close_time' => $this->s($h['close_time'] ?? null),
            'void_transaction' => $void ? '1' : ($foc ? '2' : '0'),
            'void_reason' => $void ? ($this->s($h['void_reason'] ?? null) ?? '') : null,
            'no_sales' => '0',
            'subtotal' => $this->s($h['subtotal'] ?? null) ?? '0',
            'discount' => $this->s($h['discount'] ?? null) ?? '0',
            'service_charge' => $this->s($h['serv_chg'] ?? null) ?? '0',
            'tax1' => $this->s($h['tax1'] ?? null) ?? '0',
            'tax2' => $this->s($h['tax2'] ?? null) ?? '0',
            'rounding' => $this->s($h['rounding'] ?? null) ?? '0',
            'extra_charge' => (float) ($h['other_charge'] ?? 0) != 0 ? $this->s($h['other_charge']) : null,
            'total' => $this->s($h['total'] ?? null) ?? '0',
            'shift' => $this->s($h['shift'] ?? null),
            'shift_name' => $this->s($h['shift_name'] ?? null),
            'submit_time' => $this->s($h['submit_time'] ?? null),
            'name' => $this->orNull($h['name'] ?? null) ?? $typedName ?? '',
            'notes' => $this->s($h['notes'] ?? null) ?? '',
            'submit_pos' => $this->s($h['submit_pos'] ?? null),
            'pending' => '0',
            'additional_charge' => '0',
        ]);
    }

    /**
     * The item report nets a void into the original row and leaves it at quantity 0 (a cancelled
     * HOMEMADE PICKLES, say). The void report has the real detail, so it replaces those rows.
     */
    private function itemLines(array $items, array $voids): array
    {
        $voidedCodes = array_map(fn ($v) => strtoupper((string) ($v['code'] ?? '')), $voids);
        $lines = [];

        foreach ($items as $row) {
            $quantity = (float) ($row['quantity'] ?? 0);
            if ($quantity == 0 && in_array(strtoupper((string) ($row['code'] ?? '')), $voidedCodes, true)) {
                continue;
            }
            $lines[] = array_merge($this->itemLine($row), $quantity <= 0 ? ['void_line' => '1'] : []);
        }

        foreach ($voids as $v) {
            // The void report has no department; take it from the netted item row it replaces.
            $netted = collect($items)->first(fn ($r) => strtoupper((string) ($r['code'] ?? '')) === strtoupper((string) ($v['code'] ?? ''))) ?? [];
            $quantity = (float) ($v['quantity'] ?? 0);
            $lines[] = array_merge($this->itemLine(['department' => $netted['department'] ?? null, 'category' => $v['category'] ?? $netted['category'] ?? null] + $v), [
                'unit_price' => $this->s($quantity != 0 ? (float) ($v['amount'] ?? 0) / $quantity : 0),
                'void_line' => '1',
            ]);
        }

        usort($lines, fn ($a, $b) => strcmp((string) $a['order_time'], (string) $b['order_time']));

        foreach ($lines as $i => &$line) {
            $line['line_code'] = (string) $i;
        }
        unset($line);

        return $lines;
    }

    private function itemLine(array $r): array
    {
        return $this->line([
            'item_code' => $this->s($r['code'] ?? null),
            'department_code' => $this->orNull($r['department'] ?? null),
            'category_code' => $this->orNull($r['category'] ?? null),
            'discount_code' => $this->orNull($r['discount_code'] ?? null),
            'description' => $this->s($r['name'] ?? null) ?? '',
            'quantity' => $this->s($r['quantity'] ?? null) ?? '0',
            'unit_price' => $this->s($r['unit_price'] ?? null) ?? '0',
            'unit_cost' => $this->s($r['unit_cost'] ?? null) ?? '0',
            'discount' => $this->s($r['discount'] ?? null) ?? '0',
            'service_charge' => $this->s($r['serv_chg'] ?? null) ?? '0',
            'tax1' => $this->s($r['tax1'] ?? null) ?? '0',
            'tax2' => $this->s($r['tax2'] ?? null) ?? '0',
            'staff_code' => $this->s($r['staff_name'] ?? null),
            'order_time' => $this->s($r['order_time'] ?? null),
            'void_reason' => $this->s($r['void_reason'] ?? null) ?? '',
        ]);
    }

    private function paymentLine(array $p, array $h): array
    {
        return $this->line([
            'line_code' => self::paymentLineCode($p['name'] ?? $p['code'] ?? null),
            'payment_code' => $this->s($p['code'] ?? null),
            'description' => $this->s($p['name'] ?? $p['code'] ?? null) ?? '',
            'quantity' => '1',
            'unit_price' => null, 'unit_cost' => null, 'discount' => null, 'service_charge' => null, 'tax1' => null, 'tax2' => null,
            'amount' => $this->s($p['amount'] ?? null) ?? '0',
            'remark' => $this->s($p['remark'] ?? null) ?? '',
            'staff_code' => $this->s($h['close_by'] ?? null),
            'order_time' => $this->s($h['close_time'] ?? null),
        ]);
    }

    private function line(array $values): array
    {
        return $this->ordered(Payload::POS_LINE, $values + [
            'parent_code' => null, 'modifier' => null, 'payment_code' => null, 'amount' => null, 'remark' => null,
            'void_line' => '0', 'void_reason' => '', 'void_payment' => '0', 'combo' => '0',
        ]);
    }

    /** All keys, in the old API's order; missing ones are null. */
    private function ordered(array $keys, array $values): array
    {
        return array_merge(array_fill_keys($keys, null), array_intersect_key($values, array_flip($keys)));
    }

    private function byInvoice(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $invoice = $this->invoice($row);
            if ($invoice !== '') {
                $grouped[$invoice][] = $row;
            }
        }

        return $grouped;
    }

    /** Void item rows carry the invoice in their nested transaction. */
    private function invoice(array $row): string
    {
        return trim((string) ($row['invoice_no'] ?? $row['transaction']['invoice_no'] ?? ''));
    }

    /** Report numbers as the old API's strings: 276595.0 -> "276595", 0.33 -> "0.33". */
    private function s(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            is_float($value) && floor($value) == $value && abs($value) < 1e15 => (string) (int) $value,
            is_float($value) => (string) round($value, 6),
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    private function orNull(mixed $value): ?string
    {
        return $this->filled($value) ? $this->s($value) : null;
    }

    private function filled(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }
}
