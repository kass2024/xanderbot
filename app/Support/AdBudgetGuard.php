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
    /** Pause Meta well before budget — Meta can overshoot between 1-min checks. */
    public const BUDGET_PAUSE_BUFFER = 0.40;

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

        $spent = max($fromDb, $fromMeta);
        $budget = (float) $ad->daily_budget;

        if ($budget > 0) {
            return min($spent, $budget);
        }

        return $spent;
    }

    public static function shouldAutoPauseForBudget(Ad $ad, ?float $metaTodaySpend = null): bool
    {
        $budget = (float) $ad->daily_budget;

        if ($budget <= 0) {
            return false;
        }

        $spent = static::dailySessionSpend($ad, $metaTodaySpend);
        $threshold = max(0, $budget - static::BUDGET_PAUSE_BUFFER);

        return $spent >= $threshold - 0.001 || $spent >= $budget - 0.001;
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

    /**
     * Manual pause: stop Meta billing first, then freeze local spend.
     *
     * @throws \Exception
     */
    public static function pauseImmediately(Ad $ad, MetaAdsService $meta, string $pauseReason, ?float $metaTodaySpend = null): void
    {
        if ($ad->meta_ad_id) {
            if (! static::pauseAdOnMeta($meta, $ad, true)) {
                throw new \Exception('Meta refused to pause this ad. Try again or pause in Ads Manager.');
            }
        }

        $payload = static::pausePayload($ad, $pauseReason, $metaTodaySpend);
        $ad->update($payload);
        $ad->status = Ad::STATUS_PAUSED;
        $ad->pause_reason = $pauseReason;
        $ad->daily_spend = 0;

        if (static::hasAnchorColumn()) {
            $ad->daily_spend_anchor = (float) ($payload['daily_spend_anchor'] ?? 0);
        }

        Log::info('AD_PAUSED_IMMEDIATELY', [
            'ad_id' => $ad->id,
            'meta_ad_id' => $ad->meta_ad_id,
            'pause_reason' => $pauseReason,
        ]);
    }

    public static function ensurePausedOnMeta(Ad $ad, MetaAdsService $meta): void
    {
        if (! static::isSpendFrozen($ad) || ! $ad->meta_ad_id) {
            return;
        }

        static::pauseAdOnMeta($meta, $ad, false);
    }

    public static function enforce(Ad $ad, MetaAdsService $meta, ?float $metaTodaySpend = null): void
    {
        if (! $ad->meta_ad_id) {
            return;
        }

        if (static::isSpendFrozen($ad)) {
            static::ensurePausedOnMeta($ad, $meta);

            if ($metaTodaySpend !== null) {
                static::reconcilePausedAdSpend($ad, $metaTodaySpend);
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

        $pausedOnMeta = static::pauseAdOnMeta($meta, $ad, false);

        if (! $pausedOnMeta) {
            static::pauseAdSetOnMetaFallback($ad, $meta);
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
            'meta_pause_ok' => $pausedOnMeta,
        ]);

        if (! $pausedOnMeta) {
            Log::critical('AD_AUTO_PAUSE_META_FAILED_AFTER_LOCAL', [
                'ad_id' => $ad->id,
                'meta_ad_id' => $ad->meta_ad_id,
            ]);
        }
    }

    protected static function pauseAdOnMeta(MetaAdsService $meta, Ad $ad, bool $throwOnFail): bool
    {
        if (! $ad->meta_ad_id) {
            return true;
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $meta->updateAd($ad->meta_ad_id, ['status' => 'PAUSED']);

                if (isset($response['error'])) {
                    throw new \Exception($response['error']['message'] ?? 'Pause failed');
                }

                return true;
            } catch (Throwable $e) {
                Log::warning('AD_PAUSE_META_ATTEMPT_FAILED', [
                    'ad_id' => $ad->id,
                    'meta_ad_id' => $ad->meta_ad_id,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < 3) {
                    usleep(250000 * $attempt);
                }
            }
        }

        if ($throwOnFail) {
            throw new \Exception('Could not pause ad on Meta after 3 attempts.');
        }

        return false;
    }

    protected static function pauseAdSetOnMetaFallback(Ad $ad, MetaAdsService $meta): void
    {
        $ad->loadMissing('adSet');

        $adsetMetaId = (string) ($ad->adSet?->meta_id ?? '');

        if ($adsetMetaId === '') {
            return;
        }

        try {
            $response = $meta->updateAdSet($adsetMetaId, ['status' => 'PAUSED']);

            if (isset($response['error'])) {
                throw new \Exception($response['error']['message'] ?? 'Ad set pause failed');
            }

            Log::warning('AD_BUDGET_PAUSED_ADSET_FALLBACK', [
                'ad_id' => $ad->id,
                'adset_meta_id' => $adsetMetaId,
            ]);
        } catch (Throwable $e) {
            Log::critical('AD_BUDGET_ADSET_PAUSE_FAILED', [
                'ad_id' => $ad->id,
                'adset_meta_id' => $adsetMetaId,
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
