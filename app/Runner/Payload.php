<?php
namespace App\Runner;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class Payload
{
    public const MASTER = ['departments', 'categories', 'items', 'sales_types', 'contacts'];
    public const PURCHASE = ['purchase_orders', 'goods_receipts', 'purchase_invoices'];
    public const SALES = ['sales_transactions'];
    public const POS = ['pos_transactions'];
    // Mobile API (old quinoscloud getTransactionByDate format): every value is a string or null, in this key order.
    public const POS_TRANSACTION = ['id', 'company_id', 'store_code', 'brand', 'transaction_id', 'date', 'sales_type', 'table_name', 'pax',
        'customer_code', 'customer_name', 'delivery_address', 'pickup_time', 'driver_code', 'open_staff_code', 'open_time', 'cashier_code',
        'close_time', 'void_transaction', 'void_reason', 'no_sales', 'subtotal', 'discount', 'service_charge', 'tax1', 'tax2', 'rounding',
        'extra_charge', 'total', 'shift', 'shift_name', 'submit_time', 'name', 'notes', 'submit_pos', 'pending', 'additional_charge'];
    public const POS_LINE = ['id', 'transaction_id', 'line_code', 'parent_code', 'modifier', 'item_code', 'department_code', 'category_code',
        'discount_code', 'payment_code', 'description', 'quantity', 'unit_price', 'unit_cost', 'discount', 'service_charge', 'tax1', 'tax2',
        'amount', 'remark', 'staff_code', 'order_time', 'void_line', 'void_reason', 'parent_item_code', 'parent_description', 'parent_category',
        'parent_department', 'parent_quantity', 'parent_unit_price', 'parent_discount', 'void_payment', 'combo'];
    public static function tables(string $package): array
    {
        return match ($package) { 'master' => self::MASTER, 'purchase' => self::PURCHASE, 'sales' => self::SALES, 'pos' => self::POS };
    }
    public static function fields(string $table): array
    {
        $id = ['quinos_id' => 'required|integer|min:1'];
        $name = ['name' => 'required|string|max:255'];
        $ref = 'nullable|integer|min:1';
        $str = 'nullable|string|max:255';
        $num = 'required|numeric|between:-9999999999999999,9999999999999999';
        $header = $id + [
            'no' => 'required|string|max:255', 'ref_no' => $str, 'date' => 'required|date_format:Y-m-d',
            'contact_quinos_id' => $ref, 'contact_name' => $str, 'warehouse_quinos_id' => $ref,
            'warehouse_name' => $str, 'status' => 'nullable|string|max:50', 'closed' => 'required|boolean',
            'subtotal' => $num, 'tax_amount' => $num, 'total' => $num,
            'quinos_created_at' => 'nullable|date', 'quinos_updated_at' => 'nullable|date', 'synced_at' => 'required|date',
        ];
        $line = ['quinos_id' => $ref, 'item_quinos_id' => $ref, 'code' => $str, 'name' => 'present|string|max:255',
            'category' => $str, 'uom' => 'nullable|string|max:50', 'quantity' => $num, 'unit_price' => $num,
            'subtotal' => $num, 'tax_code' => 'nullable|string|max:50', 'tax_rate' => 'required|numeric|between:-9999,9999', 'tax_amount' => $num];
        return match ($table) {
            'departments' => $id + $name,
            'categories' => $id + $name + ['department_quinos_id' => $ref],
            'items' => $id + ['name' => 'present|string|max:255', 'code' => $str, 'category_quinos_id' => $ref, 'department_quinos_id' => $ref, 'active' => 'required|boolean'],
            'sales_types' => $id + $name + ['active' => 'required|boolean'],
            'contacts' => $id + $name + ['supplier' => 'required|boolean', 'customer' => 'required|boolean', 'active' => 'required|boolean'],
            'purchase_orders' => $header + ['pr_no' => $str, 'delivery_date' => 'nullable|date_format:Y-m-d'],
            'goods_receipts' => $header + ['po_no' => $str, 'purchase_order_quinos_id' => $ref, 'purchase_invoice_quinos_id' => $ref, 'by_system' => 'required|boolean'],
            'purchase_invoices' => $header + ['direct' => 'required|boolean', 'receipt_ids' => 'present|array', 'receipt_ids.*' => 'integer|min:1', 'balance' => $num],
            'purchase_order_lines' => $line + ['received' => $num],
            'goods_receipt_lines' => $line,
            'sales_transactions' => $id + ['date' => 'required|date_format:Y-m-d', 'invoice_no' => $str, 'outlet_name' => $str,
                'sales_type' => 'nullable|string|max:50', 'pax' => 'required|integer|between:0,4294967295',
                'subtotal' => $num, 'discount' => $num, 'serv_chg' => $num, 'tax' => $num, 'total' => $num,
                'open_time' => 'nullable|date_format:Y-m-d H:i:s', 'close_time' => 'nullable|date_format:Y-m-d H:i:s'],
            'pos_transactions' => $id + ['date' => 'required|date_format:Y-m-d', 'invoice_no' => $str, 'store_code' => $str,
                'close_time' => 'nullable|date_format:Y-m-d H:i:s',
                'transaction' => 'required|array:'.implode(',', self::POS_TRANSACTION).'|required_array_keys:'.implode(',', self::POS_TRANSACTION),
                'transaction.*' => 'nullable|string|max:1000',
                'transaction_lines' => 'present|array|max:2000',
                'transaction_lines.*' => 'array:'.implode(',', self::POS_LINE).'|required_array_keys:'.implode(',', self::POS_LINE),
                'transaction_lines.*.*' => 'nullable|string|max:1000'],
            'sales_lines' => ['type' => 'required|in:ITEM,DISCOUNT', 'code' => $str, 'name' => 'present|string|max:255', 'quantity' => $num, 'unit_price' => $num, 'amount' => $num],
        };
    }
    public static function lineTable(string $table): ?string
    {
        return match ($table) { 'purchase_orders' => 'purchase_order_lines', 'goods_receipts' => 'goods_receipt_lines', 'sales_transactions' => 'sales_lines', default => null };
    }
    public static function clean(string $table, array $row): array
    {
        $fields = self::fields($table);
        $rules = $fields;
        $lineTable = self::lineTable($table);
        if ($lineTable) {
            $rules['lines'] = 'present|array|max:10000';
            foreach (self::fields($lineTable) as $key => $rule) $rules['lines.*.'.$key] = $rule;
        }
        $allowed = array_filter(array_keys($fields), fn ($key) => !str_contains($key, '.'));
        if ($lineTable) $allowed[] = 'lines';
        if (array_diff(array_keys($row), $allowed)) throw ValidationException::withMessages(['payload' => 'Unexpected document fields.']);
        $data = Validator::make($row, $rules)->validate();
        if ($lineTable) $data['lines'] = array_map(fn ($line) => self::clean($lineTable, $line), $data['lines']);
        return $data;
    }
}
