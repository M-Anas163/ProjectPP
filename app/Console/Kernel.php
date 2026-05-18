<?php
// app/Console/Kernel.php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\ProcessDailySalesCommand;
use App\Console\Commands\StartWorkerPool;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        ProcessDailySalesCommand::class,
        StartWorkerPool::class,
    ];

    protected function schedule(Schedule $schedule): void
    {

        $schedule->command('sales:process-daily')
            ->dailyAt('00:05')
            ->withoutOverlapping()
            ->runInBackground()         
            ->appendOutputTo(storage_path('logs/batch.log'));
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
