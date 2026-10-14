<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // The only cron line the server needs is `* * * * * php artisan schedule:run`.

        // Runs queued syncs. Shared hosting cannot keep a worker alive, so a short-lived
        // worker starts every minute, drains the queue and exits. `withoutOverlapping`
        // keeps a long first sync from being started twice.
        $schedule->command('queue:work --stop-when-empty --timeout=3600')
            ->everyMinute()
            ->withoutOverlapping(120);

        // Yesterday and today for every client (Bali time).
        $schedule->command('sync:nightly')
            ->dailyAt('02:30')
            ->timezone('Asia/Makassar')
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
