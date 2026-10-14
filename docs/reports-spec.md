# Purchase Detail Report: Plan

Goal: produce a "Purchase Detail Report" (Xero-bill-style, see sample) for Zali Uluwatu by pulling purchase data from https://quinosbackend.com. Status: **planning only, no code written yet.**

## Data sources (Inertia JSON)

All requests need a logged-in session and the headers `Accept: application/json`, `X-Inertia: true`, `X-Inertia-Version: <version from a prior response>`, `X-Requested-With: XMLHttpRequest`.

| Purpose | URL | Key fields |
|---|---|---|
| PO list | `/purchaseOrders` | row id, `no`, date, supplier, total, status |
| PO detail | `/purchaseOrders/{id}` | `purchaseOrder.lines[]` (name, code, quantity, unit_price, category, tax_*), `contact.name`, `date` |
| PO to invoice link | `/goodsReceipts?cursor=&search=` | per GR: `purchase_order_id`, `purchase_invoice_id`, `lines[]` (quantity, unit_price) |
| Invoice amounts | `/purchaseInvoices?cursor=&search=` | `id`, `no`, `date`, `total`, `balance`, `receipts[]` (GR ids), `direct` |

Lists are in `props.rows.data`. Pagination is cursor-based: follow `props.rows.meta.next_cursor` (`?cursor=<value>`) until null (20 per page).
The PO detail is in `props.purchaseOrder`.

## Join logic

```
PO --(GR.purchase_order_id)--> GR --(GR.purchase_invoice_id)--> PI
```

- A PO can have many GRs, and each GR can point to a different PI. Collect all PIs per PO.
- Sum invoice `total` for Total and sum `balance` for InvoiceAmountDue (PO.10005: 18,315 + 18,315 = 36,630).
- Ignore direct invoices (`direct = 1`, no PO), e.g. PI.10001. Decision: **only POs are included**.
- Skip soft-deleted rows (`deleted_at` not null).

## Row granularity and field mapping

One row **per invoice per line item**. A PO with 2 invoices gives 2 rows, using that GR's line quantity (0.5 each for PO.10005).

| Report column | Source |
|---|---|
| ContactName | `contact.name` (supplier) |
| InvoiceNumber | PI `no` (`ref_no` is empty in current data) |
| InvoiceDate | PO `date` (not on the invoice list) |
| DueDate | PO `date` |
| Total | invoice `total` (summed across linked invoices; see open question 1) |
| InvoiceAmountDue | invoice `balance` |
| Inventory/Item | line `name` |
| ItemCode | line `code` |
| Quantity | GR line `quantity` for that invoice |
| UnitAmount | line `unit_price` |
| AccountCode | line `category` |
| TaxType | "Tax Exempt" when `tax_rate` is 0, else the `tax_code` |
| TaxAmount | line `tax_amount` |
| Currency | IDR |
| Type | Bill |
| Status | Awaiting Payment (while `balance` > 0; all current data) |

Rules for POs with **no invoice yet**: emit the PO line rows with **empty invoice amount** columns.

## Auth and safety

- Auth uses the `quinos_session` (and `XSRF-TOKEN`) cookies the user supplies. These expire after about 2 hours.
- Read from env vars or a git-ignored file, never hardcoded. Never log or commit cookies.
- The pasted payloads contain a user PIN and a company token. Keep them out of code, fixtures, and logs, and consider rotating the PIN.
- Read-only: GET requests only. Add a small delay between detail requests (e.g. 200-500 ms) and stop on 401/419 with a "session expired" message.

## Steps (to be done after approval)

1. Fetch all pages of `/purchaseOrders` and collect the PO ids.
2. Fetch `/purchaseOrders/{id}` for each PO to get lines.
3. Fetch all pages of `/goodsReceipts` and build a map of PO id to [GR with its PI id and lines].
4. Fetch all pages of `/purchaseInvoices` and build a map of PI id to {no, total, balance}.
5. Join per the rules above and build the rows.
6. Sanity checks: the sum of invoice totals per PO should be <= the PO total; warn on mismatch.
7. Output (format to be discussed later): the in-memory rows can be written to CSV, XLSX, or JSON.

## Open questions

1. The report's `Total`: use the invoice total on each invoice's rows (like the sample) or the sum across the PO's invoices? Current assumption: each row shows its own invoice's total, with the PO-level sum available if wanted.
2. Output format and destination (Excel or CSV, file location).
3. Filters (date range, supplier, warehouse) if needed.
4. Quantity on unbilled PO rows: assuming PO line quantity.
5. Scale: how many POs will be processed per run (affects the request delay).

---

# Report 2: Weekly Summary Sales Report

Goal: reproduce the "Weekly Summasy Sales Report" PDF (Zali Uluwatu, 1-7 Oct 2026): one block per day with a row per daypart plus a TOTAL row, then a weekly total block. Status: **planning only, no code written yet.**

## Data source

`POST /reports/pos/transactionDetailReport` (Inertia). Body: `from` ("2026-10-01"), `to` (ISO string, e.g. "2026-10-02T00:00:00.000Z"), `outlet_id` (null = all). Needs the session cookie plus `X-XSRF-TOKEN` taken from the `XSRF-TOKEN` cookie.

Response `props.result[]`: one entry per transaction with `invoice_no`, `sales_type`, `pax`, `subtotal`, `discount`, `serv_chg`, `tax1`, `total`, `open_time`, `close_time`, and `lines[]` (`type` ITEM/DISCOUNT/PAYMENT, `code`, `name`, `quantity`, `unit_price`).

The response is one large payload with no pagination: fetch **one day per request** and merge.

## Calculation rules (verified against the 1 Oct PDF figures)

| Output column | Rule |
|---|---|
| Covers/Pax per sales type | sum of `pax` grouped by `sales_type`. Sales types configured in the backend (`/salesTypes`): `DINE IN`, `EVENT/CATERING`, `EXTRA`, `GOJEK`, `GRAB`, `TAKE AWAY`. The PDF has columns for all except `EXTRA` (see open questions) |
| Revenue per sales type | sum of `subtotal` minus `discount` (Gojek, Grab, and Takeaway had no discounts, so they equal `subtotal`) |
| Revenue TOTAL | sum of the per-type revenue |
| Food / Beverage | sum of ITEM line `quantity * unit_price` by the department of the line `code` (gross, before discount) |
| F&B | Food + Beverage |
| Others | items in the OTHER department (e.g. the MOODS mouneh jars) |
| Average Check/Pax | daypart rows: revenue TOTAL / covers TOTAL. TOTAL rows: simple mean of the daypart averages (this is what the PDF does) |

Known and expected: Revenue TOTAL differs from F&B + Others by the discount amount, because Dine In revenue is net of discount while the Food/Beverage split is gross (1 Oct: 59,250 + 63,000 = 122,250).

Ignore ITEM lines with unit_price 0 (modifiers, `MOD*` codes), and ignore zero or negative quantity pairs (voids). `subtotal` is already net.

## Shift (daypart) configuration

Dayparts are **configurable, not hard-coded**, because the PDF's labelled windows do not match the actual grouping (1 Oct Breakfast holds only INV-000001, opened 09:41, while INV-000002 to 000007 opened before noon and are in Lunch).

Config file (e.g. `shifts.json`):

```json
{
  "assign_by": "open_time",
  "timezone": "Asia/Makassar",
  "shifts": [
    { "name": "Breakfast 8AM - 11:59AM", "start": "00:00", "end": "10:00" },
    { "name": "Lunch 12.00 PM - 5 PM",   "start": "10:00", "end": "17:00" },
    { "name": "Dinner 5.01 PM - 11.59 PM", "start": "17:00", "end": "24:00" }
  ]
}
```

- `name` is the label printed in the report, so it can differ from the real times.
- `start` inclusive, `end` exclusive, in local time (Bali, UTC+8).
- `assign_by` is `open_time` or `close_time`. Default `open_time` (Gojek INV-000026, opened 16:57, is in Lunch in the PDF).
- The Breakfast end (10:00) and Lunch end (17:00) values above are **guesses** inferred from 1 and 2 Oct. They must be confirmed against real data or the shift settings before use.
- A transaction that falls outside every shift is reported in a warning, not silently dropped.
- Empty dayparts are omitted for that day (the PDF shows no Breakfast row on 2 Oct).

## Item classification (from backend data, not code prefixes)

Source: `GET /items` (paginated). Each item has `code`, `category_id`, and a nested `category` with `department_id`. Departments come from `GET /departments`:

| department_id | Department | Report column |
|---|---|---|
| 1415 | FOOD | Food |
| 1416 | BEVERAGE | Beverage |
| 1417 | OTHER (Modifier, Zali Mouneh Other, Spirit By Bottle Other) | Others |
| 1466 | INVENTORY (771 to 787, purchasing items) | not used in sales |

- Build a lookup `item code -> department` by walking every page of `/items` (20 per page; next page is `/items?cursor=<meta.next_cursor>&search=&type=`, repeat until `next_cursor` is null). Cache it to a local file and refresh when the run starts or when an unknown code appears.
- Transaction lines carry only `code`, so match on `code`. Warn if one code maps to several departments.
- Unknown codes are listed in a warning and counted in neither Food nor Beverage.
- This replaces the earlier code-prefix guess (which would have put bottle spirits in Beverage; the backend puts them in Others).

## Steps (to be done after approval)

1. Load `shifts.json`, then fetch departments and items to build the code-to-department lookup.
2. For each day in the range, POST the report request and collect the transactions.
3. Assign each transaction to a day and shift.
4. Aggregate covers, revenue by sales type, and Food/Beverage/Others per day and shift, then add TOTAL rows and the weekly block.
5. Sanity checks: per-day revenue should equal the sum of `subtotal` minus `discount`; warn on unassigned transactions or unknown item codes.
6. Output format to be discussed later.

## Open questions

1. Confirm the Breakfast and Lunch cutoff times (or point to the shift settings in the backend).
2. Confirm that Dine In revenue is `subtotal` minus `discount` across a full day (only Gojek, Grab, and Takeaway were verified exactly).
3. Resolved: the Event/Catering string is `EVENT/CATERING`. `EXTRA` transactions are **excluded** from the report (decided by the user); log a warning with the count if any are found.
4. Resolved: use `/items` + `/departments` (see above). Still to check: how many items exist, and whether `/items` accepts a larger page size.
5. Output format, and whether a partial final day (like 7 Oct in the PDF) needs special handling.
