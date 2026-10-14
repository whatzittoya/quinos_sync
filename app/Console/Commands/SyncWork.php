<?php

namespace App\Console\Commands;

use App\Sync\WorkerLauncher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncWork extends Command
{
    protected $signature = 'sync:work';

    protected $description = 'Run queued syncs until the queue is empty, then stop (started in the background by the web app)';

    public function handle(): int
    {
        WorkerLauncher::markRunning();

        $started = time();

        try {
            // queue:work stops as soon as nothing is ready; jobs that wait for a running sync
            // come back a little later, so look again until nothing is left (at most an hour).
            do {
                $this->call('queue:work', ['--stop-when-empty' => true, '--timeout' => 3600, '--tries' => 1000]);
                WorkerLauncher::markRunning(); // keep the flag fresh while we are alive

                if (DB::table('jobs')->count() === 0) {
                    break;
                }

                sleep(3);
            } while (time() - $started < 3500);
        } finally {
            WorkerLauncher::markStopped();
        }

        return self::SUCCESS;
    }
}
