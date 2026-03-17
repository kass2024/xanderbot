<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {

        /*
        |--------------------------------------------------------------------------
        | META ADS SYNC (PRIMARY - CRITICAL)
        |--------------------------------------------------------------------------
        | Syncs ad spend, impressions, clicks
        | MUST RUN correctly or dashboard freezes
        */
        $schedule->command('meta:sync-ads')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->runInBackground()
            ->name('meta-sync-ads')
            ->appendOutputTo(storage_path('logs/meta-sync.log'));


        /*
        |--------------------------------------------------------------------------
        | BUDGET RESET + AUTO RESUME
        |--------------------------------------------------------------------------
        | Handles:
        | - Daily reset
        | - Resume ads (excluding manual pause)
        */
        $schedule->command('ads:reset-daily-budget')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->runInBackground()
            ->name('ads-budget-reset')
            ->appendOutputTo(storage_path('logs/ad-reset.log'))
            ->after(function () {
                Log::info('BUDGET_RESET_FINISHED');
            });


        /*
        |--------------------------------------------------------------------------
        | MESSAGING AUTOMATION
        |--------------------------------------------------------------------------
        */
        $schedule->command('report:unread-messages')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->runInBackground()
            ->name('messaging-unread-report')
            ->appendOutputTo(storage_path('logs/scheduler.log'));


        /*
        |--------------------------------------------------------------------------
        | 🚫 REMOVED BROKEN COMMAND
        |--------------------------------------------------------------------------
        | meta:sync ❌ (was ambiguous and breaking sync)
        | DO NOT ADD BACK unless explicitly implemented
        */


        /*
        |--------------------------------------------------------------------------
        | AGENT ESCALATION MONITOR
        |--------------------------------------------------------------------------
        */
        $schedule->command('agents:monitor')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->runInBackground()
            ->name('agent-escalation-monitor')
            ->appendOutputTo(storage_path('logs/agent-monitor.log'));


        /*
        |--------------------------------------------------------------------------
        | QUEUE WORKER (PRODUCTION NOTE BELOW)
        |--------------------------------------------------------------------------
        | ⚠️ In production, prefer Supervisor instead of scheduler
        */
        $schedule->command('queue:work --tries=3 --timeout=90')
            ->everyMinute()
            ->runInBackground()
            ->withoutOverlapping(10)
            ->name('queue-worker')
            ->appendOutputTo(storage_path('logs/queue-worker.log'));


        /*
        |--------------------------------------------------------------------------
        | SYSTEM HEARTBEAT
        |--------------------------------------------------------------------------
        */
        $schedule->call(function () {

            Log::info('SYSTEM_SCHEDULER_HEARTBEAT', [
                'timestamp' => now()->toDateTimeString(),
                'environment' => app()->environment(),
            ]);

        })
        ->hourly()
        ->withoutOverlapping(10)
        ->name('scheduler-heartbeat');
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