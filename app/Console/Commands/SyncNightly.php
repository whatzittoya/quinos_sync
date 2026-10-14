<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Sync\SyncDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SyncNightly extends Command
{
    protected $signature = 'sync:nightly {--days=2 : How many days back to include, today counts as the first}';

    protected $description = 'Queue a sync of the latest days for every active client that has a Quinos login';

    public function handle(SyncDispatcher $dispatcher): int
    {
        $span = max(1, (int) $this->option('days'));

        $clients = Client::where('active', true)->orderBy('id')->get()
            ->filter(fn (Client $client) => $client->hasQuinosCredentials());

        foreach ($clients as $client) {
            $today = CarbonImmutable::now($client->timezone);
            $dates = collect(range(0, $span - 1))->map(fn ($i) => $today->subDays($i)->toDateString())->all();

            $result = $dispatcher->days($client, $dates);

            $this->line("{$client->name}: queued ".count($result['queued']).' day(s)'
                .($result['skipped'] ? ', skipped '.count($result['skipped']).' already waiting' : ''));
        }

        if ($clients->isEmpty()) {
            $this->line('No active client has a Quinos login.');
        }

        return self::SUCCESS;
    }
}
