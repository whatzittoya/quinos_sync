<?php
namespace App\Runner;
use App\Models\Client;
use App\Models\RunnerImport;
use App\Models\SyncDay;
use App\Support\CurrentClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportBatch
{
    public const MODELS = [
        'departments' => \App\Models\Department::class, 'categories' => \App\Models\Category::class,
        'items' => \App\Models\Item::class, 'sales_types' => \App\Models\SalesType::class,
        'contacts' => \App\Models\Contact::class, 'purchase_orders' => \App\Models\PurchaseOrder::class,
        'goods_receipts' => \App\Models\GoodsReceipt::class, 'purchase_invoices' => \App\Models\PurchaseInvoice::class,
        'sales_transactions' => \App\Models\SalesTransaction::class, 'pos_transactions' => \App\Models\PosTransaction::class,
    ];
    public function apply(RunnerImport $import): array
    {
        return app(CurrentClient::class)->runAs($import->client_id, function () use ($import) {
            $counts = []; $seen = []; $byDate = array_fill_keys($import->dates, 0);
            foreach (Payload::tables($import->package) as $table) { $seen[$table] = []; $counts[$table] = 0; }
            // Revalidate stored chunks at commit. Chunks remain private until this transaction commits.
            foreach (DB::table('runner_import_chunks')->where('runner_import_id', $import->id)->orderBy('sequence')->cursor() as $chunk) {
                $payload = json_decode($chunk->payload, true, 512, JSON_THROW_ON_ERROR);
                foreach ($payload as $table => $rows) foreach ($rows as $row) {
                    $row = Payload::clean($table, $row);
                    if ($import->package !== 'master' && !in_array($row['date'], $import->dates, true))
                        throw ValidationException::withMessages(['payload' => 'A document date is outside the import dates.']);
                    if (isset($seen[$table][$row['quinos_id']]))
                        throw ValidationException::withMessages(['payload' => 'Duplicate Quinos document ID.']);
                    $seen[$table][$row['quinos_id']] = true;
                    $lines = $row['lines'] ?? null; unset($row['lines']);
                    $model = self::MODELS[$table];
                    $record = $model::updateOrCreate(['client_id' => $import->client_id, 'quinos_id' => $row['quinos_id']], $row);
                    if ($lines !== null) {
                        $record->lines()->delete();
                        foreach ($lines as $line) $record->lines()->create($line + ['client_id' => $import->client_id]);
                    }
                    $counts[$table]++;
                    if ($import->package !== 'master') $byDate[$row['date']]++;
                }
            }
            foreach (Payload::tables($import->package) as $table) {
                $model = self::MODELS[$table];
                $query = $model::where('client_id', $import->client_id)->whereNotIn('quinos_id', array_keys($seen[$table]));
                if ($import->package === 'master') {
                    if ($seen[$table] !== []) $query->delete(); // match MasterSync's empty-list safety
                } else {
                    $query->whereIn('date', $import->dates);
                    if ($import->package === 'purchase' && $import->purchase_cutoff)
                        $query->where('quinos_created_at', '>=', $import->purchase_cutoff);
                    $query->delete();
                }
            }
            $client = Client::findOrFail($import->client_id);
            if ($import->package === 'master') $client->forceFill(['master_synced_at' => $import->source_synced_at])->save();
            else foreach ($byDate as $date => $count) SyncDay::updateOrCreate(
                ['client_id' => $client->id, 'package' => $import->package, 'date' => $date],
                ['status' => SyncDay::DONE, 'rows' => $count, 'error' => null, 'synced_at' => $import->source_synced_at,
                    'partial' => CarbonImmutable::parse($import->source_synced_at)->lt(CarbonImmutable::parse($date, $client->timezone)->endOfDay())],
            );
            return $counts;
        });
    }
}
