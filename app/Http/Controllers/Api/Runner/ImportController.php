<?php
namespace App\Http\Controllers\Api\Runner;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RunnerImport;
use App\Models\SyncDay;
use App\Runner\ImportBatch;
use App\Runner\Payload;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportController extends Controller
{
    private function client(int $id): Client
    {
        return Client::where('active', true)->findOrFail($id);
    }
    private function owned(Request $request, string $id): RunnerImport
    {
        $import = RunnerImport::where('runner_token_id', $request->attributes->get('runner_token')->id)->findOrFail($id);
        $this->client($import->client_id);
        return $import;
    }
    public function begin(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|uuid', 'client_id' => 'required|integer', 'package' => 'required|in:master,purchase,sales',
            'dates' => 'present|array|max:62', 'dates.*' => 'required|date_format:Y-m-d|distinct',
            'source_synced_at' => 'required|date|before_or_equal:now', 'purchase_cutoff' => 'nullable|date',
            'chunk_count' => 'required|integer|between:1,10000',
        ]);
        $client = $this->client($data['client_id']);
        abort_if(($data['package'] === 'master') !== ($data['dates'] === []), 422, 'Master imports have no dates; report imports require dates.');
        foreach ($data['dates'] as $date) abort_if($date > CarbonImmutable::now($client->timezone)->toDateString(), 422, 'Future dates cannot be imported.');
        $data['runner_token_id'] = $request->attributes->get('runner_token')->id;
        // Serialize identity registration on the tenant, including concurrent duplicate requests.
        $import = DB::transaction(function () use ($data, $client) {
            Client::whereKey($client->id)->lockForUpdate()->first();
            $existing = RunnerImport::find($data['id']);
            if ($existing) {
                foreach (['client_id', 'package', 'dates', 'chunk_count', 'runner_token_id'] as $key)
                    abort_unless($existing->{$key} == $data[$key], 409, 'Import ID belongs to another batch.');
                abort_unless($existing->source_synced_at->equalTo(CarbonImmutable::parse($data['source_synced_at'])) &&
                    (($existing->purchase_cutoff?->toIso8601String()) === (isset($data['purchase_cutoff']) ? CarbonImmutable::parse($data['purchase_cutoff'])->toIso8601String() : null)), 409, 'Import metadata changed.');
                return $existing;
            }
            return RunnerImport::create($data);
        });
        return response()->json(['id' => $import->id, 'status' => $import->status, 'counts' => $import->counts, 'already_applied' => $import->status === 'success']);
    }
    public function chunk(Request $request, string $id, int $sequence): array
    {
        $import = $this->owned($request, $id);
        abort_if($sequence < 0 || $sequence >= $import->chunk_count, 422, 'Invalid chunk sequence.');
        abort_if(strlen($request->getContent()) > 2 * 1024 * 1024, 413, 'Chunk is too large.');
        $data = $request->validate(['tables' => 'required|array']);
        abort_if(array_diff(array_keys($data['tables']), Payload::tables($import->package)) !== [], 422, 'Invalid tables for this package.');
        foreach ($data['tables'] as $table => $rows) {
            abort_unless(is_array($rows) && array_is_list($rows) && count($rows) <= 100, 422, 'Each chunk accepts at most 100 documents per table.');
            foreach ($rows as $row) {
                abort_unless(is_array($row), 422, 'Invalid document.');
                Payload::clean($table, $row);
            }
        }
        $payload = json_encode($data['tables'], JSON_THROW_ON_ERROR);
        $digest = hash('sha256', $payload);
        DB::transaction(function () use ($import, $sequence, $payload, $digest) {
            $locked = RunnerImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('runner_import_chunks')->where('runner_import_id', $import->id)->where('sequence', $sequence)->first();
            if ($existing) { abort_unless(hash_equals($existing->digest, $digest), 409, 'Chunk contents changed.'); return; }
            abort_if($locked->status === 'success', 409, 'Import already applied.');
            DB::table('runner_import_chunks')->insert(['runner_import_id' => $import->id, 'sequence' => $sequence, 'digest' => $digest, 'payload' => $payload]);
        });
        return ['accepted' => true];
    }
    public function commit(Request $request, string $id, ImportBatch $batch)
    {
        $import = $this->owned($request, $id);
        $lock = Cache::lock('laravel-queue-overlap:'.\App\Jobs\SyncClientDays::class.':quinos-sync-'.$import->client_id, 3900);
        abort_unless($lock->get(), 409, 'A sync for this tenant is already running. Retry later.');
        try {
            $result = DB::transaction(function () use ($import, $batch) {
                $client = Client::whereKey($import->client_id)->lockForUpdate()->firstOrFail();
                $import = RunnerImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
                if ($import->status === 'success') return ['already_applied' => true, 'counts' => $import->counts];
                abort_unless(DB::table('runner_import_chunks')->where('runner_import_id', $import->id)->count() === $import->chunk_count, 422, 'Some chunks are missing.');
                $latest = $import->package === 'master' ? $client->master_synced_at : SyncDay::where('client_id', $client->id)->where('package', $import->package)->whereIn('date', $import->dates)->max('synced_at');
                abort_if($latest && CarbonImmutable::parse($latest)->gt($import->source_synced_at), 409, 'Newer data has already been synced. Re-sync this package.');
                $counts = $batch->apply($import);
                $import->update(['status' => 'success', 'counts' => $counts, 'completed_at' => now(), 'error' => null]);
                // Keep digests for audit/idempotency, clear staged report data after publication.
                DB::table('runner_import_chunks')->where('runner_import_id', $import->id)->update(['payload' => '{}']);
                return ['already_applied' => false, 'counts' => $counts];
            });
            return response()->json(['id' => $id, 'status' => 'success'] + $result);
        } catch (Throwable $e) {
            if ($import->fresh()->status !== 'success') $import->update(['status' => 'failed', 'error' => 'Import could not be applied. Retry or re-sync the package.']);
            if (!$e instanceof ValidationException && !$e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                // Do not report SQL bindings or untrusted batch values.
                return response()->json(['message' => 'Import failed. No data was changed.'], 500);
            }
            throw $e;
        } finally { $lock->release(); }
    }
}
