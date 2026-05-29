<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\AdAccount;
use App\Support\AdBudgetGuard;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AdBudgetEnforcementService
{
    public function __construct(
        protected MetaAdsService $meta,
    ) {}

    /**
     * Fetch live Meta today spend and enforce per-ad budgets (pause on Meta first).
     *
     * @return array{checked: int, paused: int, re_paused: int, errors: int}
     */
    public function enforceAll(): array
    {
        $stats = ['checked' => 0, 'paused' => 0, 're_paused' => 0, 'errors' => 0];

        $ads = Ad::query()
            ->whereNotNull('meta_ad_id')
            ->get();

        if ($ads->isEmpty()) {
            return $stats;
        }

        $todayMaps = $this->todayInsightsMapsByAccount($ads);

        foreach ($ads as $ad) {
            $stats['checked']++;

            try {
                $accountId = $this->resolveAccountIdForAd($ad);
                $todayMap = $accountId ? ($todayMaps[$accountId] ?? []) : [];
                $todayRow = $this->todayRowForAd($ad, $todayMap);
                $metaTodaySpend = (float) ($todayRow['spend'] ?? 0);

                $wasActive = $ad->status === Ad::STATUS_ACTIVE;

                if ($todayRow !== null && Schema::hasColumn('ads', 'daily_spend')) {
                    $metrics = AdBudgetGuard::metricsPayloadFromMetaToday($ad, $metaTodaySpend);
                    $ad->update(AdBudgetGuard::filterPersistablePayload(
                        array_intersect_key($metrics, array_flip($ad->getFillable()))
                    ));
                    $ad->refresh();
                }

                AdBudgetGuard::enforce($ad, $this->meta, $metaTodaySpend);
                $ad->refresh();

                if ($wasActive && $ad->status === Ad::STATUS_PAUSED && AdBudgetGuard::isBudgetLimitPaused($ad)) {
                    $stats['paused']++;
                } elseif ($ad->status === Ad::STATUS_PAUSED && ! $wasActive) {
                    $stats['re_paused']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                Log::error('AD_BUDGET_ENFORCE_FAILED', [
                    'ad_id' => $ad->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * @param  iterable<Ad>  $ads
     * @return array<string, array<string, array<string, mixed>>>
     */
    protected function todayInsightsMapsByAccount(iterable $ads): array
    {
        $accountIds = [];

        foreach ($ads as $ad) {
            $id = $this->resolveAccountIdForAd($ad);

            if ($id) {
                $accountIds[$id] = true;
            }
        }

        $maps = [];

        foreach (array_keys($accountIds) as $accountId) {
            try {
                $maps[$accountId] = $this->meta->getAdInsightsMap($accountId, 'today');
            } catch (Throwable $e) {
                Log::warning('AD_BUDGET_TODAY_INSIGHTS_FAILED', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
                $maps[$accountId] = [];
            }
        }

        return $maps;
    }

    protected function resolveAccountIdForAd(Ad $ad): ?string
    {
        $ad->loadMissing('adSet.campaign.adAccount');

        $fromAd = $ad->adSet?->campaign?->adAccount?->meta_id;

        if ($fromAd) {
            return (string) $fromAd;
        }

        $account = AdAccount::query()->first();

        return $account?->meta_id ? (string) $account->meta_id : null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $map
     * @return array<string, mixed>|null
     */
    protected function todayRowForAd(Ad $ad, array $map): ?array
    {
        $impressions = 0;
        $clicks = 0;
        $spend = 0.0;
        $found = false;

        foreach ($ad->metaIdsForMetrics() as $metaId) {
            if (! isset($map[$metaId])) {
                continue;
            }

            $found = true;
            $row = $map[$metaId];
            $impressions += (int) ($row['impressions'] ?? 0);
            $clicks += (int) ($row['clicks'] ?? 0);
            $spend += (float) ($row['spend'] ?? 0);
        }

        if (! $found) {
            return null;
        }

        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'spend' => $spend,
        ];
    }
}
