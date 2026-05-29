<?php

namespace App\Console\Commands;

use App\Services\AdBudgetEnforcementService;
use App\Support\AdBudgetGuard;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResetDailyAdBudgets extends Command
{
    protected $signature = 'ads:reset-daily-budget';

    protected $description = 'Reset daily spend counters and enforce per-ad budget pauses (no auto-resume)';

    public function __construct(
        protected AdBudgetEnforcementService $enforcer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = Carbon::today()->toDateString();
        $reset = 0;

        foreach (\App\Models\Ad::whereNotNull('meta_ad_id')->cursor() as $ad) {
            try {
                if (! $ad->spend_date || $ad->spend_date->toDateString() !== $today) {
                    $payload = AdBudgetGuard::filterPersistablePayload([
                        'daily_spend' => 0,
                        'spend_date' => $today,
                        'daily_spend_anchor' => 0,
                    ]);
                    $ad->update($payload);
                    $reset++;
                }
            } catch (\Throwable $e) {
                Log::error('BUDGET_RESET_AD_FAILED', [
                    'ad_id' => $ad->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $stats = $this->enforcer->enforceAll();

        $this->info(sprintf(
            'Reset: %d | Checked: %d | Paused: %d | Re-paused: %d | Errors: %d',
            $reset,
            $stats['checked'],
            $stats['paused'],
            $stats['re_paused'],
            $stats['errors'],
        ));

        return Command::SUCCESS;
    }
}
