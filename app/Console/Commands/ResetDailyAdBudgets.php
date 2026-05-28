<?php

namespace App\Console\Commands;

use App\Models\Ad;
use App\Services\MetaAdsService;
use App\Support\AdBudgetGuard;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResetDailyAdBudgets extends Command
{
    protected $signature = 'ads:reset-daily-budget';

    protected $description = 'Reset daily spend counters and enforce per-ad budget pauses (no auto-resume)';

    public function __construct(protected MetaAdsService $meta)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = Carbon::today()->toDateString();
        $reset = 0;
        $paused = 0;

        foreach (Ad::whereNotNull('meta_ad_id')->cursor() as $ad) {
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

                $wasActive = $ad->status === Ad::STATUS_ACTIVE;
                AdBudgetGuard::enforce($ad, $this->meta);
                $ad->refresh();

                if ($wasActive && $ad->status === Ad::STATUS_PAUSED && AdBudgetGuard::isBudgetLimitPaused($ad)) {
                    $paused++;
                }
            } catch (\Throwable $e) {
                Log::error('BUDGET_RESET_AD_FAILED', [
                    'ad_id' => $ad->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Reset: {$reset} | Auto-paused for budget: {$paused}");

        return Command::SUCCESS;
    }
}
