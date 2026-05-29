<?php

namespace App\Support;

use App\Models\Ad;
use App\Services\MetaAdsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AdBudgetGuard
{
    /** Pause Meta before reported spend reaches budget (Meta can overshoot between syncs). */
    public const BUDGET_PAUSE_BUFFER = 0.15;

    protected static ?bool $hasAnchorColumn = null;

    public static function hasAnchorColumn(): bool
    {
        if (static::$hasAnchorColumn === null) {
            static::$hasAnchorColumn = Schema::hasColumn('ads', 'daily_spend_anchor');
        }

        return static::$hasAnchorColumn;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function filterPersistablePayload(array $payload): array
    {
        if (! static::hasAnchorColumn()) {
            unset($payload['daily_spend_anchor']);
        }

        return $payload;
    }

    public static function isBudgetLimitPaused(Ad $ad): bool
    {
        return in_array($ad->pause_reason, ['budget_limit', 'budget'], true)
            && $ad->status === Ad::STATUS_PAUSED;
    }

    public static function isSpendFrozen(Ad $ad): bool
    {
        return $ad->status === Ad::STATUS_PAUSED;
    }

    public static function sessionSpend(Ad $ad, float $metaTodaySpend): float
    {
        if (static::isSpendFrozen($ad)) {
            return 0;
        }

        $today = Carbon::today()->toDateString();

        if ($ad->spend_date && $ad->spend_date->toDateString() !== $today) {
            return max(0, $metaTodaySpend);
        }

        $anchor = (float) ($ad->daily_spend_anchor ?? 0);

        return max(0, $metaTodaySpend - $anchor);
    }

    public static function cappedSessionSpend(Ad $ad, float $metaTodaySpend): float
    {
        $session = static::sessionSpend($ad, $metaTodaySpend);
        $budget = (float) $ad->daily_budget;

        if ($budget <= 0) {
            return $session;
        }

        return min($session, $budget);
    }

    /**
     * @return array{daily_spend: float, spend_date: string, daily_spend_anchor?: float}
     */
    public static function metricsPayloadFromMetaToday(Ad $ad, float $metaTodaySpend): array
    {
        $today = Carbon::today()->toDateString();

        if (static::isSpendFrozen($ad)) {
            $payload = [
                'daily_spend' => 0,
                'spend_date' => $today,
            ];

            if (static::hasAnchorColumn() && (float) ($ad->daily_spend_anchor ?? 0) < $metaTodaySpend) {
                $payload['daily_spend_anchor'] = max(0, $metaTodaySpend);
            }

            return static::filterPersistablePayload($payload);
        }

        $payload = [
            'daily_spend' => static::cappedSessionSpend($ad, $metaTodaySpend),
            'spend_date' => $today,
        ];

        if ($ad->spend_date && $ad->spend_date->toDateString() !== $today) {
            if (static::hasAnchorColumn()) {
                $payload['daily_spend_anchor'] = 0;
            }
        }

        return static::filterPersistablePayload($payload);
    }

    public static function reconcileBudgetLimitPause(Ad $ad, float $metaTodaySpend): void
    {
        static::reconcilePausedAdSpend($ad, $metaTodaySpend);
    }

    /**
     * Keep today's spend at zero for any paused ad; advance anchor if Meta still reports spend.
     */
    public static function reconcilePausedAdSpend(Ad $ad, float $metaTodaySpend): void
    {
        if (! static::isSpendFrozen($ad)) {
            return;
        }

        $anchor = (float) ($ad->daily_spend_anchor ?? 0);

        if (static::hasAnchorColumn() && $anchor >= $metaTodaySpend) {
            return;
        }

        $today = Carbon::today()->toDateString();

        $payload = static::filterPersistablePayload([
            'daily_spend' => 0,
            'spend_date' => $today,
            'daily_spend_anchor' => max(0, $metaTodaySpend),
        ]);

        $ad->update($payload);
        $ad->daily_spend = 0;
        $ad->spend_date = $today;

        if (static::hasAnchorColumn()) {
            $ad->daily_spend_anchor = max(0, $metaTodaySpend);
        }
    }

    public static function beginNewSpendSession(Ad $ad, float $metaTodaySpend): void
    {
        $today = Carbon::today()->toDateString();

        $payload = static::filterPersistablePayload([
            'daily_spend_anchor' => max(0, $metaTodaySpend),
            'daily_spend' => 0,
            'spend_date' => $today,
        ]);

        $ad->update($payload);
        $ad->daily_spend = 0;
        $ad->spend_date = $today;

        if (static::hasAnchorColumn()) {
            $ad->daily_spend_anchor = max(0, $metaTodaySpend);
        }
    }

    /**
     * Today's spend for budget decisions (Meta session + stored daily_spend, whichever is higher).
     */
    public static function dailySessionSpend(Ad $ad, ?float $metaTodaySpend = null): float
    {
        $fromDb = (float) ($ad->daily_spend ?? 0);
        $fromMeta = $metaTodaySpend !== null
            ? static::sessionSpend($ad, $metaTodaySpend)
            : 0;

        return max($fromDb, $fromMeta);
    }

    public static function shouldAutoPauseForBudget(Ad $ad, ?float $metaTodaySpend = null): bool
    {
        $budget = (float) $ad->daily_budget;

        if ($budget <= 0) {
            return false;
        }

        $threshold = max(0, $budget - static::BUDGET_PAUSE_BUFFER);

        return static::dailySessionSpend($ad, $metaTodaySpend) >= $threshold - 0.001;
    }

    /**
     * @return array<string, mixed>
     */
    public static function pausePayload(Ad $ad, string $pauseReason, ?float $metaTodaySpend = null): array
    {
        $today = Carbon::today()->toDateString();
        $anchor = $metaTodaySpend ?? static::dailySessionSpend($ad, $metaTodaySpend);

        return static::filterPersistablePayload([
            'status' => Ad::STATUS_PAUSED,
            'pause_reason' => $pauseReason,
            'daily_spend' => 0,
            'daily_spend_anchor' => max(0, $anchor),
            'spend_date' => $today,
        ]);
    }

    public static function ensurePausedOnMeta(Ad $ad, MetaAdsService $meta): void
    {
        if (! static::isSpendFrozen($ad) || ! $ad->meta_ad_id) {
            return;
        }

        try {
            $response = $meta->updateAd($ad->meta_ad_id, ['status' => 'PAUSED']);

            if (isset($response['error'])) {
                throw new \Exception($response['error']['message'] ?? 'Pause failed');
            }
        } catch (Throwable $e) {
            Log::warning('AD_ENSURE_PAUSED_META_FAILED', [
                'ad_id' => $ad->id,
                'meta_ad_id' => $ad->meta_ad_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function enforce(Ad $ad, MetaAdsService $meta, ?float $metaTodaySpend = null): void
    {
        if (! $ad->meta_ad_id) {
            return;
        }

        if (static::isSpendFrozen($ad)) {
            if ($metaTodaySpend !== null) {
                $anchor = (float) ($ad->daily_spend_anchor ?? 0);

                if ($metaTodaySpend > $anchor + 0.001) {
                    static::ensurePausedOnMeta($ad, $meta);
                    static::reconcilePausedAdSpend($ad, $metaTodaySpend);
                }
            }

            return;
        }

        if (! $ad->daily_budget || $ad->daily_budget <= 0) {
            return;
        }

        if (! static::shouldAutoPauseForBudget($ad, $metaTodaySpend)) {
            return;
        }

        if ($ad->status !== Ad::STATUS_ACTIVE) {
            return;
        }

        try {
            $response = $meta->updateAd($ad->meta_ad_id, ['status' => 'PAUSED']);

            if (isset($response['error'])) {
                throw new \Exception($response['error']['message'] ?? 'Pause failed');
            }

            $payload = static::pausePayload($ad, 'budget_limit', $metaTodaySpend);

            $ad->update($payload);
            $ad->status = Ad::STATUS_PAUSED;
            $ad->pause_reason = 'budget_limit';
            $ad->daily_spend = 0;
            $ad->spend_date = $payload['spend_date'] ?? Carbon::today()->toDateString();

            if (static::hasAnchorColumn()) {
                $ad->daily_spend_anchor = (float) ($payload['daily_spend_anchor'] ?? 0);
            }

            Log::info('AD_AUTO_PAUSED_BUDGET', [
                'ad_id' => $ad->id,
                'meta_ad_id' => $ad->meta_ad_id,
                'daily_budget' => $ad->daily_budget,
                'session_spend' => static::dailySessionSpend($ad, $metaTodaySpend),
            ]);
        } catch (Throwable $e) {
            Log::warning('AD_AUTO_PAUSE_BUDGET_FAILED', [
                'ad_id' => $ad->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function canManualPublish(Ad $ad): bool
    {
        if ((float) $ad->daily_budget <= 0) {
            return true;
        }

        if (static::isBudgetLimitPaused($ad)) {
            return true;
        }

        return ! $ad->hasReachedDailyBudget();
    }

    public static function publishBlockedMessage(Ad $ad): string
    {
        $spent = static::isBudgetLimitPaused($ad)
            ? (float) $ad->daily_budget
            : (float) $ad->daily_spend;

        return sprintf(
            'Daily budget reached ($%s of $%s). Increase the daily budget in Edit, or use Publish to start a new session.',
            number_format($spent, 2),
            number_format((float) $ad->daily_budget, 2)
        );
    }
}
