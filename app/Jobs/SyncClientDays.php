<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\SyncDay;
use App\Runner\LocalSyncService;
use App\Sync\SyncProgress;
use App\Sync\SyncRunner;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncClientDays implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A long first sync is fine; the worker is started with a matching timeout. */
    public int $timeout = 3600;

    /**
     * @param  list<string>  $dates  empty = only refresh master data
     */
    public function __construct(
        public readonly int $clientId,
        public readonly array $dates,
        public readonly bool $force = false,
        public readonly ?array $masterKinds = null,
        public readonly ?array $packages = null,
    ) {}

    /** One sync at a time per client: they share one Quinos session. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('quinos-sync-'.$this->clientId))->releaseAfter(30)->expireAfter(3900)];
    }

    // Waiting for the lock releases the job back to the queue, so allow that for a while.
    public function retryUntil(): DateTime
    {
        return now()->addHours(6)->toDateTime();
    }

    public function handle(SyncRunner $runner, ?LocalSyncService $uploader = null): void
    {
        $client = Client::find($this->clientId);

        if ($client === null || ! $client->active || ! $client->hasQuinosCredentials()) {
            $this->markFailed('This client is disabled or has no Quinos login.');

            return;
        }

        try {
            $runner->run($client, $this->dates, $this->force, $this->masterKinds, $this->packages);
            $this->upload($client, $uploader ?? app(LocalSyncService::class));
        } catch (Throwable $e) {
            $this->fail($e);
        } finally {
            SyncProgress::clear($this->clientId);
        }
    }

    /** Send what was just synced to the hosted app. Days that synced but could not be sent are marked failed. */
    private function upload(Client $client, LocalSyncService $uploader): void
    {
        if ($this->dates === []) {
            try {
                $uploader->push($client, 'master', []);
            } catch (Throwable $e) {
                report($e);
            }

            return;
        }

        foreach ($this->packages ?? config('sync.packages') as $package) {
            $done = SyncDay::where('client_id', $client->id)->where('package', $package)
                ->whereIn('date', $this->dates)->where('status', SyncDay::DONE)->pluck('date')
                ->map(fn ($d) => $d->toDateString())->all();

            if ($done === []) {
                continue;
            }

            try {
                SyncProgress::set($client->id, 'Uploading '.$package.' to the hosted app');
                $uploader->push($client, $package, $done);
            } catch (Throwable $e) {
                SyncDay::where('client_id', $client->id)->where('package', $package)->whereIn('date', $done)
                    ->update(['status' => SyncDay::FAILED, 'error' => 'Synced locally but upload failed: '.mb_substr($e->getMessage(), 0, 400), 'updated_at' => now()]);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);

        $this->markFailed('The sync stopped unexpectedly. Try again.');
    }

    private function markFailed(string $message): void
    {
        SyncDay::where('client_id', $this->clientId)
            ->whereIn('date', $this->dates)
            ->when($this->packages !== null, fn ($query) => $query->whereIn('package', $this->packages))
            ->whereIn('status', [SyncDay::PENDING, SyncDay::RUNNING])
            ->update(['status' => SyncDay::FAILED, 'error' => $message, 'updated_at' => now()]);
    }
}
