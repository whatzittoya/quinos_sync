<?php

namespace Tests\Support;

/** Builders for made-up Quinos purchase documents, shaped like the real list/detail rows. */
class QuinosDocs
{
    public static function iso(string $date): string
    {
        return $date.'T00:00:00.000000Z';
    }

    public static function order(int $id, string $date, string $created = '2026-10-09T10:00:00.000000Z', string $updated = '2026-10-09T10:00:00.000000Z'): array
    {
        return [
            'id' => $id, 'no' => 'PO.'.(10000 + $id), 'ref_no' => '', 'date' => self::iso($date),
            'delivery_date' => self::iso($date), 'pr_no' => 'PR.1', 'status' => 'Partial', 'closed' => 0,
            'subtotal' => 36630, 'tax_amount' => 0, 'total' => 36630,
            'contact_id' => 1437, 'contact' => ['id' => 1437, 'name' => 'PASAR'],
            'warehouse_id' => 225, 'warehouse' => ['id' => 225, 'name' => 'KITCHEN'],
            'created_at' => $created, 'updated_at' => $updated,
        ];
    }

    /** The detail page props for an order (adds lines). */
    public static function orderDetail(array $order, array $lines = null): array
    {
        return ['purchaseOrder' => $order + ['lines' => $lines ?? [[
            'id' => $order['id'] * 10, 'item_id' => 121415, 'name' => '/Icing Sugar @15 kg ING', 'code' => 'INV-170',
            'category' => '772', 'quantity' => 1, 'received' => 0.5, 'uom' => 'GR', 'unit_price' => 36630,
            'subtotal' => 36630, 'tax_code' => '', 'tax_rate' => 0, 'tax_amount' => 0,
        ]]]];
    }

    public static function receipt(int $id, string $date, ?int $orderId = null, ?int $invoiceId = null, string $created = '2026-10-09T10:00:00.000000Z'): array
    {
        return [
            'id' => $id, 'no' => 'GR.'.(10000 + $id), 'po_no' => $orderId ? 'PO.'.(10000 + $orderId) : '',
            'purchase_order_id' => $orderId, 'purchase_invoice_id' => $invoiceId, 'by_system' => 0,
            'date' => self::iso($date), 'status' => 'Closed', 'closed' => 1,
            'subtotal' => 18315, 'tax_amount' => 0, 'total' => 18315,
            'contact' => ['id' => 1437, 'name' => 'PASAR'], 'warehouse' => ['id' => 225, 'name' => 'KITCHEN'],
            'created_at' => $created, 'updated_at' => $created,
            'lines' => [[
                'id' => $id * 10, 'item_id' => 121415, 'name' => '/Icing Sugar @15 kg ING', 'code' => 'INV-170',
                'category' => '772', 'quantity' => 0.5, 'uom' => 'GR', 'unit_price' => 36630,
                'subtotal' => 18315, 'tax_code' => '', 'tax_rate' => 0, 'tax_amount' => 0,
            ]],
        ];
    }

    public static function invoice(int $id, string $date, array $receipts = [], float $total = 18315, string $created = '2026-10-09T10:00:00.000000Z'): array
    {
        return [
            'id' => $id, 'no' => 'PI.'.(10000 + $id), 'ref_no' => '', 'date' => self::iso($date),
            'direct' => $receipts === [] ? 1 : 0, 'receipts' => $receipts,
            'subtotal' => $total, 'tax_amount' => 0, 'total' => $total, 'balance' => $total,
            'closed' => 0, 'status' => '',
            'contact' => ['id' => 1437, 'name' => 'PASAR'], 'warehouse' => ['id' => 225, 'name' => 'KITCHEN'],
            'created_at' => $created, 'updated_at' => $created,
        ];
    }
}
