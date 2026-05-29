<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdSet;
use App\Models\Creative;
use App\Services\InstagramDeliveryService;
use App\Services\MetaAdsService;
use App\Support\AdBudgetGuard;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use Throwable;
use Exception;

class AdController extends Controller
{
    protected MetaAdsService $meta;

    protected InstagramDeliveryService $instagramDelivery;

    public function __construct(MetaAdsService $meta, InstagramDeliveryService $instagramDelivery)
    {
        $this->meta = $meta;
        $this->instagramDelivery = $instagramDelivery;
    }

    protected function resolveMetaAccountId(): ?string
    {
        $fromDb = AdAccount::query()->whereNotNull('meta_id')->value('meta_id');

        if ($fromDb) {
            return (string) $fromDb;
        }

        $fromConfig = config('services.meta.ad_account_id');

        return $fromConfig ? (string) $fromConfig : null;
    }

    /**
     * @return array{lifetime: array<string, array<string, mixed>>, today: array<string, array<string, mixed>>}
     */
    /**
     * Cache is optional — file cache permission errors must not break Resync or live refresh.
     */
    protected function cacheRemember(string $key, int $seconds, callable $callback): mixed
    {
        try {
            return Cache::remember($key, now()->addSeconds($seconds), $callback);
        } catch (Throwable $e) {
            Log::warning('CACHE_REMEMBER_FAILED', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return $callback();
        }
    }

    protected function cacheGet(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::get($key, $default);
        } catch (Throwable $e) {
            Log::warning('CACHE_GET_FAILED', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }
    }

    protected function cacheForget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable $e) {
            Log::warning('CACHE_FORGET_FAILED', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function metaInsightsMaps(string $accountId): array
    {
        $cacheKey = 'meta_ad_insights_maps:'.md5($accountId);

        return $this->cacheRemember($cacheKey, 30, function () use ($accountId) {
            return [
                'lifetime' => $this->meta->getAdInsightsMap($accountId, 'maximum'),
                'today' => $this->meta->getAdInsightsMap($accountId, 'today'),
            ];
        });
    }

    /**
     * @return array<string, array<string, array{impressions: int, clicks: int, spend: float}>>
     */
    protected function placementInsightsMap(string $accountId, string $preset = 'maximum'): array
    {
        $cacheKey = 'meta_ad_placement_maps:'.md5($accountId.':'.$preset);

        return $this->cacheRemember($cacheKey, 60, function () use ($accountId, $preset) {
            return $this->meta->getAdPlacementInsightsMap($accountId, $preset);
        });
    }

    /**
     * Lifetime placement (all Meta ad ids) + today's Instagram on the active Meta ad id.
     *
     * @param  array<string, array<string, array{impressions: int, clicks: int, spend: float}>>  $maximumMap
     * @param  array<string, array<string, array{impressions: int, clicks: int, spend: float}>>  $todayMap
     * @return array<string, array{impressions: int, clicks: int, spend: float}>
     */
    protected function mergePlacementMapsForAd(Ad $ad, array $maximumMap, array $todayMap): array
    {
        $merged = $this->mergedPlacementDeliveryForAd($ad, $maximumMap);
        $todayAll = $this->mergedPlacementDeliveryForAd($ad, $todayMap);
        $currentId = (string) ($ad->meta_ad_id ?? '');
        $todayCurrent = ($currentId !== '' && isset($todayMap[$currentId]) && is_array($todayMap[$currentId]))
            ? $todayMap[$currentId]
            : [];

        foreach (['instagram', 'facebook'] as $platform) {
            $candidates = [
                $merged[$platform] ?? null,
                $todayAll[$platform] ?? null,
                $todayCurrent[$platform] ?? null,
            ];

            $impressions = 0;
            $clicks = 0;
            $spend = 0.0;

            foreach ($candidates as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $impressions = max($impressions, (int) ($row['impressions'] ?? 0));
                $clicks = max($clicks, (int) ($row['clicks'] ?? 0));
                $spend = max($spend, (float) ($row['spend'] ?? 0));
            }

            if ($impressions > 0 || $clicks > 0 || $spend > 0.00001) {
                $merged[$platform] = [
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'spend' => $spend,
                ];
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, array<string, array{impressions: int, clicks: int, spend: float}>>  $placementMap
     */
    protected function applyPlacementDeliveryToAds(iterable $ads, array $placementMap): void
    {
        foreach ($ads as $ad) {
            $ad->setAttribute('placement_delivery', $this->mergedPlacementDeliveryForAd($ad, $placementMap));
        }
    }

    /**
     * @return list<string>
     */
    protected function metaAdIdsForMetrics(Ad $ad): array
    {
        $ids = [];

        if (! empty($ad->meta_ad_id)) {
            $ids[] = (string) $ad->meta_ad_id;
        }

        foreach (is_array($ad->previous_meta_ad_ids) ? $ad->previous_meta_ad_ids : [] as $legacyId) {
            $legacyId = (string) $legacyId;

            if ($legacyId !== '' && ! in_array($legacyId, $ids, true)) {
                $ids[] = $legacyId;
            }
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{impressions: int, clicks: int, spend: float, ctr: float}|null
     */
    protected function combineInsightRows(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $impressions = 0;
        $clicks = 0;
        $spend = 0.0;

        foreach ($rows as $row) {
            $impressions += (int) ($row['impressions'] ?? 0);
            $clicks += (int) ($row['clicks'] ?? 0);
            $spend += $this->parseInsightMetric($row, 'spend');
        }

        if ($impressions === 0 && $clicks === 0 && $spend <= 0.00001) {
            return null;
        }

        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'spend' => $spend,
            'ctr' => $impressions > 0
                ? round(($clicks / $impressions) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $map
     * @return array<string, mixed>|null
     */
    protected function insightsRowForAdFromMap(Ad $ad, array $map): ?array
    {
        $rows = [];

        foreach ($this->metaAdIdsForMetrics($ad) as $metaId) {
            if (isset($map[$metaId])) {
                $rows[] = $map[$metaId];
            }
        }

        return $this->combineInsightRows(array_map(fn (array $row) => [
            'impressions' => (int) ($row['impressions'] ?? 0),
            'clicks' => (int) ($row['clicks'] ?? 0),
            'spend' => $this->parseInsightMetric($row, 'spend'),
        ], $rows));
    }

    /**
     * @param  array<string, array<string, array{impressions: int, clicks: int, spend: float}>>  $placementMap
     * @return array<string, array{impressions: int, clicks: int, spend: float}>
     */
    protected function mergedPlacementDeliveryForAd(Ad $ad, array $placementMap): array
    {
        $merged = [];

        foreach ($this->metaAdIdsForMetrics($ad) as $metaId) {
            foreach ($placementMap[$metaId] ?? [] as $platform => $row) {
                if (! isset($merged[$platform])) {
                    $merged[$platform] = [
                        'impressions' => 0,
                        'clicks' => 0,
                        'spend' => 0.0,
                    ];
                }

                $merged[$platform]['impressions'] += (int) ($row['impressions'] ?? 0);
                $merged[$platform]['clicks'] += (int) ($row['clicks'] ?? 0);
                $merged[$platform]['spend'] += (float) ($row['spend'] ?? 0);
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, array<string, mixed>>  $lifetimeMap
     * @param  list<array<string, mixed>>|null  $accountAds
     * @return list<string>
     */
    protected function discoverPreviousMetaAdIds(Ad $ad, array $lifetimeMap, ?array $accountAds = null): array
    {
        $existing = is_array($ad->previous_meta_ad_ids) ? $ad->previous_meta_ad_ids : [];

        $ad->loadMissing('adSet');

        $adsetMetaId = (string) ($ad->adSet?->meta_id ?? '');
        $currentId = (string) ($ad->meta_ad_id ?? '');

        if ($adsetMetaId === '' || $currentId === '') {
            return array_values(array_unique($existing));
        }

        if ($accountAds === null) {
            $accountId = $this->resolveMetaAccountIdForAd($ad);
            $accountAds = $accountId ? $this->meta->listAccountAds($accountId) : [];
        }

        $discovered = [];

        foreach ($accountAds as $metaAd) {
            $id = (string) ($metaAd['id'] ?? '');

            if ($id === '' || $id === $currentId) {
                continue;
            }

            if ((string) ($metaAd['adset_id'] ?? '') !== $adsetMetaId) {
                continue;
            }

            $status = strtoupper((string) ($metaAd['status'] ?? $metaAd['effective_status'] ?? ''));

            if (! in_array($status, ['PAUSED', 'ARCHIVED', 'DELETED'], true)) {
                continue;
            }

            $row = $lifetimeMap[$id] ?? null;
            $imp = (int) ($row['impressions'] ?? 0);
            $spend = (float) ($row['spend'] ?? 0);

            if ($imp > 0 || $spend > 0.00001) {
                $discovered[] = $id;
            }
        }

        return array_values(array_unique(array_merge($existing, $discovered)));
    }

    /**
     * @param  array<string, array<string, mixed>>  $lifetimeMap
     * @param  array<string, list<array<string, mixed>>>  $accountAdsCache
     */
    protected function ensurePreviousMetaAdIdsStored(Ad $ad, array $lifetimeMap, array &$accountAdsCache): void
    {
        if (! Schema::hasColumn('ads', 'previous_meta_ad_ids')) {
            return;
        }

        $combined = $this->insightsRowForAdFromMap($ad, $lifetimeMap);
        $storedImp = (int) ($ad->impressions ?? 0);
        $storedSpend = (float) ($ad->spend ?? 0);
        $combinedImp = (int) ($combined['impressions'] ?? 0);

        $needsDiscover = $storedImp < 50 && $storedSpend < 0.5
            && $combinedImp < max(50, $storedImp + 10);

        if (! $needsDiscover && ! empty($ad->previous_meta_ad_ids)) {
            return;
        }

        $accountId = $this->resolveMetaAccountIdForAd($ad);

        if (! $accountId) {
            return;
        }

        if (! isset($accountAdsCache[$accountId])) {
            try {
                $accountAdsCache[$accountId] = $this->meta->listAccountAds($accountId);
            } catch (Throwable $e) {
                Log::warning('META_LIST_ADS_FAILED', ['error' => $e->getMessage()]);
                $accountAdsCache[$accountId] = [];
            }
        }

        $discovered = $this->discoverPreviousMetaAdIds($ad, $lifetimeMap, $accountAdsCache[$accountId]);

        if ($discovered === (is_array($ad->previous_meta_ad_ids) ? $ad->previous_meta_ad_ids : [])) {
            return;
        }

        $ad->previous_meta_ad_ids = $discovered;
        $ad->save();
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPlacementPayloadForAd(Ad $ad): array
    {
        $delivery = is_array($ad->getAttribute('placement_delivery'))
            ? $ad->getAttribute('placement_delivery')
            : [];

        return $this->instagramDelivery->buildPlacementPayloadForAd($ad, $delivery);
    }

    protected function parseInsightMetric(array $row, string $key): float
    {
        return (float) ($row[$key] ?? 0);
    }

    /**
     * @return array{impressions: int, clicks: int, spend: float}|null
     */
    protected function fetchMetaLifetimeMetrics(Ad $ad): ?array
    {
        if ($this->metaAdIdsForMetrics($ad) === []) {
            return null;
        }

        $accountId = $this->resolveMetaAccountIdForAd($ad);

        if ($accountId) {
            try {
                $map = $this->meta->getAdInsightsMap($accountId, 'maximum');
                $row = $this->insightsRowForAdFromMap($ad, $map);

                if ($row !== null) {
                    return [
                        'impressions' => (int) $row['impressions'],
                        'clicks' => (int) $row['clicks'],
                        'spend' => (float) $row['spend'],
                    ];
                }
            } catch (Throwable $e) {
                Log::warning('META_LIFETIME_MAP_FAILED', [
                    'ad_id' => $ad->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $rows = [];

        foreach ($this->metaAdIdsForMetrics($ad) as $metaId) {
            try {
                $base = $this->meta->getInsights($metaId, 'maximum');

                if ($base !== []) {
                    $rows[] = [
                        'impressions' => (int) ($base['impressions'] ?? 0),
                        'clicks' => (int) ($base['clicks'] ?? 0),
                        'spend' => $this->parseInsightMetric($base, 'spend'),
                    ];
                }
            } catch (Throwable $e) {
                Log::warning('META_LIFETIME_METRICS_FAILED', [
                    'ad_id' => $ad->id,
                    'meta_ad_id' => $metaId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $combined = $this->combineInsightRows($rows);

        if ($combined === null) {
            return null;
        }

        return [
            'impressions' => (int) $combined['impressions'],
            'clicks' => (int) $combined['clicks'],
            'spend' => (float) $combined['spend'],
        ];
    }

    /**
     * Metrics for display — never lower than values already stored locally.
     *
     * @param  array{impressions: int, clicks: int, spend: float}|null  $meta
     * @return array{impressions: int, clicks: int, spend: float, ctr: float, source: string}
     */
    protected function mergeMetricsForDisplay(Ad $ad, ?array $meta): array
    {
        $impressions = (int) ($ad->impressions ?? 0);
        $clicks = (int) ($ad->clicks ?? 0);
        $spend = (float) ($ad->spend ?? 0);
        $source = 'database';

        if ($meta !== null) {
            $impressions = max($impressions, (int) $meta['impressions']);
            $clicks = max($clicks, (int) $meta['clicks']);
            $spend = max($spend, (float) $meta['spend']);
            $source = 'meta';
        }

        $ctr = $impressions > 0
            ? round(($clicks / $impressions) * 100, 2)
            : (float) ($ad->ctr ?? 0);

        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'spend' => $spend,
            'ctr' => $ctr,
            'source' => $source,
        ];
    }

    /**
     * Persist Meta metrics without wiping local totals (e.g. after ad reprovision).
     *
     * @param  array<string, mixed>|null  $lifetimeRow
     * @param  array<string, mixed>|null  $todayRow
     */
    protected function persistAdMetricsFromMeta(Ad $ad, ?array $lifetimeRow, ?array $todayRow = null, bool $enforceBudget = true): void
    {
        if (! $lifetimeRow && ! $todayRow) {
            return;
        }

        $metaImpressions = (int) ($lifetimeRow['impressions'] ?? $todayRow['impressions'] ?? 0);
        $metaClicks = (int) ($lifetimeRow['clicks'] ?? $todayRow['clicks'] ?? 0);
        $metaSpend = $this->parseInsightMetric($lifetimeRow ?? [], 'spend');
        if ($metaSpend <= 0 && $todayRow) {
            $metaSpend = $this->parseInsightMetric($todayRow, 'spend');
        }

        $hadLocal = ((int) ($ad->impressions ?? 0)) > 0
            || ((float) ($ad->spend ?? 0)) > 0.00001;
        $metaHasActivity = $metaImpressions > 0 || $metaClicks > 0 || $metaSpend > 0.00001;

        if ($hadLocal && ! $metaHasActivity) {
            if ($enforceBudget) {
                AdBudgetGuard::enforce($ad, $this->meta, null);
            }

            return;
        }

        $impressions = max((int) ($ad->impressions ?? 0), $metaImpressions);
        $clicks = max((int) ($ad->clicks ?? 0), $metaClicks);
        $spend = max((float) ($ad->spend ?? 0), $metaSpend);
        $ctr = $impressions > 0
            ? round(($clicks / $impressions) * 100, 2)
            : (float) ($lifetimeRow['ctr'] ?? $todayRow['ctr'] ?? 0);

        $metaTodaySpend = $todayRow
            ? $this->parseInsightMetric($todayRow, 'spend')
            : 0;

        $payload = [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'spend' => $spend,
            'ctr' => $ctr,
        ];

        if (Schema::hasColumn('ads', 'spend_date')) {
            $payload = array_merge($payload, AdBudgetGuard::metricsPayloadFromMetaToday($ad, $metaTodaySpend));
        } elseif (Schema::hasColumn('ads', 'daily_spend')) {
            $payload['daily_spend'] = AdBudgetGuard::isSpendFrozen($ad)
                ? 0
                : AdBudgetGuard::cappedSessionSpend($ad, $metaTodaySpend);
        }

        $ad->update(AdBudgetGuard::filterPersistablePayload(
            array_intersect_key($payload, array_flip($ad->getFillable()))
        ));

        $ad->impressions = $impressions;
        $ad->clicks = $clicks;
        $ad->spend = $spend;
        $ad->ctr = $ctr;
        $ad->daily_spend = (float) ($payload['daily_spend'] ?? 0);
        if (isset($payload['spend_date'])) {
            $ad->spend_date = $payload['spend_date'];
        }
        if (isset($payload['daily_spend_anchor'])) {
            $ad->daily_spend_anchor = (float) $payload['daily_spend_anchor'];
        }

        AdBudgetGuard::reconcileBudgetLimitPause($ad, $metaTodaySpend);

        if ($enforceBudget) {
            AdBudgetGuard::enforce($ad, $this->meta, $metaTodaySpend);
        }
    }

    protected function enforceDailyBudgetsOnAds(iterable $ads): void
    {
        foreach ($ads as $ad) {
            if ($ad->status === Ad::STATUS_PAUSED && $ad->meta_ad_id) {
                AdBudgetGuard::ensurePausedOnMeta($ad, $this->meta);
            }
        }
    }

    protected function applyMetaInsightsToAds(iterable $ads, array $lifetime, array $today, bool $enforceBudget = true): void
    {
        foreach ($ads as $ad) {
            $this->persistAdMetricsFromMeta(
                $ad,
                $this->insightsRowForAdFromMap($ad, $lifetime),
                $this->insightsRowForAdFromMap($ad, $today),
                $enforceBudget
            );
        }
    }

    /**
     * @param  iterable<Ad>  $ads
     * @return array{updated: int, discovered: int, skipped: int, rows: list<array<string, mixed>>}
     */
    public function resyncMetricsFromMeta(iterable $ads, bool $discover = true): array
    {
        $ads = collect($ads)->filter(fn (Ad $ad) => $ad->meta_ad_id);

        $stats = ['updated' => 0, 'discovered' => 0, 'skipped' => 0, 'rows' => []];

        if ($ads->isEmpty()) {
            return $stats;
        }

        $byAccount = [];

        foreach ($ads as $ad) {
            $accountId = $this->resolveMetaAccountIdForAd($ad) ?? $this->resolveMetaAccountId();

            if ($accountId) {
                $byAccount[$accountId][] = $ad;
            }
        }

        foreach ($byAccount as $accountId => $group) {
            $this->cacheForget('meta_ad_insights_maps:'.md5($accountId));
            $this->cacheForget('meta_ad_placement_maps:'.md5($accountId.':maximum'));
            $this->cacheForget('meta_ad_placement_maps:'.md5($accountId.':today'));

            $maps = $this->metaInsightsMaps($accountId);
            $lifetime = $maps['lifetime'] ?? [];
            $today = $maps['today'] ?? [];
            $accountAdsCache = [];

            foreach ($group as $ad) {
                if ($discover) {
                    $before = is_array($ad->previous_meta_ad_ids) ? count($ad->previous_meta_ad_ids) : 0;
                    $this->ensurePreviousMetaAdIdsStored($ad, $lifetime, $accountAdsCache);
                    $after = is_array($ad->previous_meta_ad_ids) ? count($ad->previous_meta_ad_ids) : 0;

                    if ($after > $before) {
                        $stats['discovered']++;
                    }
                }

                $lifetimeRow = $this->insightsRowForAdFromMap($ad, $lifetime);
                $todayRow = $this->insightsRowForAdFromMap($ad, $today);

                if ($lifetimeRow === null && $todayRow === null) {
                    $stats['skipped']++;

                    continue;
                }

                $this->persistAdMetricsFromMeta($ad, $lifetimeRow, $todayRow, false);
                $ad->refresh();

                $stats['updated']++;
                $stats['rows'][] = [
                    'id' => $ad->id,
                    'name' => $ad->name,
                    'impressions' => (int) $ad->impressions,
                    'spend' => (float) $ad->spend,
                    'meta_ids' => $this->metaAdIdsForMetrics($ad),
                ];
            }

            try {
                $this->hydratePlacementDeliveryFromMeta($group);
            } catch (Throwable $e) {
                Log::warning('ADS_RESYNC_PLACEMENT_FAILED', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    protected function hydrateLiveMetricsFromMeta(iterable $ads, bool $cacheOnly = false, bool $enforceBudget = true): bool
    {
        $ads = collect($ads)->filter(fn (Ad $ad) => $ad->meta_ad_id);

        if ($ads->isEmpty()) {
            return true;
        }

        $accountId = $this->resolveMetaAccountId();

        if (! $accountId) {
            return true;
        }

        try {
            $cacheKey = 'meta_ad_insights_maps:'.md5($accountId);

            if ($cacheOnly) {
                $maps = $this->cacheGet($cacheKey);

                if (! is_array($maps) || empty($maps['lifetime'])) {
                    return false;
                }
            } else {
                $maps = $this->metaInsightsMaps($accountId);
            }

            $lifetime = $maps['lifetime'] ?? [];
            $accountAdsCache = [];

            foreach ($ads as $ad) {
                $this->ensurePreviousMetaAdIdsStored($ad, $lifetime, $accountAdsCache);
            }

            $this->applyMetaInsightsToAds(
                $ads,
                $lifetime,
                $maps['today'] ?? [],
                $enforceBudget,
            );

            if ($enforceBudget) {
                $this->enforceDailyBudgetsOnAds($ads);
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('ADS_LIVE_METRICS_REFRESH_FAILED', [
                'error' => $e->getMessage(),
            ]);

            if ($enforceBudget) {
                $this->enforceDailyBudgetsOnAds($ads);
            }

            return false;
        }
    }

    protected function adsMetricsQuery()
    {
        return Ad::query()
            ->with(['adSet:id,name,campaign_id,targeting'])
            ->select($this->adsSelectColumns())
            ->latest();
    }

    protected function adsListQuery()
    {
        return Ad::query()
            ->with([
                'creative:id,name,image_url,json_payload',
                'adSet:id,name,campaign_id,targeting',
                'adSet.campaign:id,name,ad_account_id',
                'adSet.campaign.adAccount:id,name,meta_id',
            ])
            ->select($this->adsSelectColumns())
            ->latest();
    }

    protected function adsSelectColumns(): array
    {
        return array_values(array_filter([
            'id',
            'name',
            'adset_id',
            'creative_id',
            'meta_ad_id',
            Schema::hasColumn('ads', 'previous_meta_ad_ids') ? 'previous_meta_ad_ids' : null,
            'status',
            'impressions',
            'clicks',
            Schema::hasColumn('ads', 'ctr') ? 'ctr' : null,
            'spend',
            Schema::hasColumn('ads', 'daily_budget') ? 'daily_budget' : null,
            Schema::hasColumn('ads', 'daily_spend') ? 'daily_spend' : null,
            Schema::hasColumn('ads', 'daily_spend_anchor') ? 'daily_spend_anchor' : null,
            Schema::hasColumn('ads', 'pause_reason') ? 'pause_reason' : null,
            Schema::hasColumn('ads', 'spend_date') ? 'spend_date' : null,
            Schema::hasColumn('ads', 'instagram_enabled_at') ? 'instagram_enabled_at' : null,
            'created_at',
        ]));
    }

    protected function buildAdsMetrics(iterable $ads, ?int $totalAds = null): array
    {
        $collection = collect($ads);

        return [
            'total_ads' => $totalAds ?? $collection->count(),
            'active_ads' => $collection->where('status', 'ACTIVE')->count(),
            'total_spend' => $collection->sum('spend'),
            'total_clicks' => $collection->sum('clicks'),
            'total_impressions' => $collection->sum('impressions'),
            'avg_ctr' => $collection->avg('ctr'),
        ];
    }

    protected function formatAdForLiveJson(Ad $ad): array
    {
        $placement = $this->buildPlacementPayloadForAd($ad);

        return array_merge([
            'id' => $ad->id,
            'name' => $ad->name,
            'adset_id' => $ad->adset_id,
            'creative_id' => $ad->creative_id,
            'meta_ad_id' => $ad->meta_ad_id,
            'status' => $ad->status,
            'impressions' => (int) ($ad->impressions ?? 0),
            'clicks' => (int) ($ad->clicks ?? 0),
            'ctr' => (float) ($ad->ctr ?? 0),
            'spend' => (float) ($ad->spend ?? 0),
            'daily_spend' => $ad->displayDailySpend(),
            'daily_budget' => (float) ($ad->daily_budget ?? 0),
            'pause_reason' => $ad->pause_reason ?? null,
            'enable_instagram_url' => route('admin.ads.enable-instagram', $ad),
            'instagram_impressions' => (int) ($placement['instagram_impressions'] ?? 0),
        ], [
            'placement' => $placement,
        ]);
    }

    protected function resolveMetaAccountIdForAd(Ad $ad): ?string
    {
        $ad->loadMissing('adSet.campaign.adAccount');

        $fromAd = $ad->adSet?->campaign?->adAccount?->meta_id;

        return $fromAd ? (string) $fromAd : $this->resolveMetaAccountId();
    }

    protected function hydratePlacementDeliveryFromMeta(iterable $ads): void
    {
        $byAccount = [];

        foreach ($ads as $ad) {
            $accountId = $this->resolveMetaAccountIdForAd($ad);

            if (! $accountId) {
                continue;
            }

            $byAccount[$accountId][] = $ad;
        }

        foreach ($byAccount as $accountId => $group) {
            try {
                $maximumMap = $this->placementInsightsMap($accountId, 'maximum');
                $todayMap = $this->placementInsightsMap($accountId, 'today');

                foreach ($group as $ad) {
                    $ad->setAttribute(
                        'placement_delivery',
                        $this->mergePlacementMapsForAd($ad, $maximumMap, $todayMap)
                    );
                }
            } catch (Throwable $e) {
                Log::warning('ADS_PLACEMENT_INSIGHTS_FAILED', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | LIST ADS
    |--------------------------------------------------------------------------
    */
    public function index(): View
    {
        $ads = $this->adsListQuery()->paginate(20);

        $allAds = $this->adsMetricsQuery()->get();
        $this->hydrateLiveMetricsFromMeta($allAds, false, true);
        $this->enforceDailyBudgetsOnAds($allAds);
        $this->hydratePlacementDeliveryFromMeta($allAds);

        $freshMap = $allAds->keyBy('id');
        $ads->setCollection(
            $ads->getCollection()->map(function (Ad $ad) use ($freshMap) {
                $ad = $freshMap->get($ad->id, $ad);
                $ad->setAttribute('placement', $this->buildPlacementPayloadForAd($ad));

                return $ad;
            })
        );

        $metrics = $this->buildAdsMetrics($allAds);

        return view('admin.ads.index', [
            'ads' => $ads,
            'metrics' => $metrics,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE FORM
    |--------------------------------------------------------------------------
    */

    public function create(): View
    {
        $adsets = AdSet::with('campaign.adAccount')
            ->latest()
            ->get();

        $creatives = Creative::latest()->get();

        return view('admin.ads.create', compact('adsets','creatives'));
    }

    /*
    |--------------------------------------------------------------------------
    | STORE AD
    |--------------------------------------------------------------------------
    */

    public function store(Request $request): RedirectResponse
    {
    $data = $request->validate([
'name' => 'required|string|max:255',
'adset_id' => 'required|exists:ad_sets,id',
'creative_id' => 'required|exists:creatives,id',
'daily_budget' => 'required|numeric|min:0.10',
'status' => 'required|in:ACTIVE,PAUSED'
]);

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | LOAD MODELS
            |--------------------------------------------------------------------------
            */

            $adset = AdSet::with('campaign.adAccount')
                ->findOrFail($data['adset_id']);

           #$creative = Creative::where('meta_id',$data['creative_id'])->firstOrFail();
           $creative = Creative::findOrFail($data['creative_id']);

            $campaign = $adset->campaign;

            $adAccount = $campaign->adAccount ?? null;


            /*
            |--------------------------------------------------------------------------
            | VALIDATE META SYNC
            |--------------------------------------------------------------------------
            */

            if (!$adset->meta_id) {
                throw new Exception('AdSet not synced with Meta.');
            }

            if (!$creative->meta_id) {
                throw new Exception('Creative not synced with Meta.');
            }

            if (!$adAccount || !$adAccount->meta_id) {
                throw new Exception('Meta Ad Account not connected.');
            }

            $this->assertCreativeEligibleForMetaAd($creative, $adset);

            /*
            |--------------------------------------------------------------------------
            | INSTAGRAM DELIVERY (Page + IG actor on ad set & creative before Meta ad)
            |--------------------------------------------------------------------------
            */
            $this->instagramDelivery->assertInstagramConfigured();
            $this->instagramDelivery->repairAdSet($adset, true);
            $this->instagramDelivery->repairCreative($creative, true);
            $creative->refresh();

            /*
            |--------------------------------------------------------------------------
            | PREVENT DUPLICATE ADS
            |--------------------------------------------------------------------------
            */

            $exists = Ad::where('adset_id',$adset->id)
                ->where('creative_id',$creative->id)
                ->first();

            if ($exists) {
                throw new Exception('Ad already exists for this AdSet + Creative.');
            }


            /*
            |--------------------------------------------------------------------------
            | FORMAT ACCOUNT ID
            |--------------------------------------------------------------------------
            */

            $accountId = $adAccount->meta_id;

            if (!str_starts_with($accountId,'act_')) {
                $accountId = 'act_'.$accountId;
            }


          /*
/*
|--------------------------------------------------------------------------
| META PAYLOAD
|--------------------------------------------------------------------------
| Prepare the payload to create the Ad in Meta.
| The creative meta_id is passed as "id" and converted by MetaAdsService
| to the required format: creative={"creative_id":"..."}
*/

$payload = [

    // Ad name in Meta
    'name' => $data['name'],

    // Meta AdSet ID (not local id)
    'adset_id' => $adset->meta_id,

    // Attach existing Meta creative (Meta expects creative_id only here)
    'creative' => [
        'id' => $creative->meta_id,
    ],

    // Delivery status (default paused for safety)
    'status' => $data['status'] ?? 'PAUSED'

];

Log::info('META_AD_CREATE_REQUEST', [
    'account_id' => $accountId,
    'adset_meta_id' => $adset->meta_id,
    'creative_meta_id' => $creative->meta_id,
    'payload' => $payload,
]);

            $response = $this->createMetaAdWithLinkFallbacks($accountId, $payload, $creative, $adset);

            Log::info('META_AD_CREATE_RESPONSE', $response);


            if (!isset($response['id'])) {

                $error = $response['error']['message']
                    ?? 'Meta API failed creating ad';

                throw new Exception($error);
            }


            /*
            |--------------------------------------------------------------------------
            | SAVE LOCAL AD
            |--------------------------------------------------------------------------
            */
$ad = Ad::create([
'adset_id' => $adset->id,
'creative_id' => $creative->id,
'meta_ad_id' => $response['id'],

'name' => $data['name'],
'status' => $data['status'],

'daily_budget' => $request->input('daily_budget', 2),
'daily_spend' => 0
]);

            DB::commit();

            try {
                $this->instagramDelivery->repairAd($ad->fresh(['creative', 'adSet.campaign.adAccount']), false, false);
            } catch (Throwable $igError) {
                Log::warning('AD_CREATE_IG_ATTACH_FAILED', [
                    'ad_id' => $ad->id,
                    'error' => $igError->getMessage(),
                ]);
            }

            Log::info('META_AD_CREATED', [

                'local_ad_id' => $ad->id,

                'meta_ad_id' => $response['id']

            ]);


            return redirect()
                ->route('admin.ads.index')
                ->with('success','Ad created and synced to Meta with Instagram delivery enabled.');

        }

        catch (Throwable $e) {

            DB::rollBack();

            $message = $e->getMessage();

            if ($e instanceof RequestException && $e->response) {
                $decoded = $e->response->json();
                if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
                    $err = $decoded['error'];
                    $parts = array_filter([
                        $err['error_user_title'] ?? null,
                        $err['error_user_msg'] ?? null,
                        $err['message'] ?? null,
                        isset($err['error_subcode']) ? '(Meta subcode '.$err['error_subcode'].')' : null,
                    ]);
                    if ($parts !== []) {
                        $message = implode(' — ', $parts);
                    }
                }
            }

            Log::error('AD_CREATION_FAILED', [

                'error' => $message,

            ]);

            return back()
                ->withInput()
                ->withErrors([
                    'meta' => 'Ad creation failed: '.$message,
                ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | ADS BY ADSET (AJAX)
    |--------------------------------------------------------------------------
    */

    public function byAdset(int $adsetId): JsonResponse
    {
        $ads = Ad::where('adset_id',$adsetId)
            ->latest()
            ->get([
                'id',
                'name',
                'status',
                'impressions',
                'clicks',
                'spend'
            ]);

        return response()->json($ads);
    }


    /*
    |--------------------------------------------------------------------------
    | SHOW (resource route) → preview dashboard
    |--------------------------------------------------------------------------
    */
    public function show(Ad $ad): RedirectResponse
    {
        return redirect()->route('admin.ads.preview', $ad);
    }

    /*
    |--------------------------------------------------------------------------
    | PREVIEW CREATIVE
    |--------------------------------------------------------------------------
    */
    public function preview(Ad $ad): View
    {
        $ad->load(['creative', 'adSet', 'adSet.campaign']);

        $metrics = $this->mergeMetricsForDisplay($ad, null);
        $audience = ['countries' => [], 'age' => [], 'gender' => []];
        $devices = [];
        $placementRows = [];
        $insightsError = null;
        $refreshedAt = now();

        if (! empty($ad->meta_ad_id)) {
            try {
                $metrics = $this->mergeMetricsForDisplay($ad, $this->fetchMetaLifetimeMetrics($ad));

                foreach ($this->meta->getInsights((string) $ad->meta_ad_id, 'maximum', ['breakdowns' => 'country']) as $row) {
                    $country = $row['country'] ?? 'Unknown';
                    $audience['countries'][$country] = ($audience['countries'][$country] ?? 0) + (int) ($row['impressions'] ?? 0);
                }

                foreach ($this->meta->getInsights((string) $ad->meta_ad_id, 'maximum', ['breakdowns' => 'age,gender']) as $row) {
                    if (! empty($row['age'])) {
                        $age = $row['age'];
                        $audience['age'][$age] = ($audience['age'][$age] ?? 0) + (int) ($row['impressions'] ?? 0);
                    }
                    if (! empty($row['gender'])) {
                        $gender = $row['gender'];
                        $audience['gender'][$gender] = ($audience['gender'][$gender] ?? 0) + (int) ($row['impressions'] ?? 0);
                    }
                }

                foreach ($this->meta->getInsights((string) $ad->meta_ad_id, 'maximum', ['breakdowns' => 'device_platform']) as $row) {
                    $device = $row['device_platform'] ?? 'Unknown';
                    $devices[$device] = [
                        'device' => $device,
                        'impressions' => ($devices[$device]['impressions'] ?? 0) + (int) ($row['impressions'] ?? 0),
                        'clicks' => ($devices[$device]['clicks'] ?? 0) + (int) ($row['clicks'] ?? 0),
                    ];
                }

                foreach ($this->meta->getAdPlatformBreakdown((string) $ad->meta_ad_id, 'maximum') as $row) {
                    $platform = $row['publisher_platform'] ?? 'unknown';
                    $placementRows[] = [
                        'placement' => ucfirst(str_replace('_', ' ', (string) $platform)),
                        'platform_key' => $platform,
                        'impressions' => (int) ($row['impressions'] ?? 0),
                        'clicks' => (int) ($row['clicks'] ?? 0),
                        'spend' => (float) ($row['spend'] ?? 0),
                    ];
                }

                arsort($audience['countries']);
                arsort($audience['age']);
                arsort($audience['gender']);
            } catch (Throwable $e) {
                $insightsError = $e->getMessage();
                Log::warning('AD_INSIGHTS_PREVIEW_FETCH_FAILED', [
                    'ad_id' => $ad->id,
                    'meta_ad_id' => $ad->meta_ad_id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            $insightsError = 'Ad is not synced with Meta.';
        }

        $placementDelivery = [];
        if (! empty($ad->meta_ad_id)) {
            try {
                $accountId = $this->resolveMetaAccountIdForAd($ad);
                if ($accountId) {
                    $map = $this->meta->getAdPlacementInsightsMap($accountId, 'maximum');
                    $placementDelivery = $this->mergedPlacementDeliveryForAd($ad, $map);
                }
            } catch (Throwable $e) {
                Log::warning('PREVIEW_PLACEMENT_FETCH_FAILED', ['error' => $e->getMessage()]);
            }
        }

        $igDelivery = $this->instagramDelivery->auditAdDelivery($ad, $placementDelivery, false, true);

        if ($placementRows === []) {
            foreach (['facebook', 'instagram', 'messenger', 'audience_network'] as $platform) {
                if (! isset($placementDelivery[$platform])) {
                    continue;
                }
                $row = $placementDelivery[$platform];
                $placementRows[] = [
                    'placement' => ucfirst(str_replace('_', ' ', $platform)),
                    'platform_key' => $platform,
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'spend' => (float) ($row['spend'] ?? 0),
                ];
            }
        }

        return view('admin.ads.preview', [
            'ad' => $ad,
            'metrics' => $metrics,
            'audience' => $audience,
            'devices' => array_values($devices),
            'placements' => $placementRows,
            'igDelivery' => $igDelivery,
            'insightsError' => $insightsError,
            'refreshedAt' => $refreshedAt,
        ]);
    }
/*
|--------------------------------------------------------------------------
| UPDATE STATUS
|--------------------------------------------------------------------------
*/
public function updateStatus(Request $request, Ad $ad): RedirectResponse
{
    $data = $request->validate([
        'status' => 'required|in:ACTIVE,PAUSED,ARCHIVED'
    ]);

    try {

        if ($data['status'] === 'PAUSED') {

            $metaTodaySpend = null;

            if ($ad->meta_ad_id) {
                try {
                    $todayInsights = $this->meta->getInsights($ad->meta_ad_id, 'today');
                    $metaTodaySpend = (float) ($todayInsights['spend'] ?? 0);
                } catch (Throwable $e) {
                    Log::warning('AD_STATUS_PAUSE_INSIGHTS_FAILED', [
                        'ad_id' => $ad->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            AdBudgetGuard::pauseImmediately($ad, $this->meta, 'manual', $metaTodaySpend);

            return back()->with('success', 'Ad paused on Meta — billing stopped.');
        }

        if ($data['status'] === 'ACTIVE') {
            return back()->withErrors([
                'status' => 'Auto-resume is disabled. Use Publish on the Ads list to resume delivery.',
            ]);
        }

        /*
        |------------------------------------------------------------------
        | Update on Meta (if synced)
        |------------------------------------------------------------------
        */
        if ($ad->meta_ad_id) {

            $this->meta->updateAd(
                $ad->meta_ad_id,
                [
                    'status' => $data['status']
                ]
            );
        }

        /*
        |------------------------------------------------------------------
        | Determine pause reason
        |------------------------------------------------------------------
        */
        $pauseReason = $ad->pause_reason; // keep existing by default

        if ($data['status'] === 'ARCHIVED') {

            // Archived = no pause logic needed
            $pauseReason = null;
        }

        /*
        |------------------------------------------------------------------
        | Update local DB
        |------------------------------------------------------------------
        */
        $ad->update([
            'status' => $data['status'],
            'pause_reason' => $pauseReason ?? null
        ]);

        return back()->with('success', 'Ad status updated.');

    } catch (\Throwable $e) {

        Log::error('AD_STATUS_UPDATE_FAILED', [
            'ad_id' => $ad->id,
            'status' => $data['status'] ?? null,
            'error' => $e->getMessage()
        ]);

        return back()->withErrors([
            'meta' => 'Unable to update ad status.'
        ]);
    }
}
    /*
    |--------------------------------------------------------------------------
    | DELETE AD
    |--------------------------------------------------------------------------
    */

    public function destroy(Ad $ad): RedirectResponse
    {
        try {

            if ($ad->meta_ad_id) {

                $this->meta->deleteAd($ad->meta_ad_id);

            }

            $ad->delete();

            return back()->with('success','Ad deleted.');

        }

        catch (Throwable $e) {

            Log::error('AD_DELETE_FAILED',[
                'error'=>$e->getMessage()
            ]);

            return back()->withErrors([
                'meta'=>'Unable to delete ad'
            ]);
        }
    }
    public function edit(Ad $ad): View
{
    $adsets = AdSet::with('campaign')->latest()->get();
    $creatives = Creative::latest()->get();

    return view('admin.ads.edit', [
        'ad' => $ad,
        'adsets' => $adsets,
        'creatives' => $creatives
    ]);
}
public function update(Request $request, Ad $ad): RedirectResponse
{
    $data = $request->validate([
        'name' => 'required|string|max:255',
        'adset_id' => 'required|exists:ad_sets,id',
        'creative_id' => 'required|exists:creatives,id',
        'daily_budget' => 'required|numeric|min:1',
        'status' => 'required|in:ACTIVE,PAUSED,ARCHIVED'
    ]);

    try {

        /*
        |--------------------------------------------------------------------------
        | UPDATE META AD (only name/status allowed)
        |--------------------------------------------------------------------------
        */

        if ($ad->meta_ad_id) {

            $metaPayload = ['name' => $data['name']];

            if ($data['status'] !== 'ACTIVE') {
                $metaPayload['status'] = $data['status'];
            }

            $this->meta->updateAd($ad->meta_ad_id, $metaPayload);

        }

        $localPayload = [
            'name' => $data['name'],
            'adset_id' => $data['adset_id'],
            'creative_id' => $data['creative_id'],
            'daily_budget' => $data['daily_budget'],
        ];

        if ($data['status'] === 'ACTIVE' && $ad->status !== Ad::STATUS_ACTIVE) {
            return back()->withErrors([
                'status' => 'Auto-resume is disabled. Use Publish on the Ads list to resume delivery.',
            ])->withInput();
        }

        if ($data['status'] === 'PAUSED') {
            $metaTodaySpend = null;

            if ($ad->meta_ad_id) {
                try {
                    $todayInsights = $this->meta->getInsights($ad->meta_ad_id, 'today');
                    $metaTodaySpend = (float) ($todayInsights['spend'] ?? 0);
                } catch (Throwable $e) {
                    Log::warning('AD_UPDATE_PAUSE_INSIGHTS_FAILED', [
                        'ad_id' => $ad->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            AdBudgetGuard::pauseImmediately($ad, $this->meta, 'manual', $metaTodaySpend);
            $ad->update($localPayload);
        } elseif ($data['status'] === 'ACTIVE') {
            $ad->update(array_merge($localPayload, [
                'status' => Ad::STATUS_ACTIVE,
                'pause_reason' => null,
            ]));
        } else {
            $ad->update(array_merge($localPayload, [
                'status' => $data['status'],
                'pause_reason' => null,
            ]));
        }

        return redirect()
            ->route('admin.ads.index')
            ->with('success','Ad updated successfully.');

    }

    catch(Throwable $e){

        Log::error('AD_UPDATE_FAILED',[
            'error'=>$e->getMessage()
        ]);

        return back()->withErrors([
            'update'=>'Failed to update Ad'
        ]);
    }
}
public function activate(Ad $ad): RedirectResponse
{
    try {

        if (AdBudgetGuard::requiresPublishToResume($ad)) {
            return back()->withErrors([
                'activate' => 'Use Publish to resume this ad and start a new spend session.',
            ]);
        }

        if (! AdBudgetGuard::canManualPublish($ad)) {
            return back()->withErrors([
                'activate' => AdBudgetGuard::publishBlockedMessage($ad),
            ]);
        }

        if ($ad->meta_ad_id) {

            $this->meta->updateAd(
                $ad->meta_ad_id,
                ['status'=>'ACTIVE']
            );

        }

        $ad->update([
            'status'=>'ACTIVE',
            'pause_reason'=>null
        ]);

        return back()->with('success','Ad activated.');

    } catch(Throwable $e){

        Log::error('AD_ACTIVATE_FAILED',[
            'error'=>$e->getMessage()
        ]);

        return back()->withErrors([
            'activate'=>'Failed to activate ad'
        ]);
    }
}
public function pause(Ad $ad): RedirectResponse
{
    try {

        $metaTodaySpend = null;

        if ($ad->meta_ad_id) {
            try {
                $todayInsights = $this->meta->getInsights($ad->meta_ad_id, 'today');
                $metaTodaySpend = (float) ($todayInsights['spend'] ?? 0);
            } catch (Throwable $e) {
                Log::warning('AD_PAUSE_TODAY_INSIGHTS_FAILED', [
                    'ad_id' => $ad->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        AdBudgetGuard::pauseImmediately($ad, $this->meta, 'manual', $metaTodaySpend);

        return back()->with('success','Ad paused on Meta — billing stopped.');

    } catch(Throwable $e){

        Log::error('AD_MANUAL_PAUSE_FAILED',[
            'error'=>$e->getMessage()
        ]);

        return back()->withErrors([
            'pause'=>'Failed to pause ad: '.$e->getMessage()
        ]);
    }
}
public function duplicate(Ad $ad): RedirectResponse
{
    $copy = $ad->replicate();

    $copy->name = $ad->name.' Copy';

    $copy->meta_ad_id = null;

    $copy->impressions = 0;
    $copy->clicks = 0;
    $copy->spend = 0;
    $copy->ctr = 0;

    $copy->status = 'PAUSED';

    $copy->save();

    return back()->with('success','Ad duplicated.');
}
/**
 * Patch ad set placements + swap in an Instagram-enabled creative on Meta.
 */
public function enableInstagram(Ad $ad): RedirectResponse
{
    try {
        $accountId = $this->resolveMetaAccountIdForAd($ad);
        $todayMap = $accountId
            ? $this->meta->getAdPlacementInsightsMap($accountId, 'today')
            : [];
        $reprovision = $this->instagramDelivery->adNeedsMetaReprovision($ad, $todayMap);

        $this->instagramDelivery->repairAd($ad, true, $reprovision);
        $ad->refresh();
        $this->instagramDelivery->clearInsightsCaches($this->resolveMetaAccountIdForAd($ad));

        $message = $reprovision
            ? 'New Meta ad created for Instagram on this legacy ad set (old Meta ad paused). Platforms should show IG enabled; IG live after new spend (24–48h).'
            : 'Instagram enabled on Meta for this ad. Platforms column should show “IG enabled” — IG live impressions can take a few hours.';

        return back()->with('success', $message);
    } catch (Throwable $e) {
        Log::error('AD_ENABLE_INSTAGRAM_FAILED', [
            'ad_id' => $ad->id,
            'error' => $e->getMessage(),
        ]);

        return back()->withErrors([
            'enable_instagram' => $e->getMessage(),
        ]);
    }
}

/**
 * Enable Instagram on all existing campaigns (ad sets), creatives, and ads on Meta.
 */
public function enableInstagramAll(): RedirectResponse
{
    try {
        $stats = $this->instagramDelivery->repairAll(true, true);
        $this->instagramDelivery->clearInsightsCaches($this->resolveMetaAccountId());

        if ($stats['ads']['updated'] === 0 && $stats['errors'] !== []) {
            return back()->withErrors([
                'enable_instagram' => implode(' | ', array_slice($stats['errors'], 0, 3)),
            ]);
        }

        return back()->with(
            'success',
            $this->instagramDelivery->summaryMessage($stats)
                .' Legacy campaigns: new Meta ads were created where needed; old ads paused. IG impressions may take 24–48h on new spend.'
        );
    } catch (Throwable $e) {
        Log::error('AD_ENABLE_INSTAGRAM_ALL_FAILED', ['error' => $e->getMessage()]);

        return back()->withErrors([
            'enable_instagram' => $e->getMessage(),
        ]);
    }
}

public function ensureBrandPagesAll(): RedirectResponse
{
    try {
        $stats = $this->instagramDelivery->ensureBrandPageDeliveryAll(false);
        $this->instagramDelivery->clearInsightsCaches($this->resolveMetaAccountId());

        if (($stats['ads']['updated'] ?? 0) === 0 && ($stats['creatives']['updated'] ?? 0) === 0
            && ($stats['adsets']['updated'] ?? 0) === 0 && ($stats['errors'] ?? []) !== []) {
            return back()->withErrors([
                'pages' => implode(' | ', array_slice($stats['errors'], 0, 3)),
            ]);
        }

        return back()->with('success', $this->instagramDelivery->brandPageDeliverySummary($stats));
    } catch (Throwable $e) {
        Log::error('ADS_ENSURE_BRAND_PAGES_FAILED', ['error' => $e->getMessage()]);

        return back()->withErrors([
            'pages' => $e->getMessage(),
        ]);
    }
}

public function resyncMetricsAll(): RedirectResponse
{
    try {
        $ads = Ad::query()->whereNotNull('meta_ad_id')->get();
        $stats = $this->resyncMetricsFromMeta($ads, true);

        return back()->with(
            'success',
            'Restored metrics for '.$stats['updated'].' ad(s) from Meta'
                .($stats['discovered'] > 0 ? ' ('.$stats['discovered'].' linked to previous Meta ad ids).' : '.')
                .' Includes spend on paused ads before IG reprovision.'
        );
    } catch (Throwable $e) {
        Log::error('ADS_RESYNC_ALL_FAILED', ['error' => $e->getMessage()]);

        return back()->withErrors([
            'sync' => $e->getMessage(),
        ]);
    }
}

public function sync(Ad $ad): RedirectResponse
{
    if (!$ad->meta_ad_id) {
        return back()->withErrors([
            'sync' => 'Ad not synced with Meta'
        ]);
    }

    try {
        $stats = $this->resyncMetricsFromMeta(collect([$ad]), true);

        if (($stats['updated'] ?? 0) === 0) {
            return back()->withErrors([
                'sync' => 'No Meta insights found for this ad (current or previous Meta ad ids).',
            ]);
        }

        $ids = implode(', ', $stats['rows'][0]['meta_ids'] ?? []);

        return back()->with(
            'success',
            'Metrics restored from Meta using ad id(s): '.$ids.'. Totals include paused ads from before IG reprovision.'
        );
    }

    catch (Throwable $e) {

        Log::error('AD_SYNC_FAILED',[
            'error'=>$e->getMessage()
        ]);

        return back()->withErrors([
            'sync'=>$e->getMessage()
        ]);
    }
}
public function createFromAdSet(AdSet $adset): View
{
    $creatives = Creative::latest()->get();

    return view('admin.ads.create', [
        'adsets' => collect([$adset]),
        'creatives' => $creatives,
        'selectedAdSet' => $adset->id
    ]);
}
public function bulkStatusUpdate(Request $request): RedirectResponse
{
    $data = $request->validate([
        'ids' => 'required|array',
        'status' => 'required|in:ACTIVE,PAUSED'
    ]);

    Ad::whereIn('id',$data['ids'])
        ->update(['status'=>$data['status']]);

    return back()->with('success','Ads updated.');
}
/*
|--------------------------------------------------------------------------
| PUBLISH AD
|--------------------------------------------------------------------------
*/
public function publish(Ad $ad): RedirectResponse
{
    try {

        /*
        |------------------------------------------------------------------
        | Load Required Relations
        |------------------------------------------------------------------
        */
        $ad->load([
            'creative',
            'adSet.campaign.adAccount'
        ]);

        /*
        |------------------------------------------------------------------
        | Validate Required Data
        |------------------------------------------------------------------
        */
        if (!$ad->meta_ad_id) {
            throw new Exception('Ad is not synced with Meta.');
        }

        if (!$ad->adSet || !$ad->adSet->meta_id) {
            throw new Exception('AdSet not synced with Meta.');
        }

        if (!$ad->creative || !$ad->creative->meta_id) {
            throw new Exception('Creative not synced with Meta.');
        }

        if ($this->instagramDelivery->adNeedsBrandPageRepair($ad)) {
            $this->instagramDelivery->repairAd($ad, true, false);
            $ad->refresh();
        }

        if (! AdBudgetGuard::canManualPublish($ad)) {
            throw new Exception(AdBudgetGuard::publishBlockedMessage($ad));
        }

        $todayInsights = $this->meta->getInsights($ad->meta_ad_id, 'today');
        $metaTodaySpend = (float) ($todayInsights['spend'] ?? 0);

        AdBudgetGuard::beginNewSpendSession($ad, $metaTodaySpend);

        Log::info('META_AD_PUBLISH_REQUEST', [
            'ad_id' => $ad->id,
            'meta_ad_id' => $ad->meta_ad_id,
            'meta_today_spend' => $metaTodaySpend,
        ]);

        $response = $this->meta->updateAd(
            $ad->meta_ad_id,
            ['status' => 'ACTIVE']
        );

        Log::info('META_AD_PUBLISH_RESPONSE', [
            'ad_id' => $ad->id,
            'response' => $response
        ]);

        if (isset($response['error'])) {
            throw new Exception(
                $response['error']['message'] ?? 'Meta API error'
            );
        }

        $ad->update([
            'status' => 'ACTIVE',
            'pause_reason' => null,
        ]);

        Log::info('AD_PUBLISHED_SUCCESS', [
            'ad_id' => $ad->id,
            'meta_ad_id' => $ad->meta_ad_id
        ]);

        return back()->with('success', 'Ad successfully published.');

    } catch (Throwable $e) {

        Log::error('AD_PUBLISH_FAILED', [
            'ad_id' => $ad->id ?? null,
            'meta_ad_id' => $ad->meta_ad_id ?? null,
            'error' => $e->getMessage()
        ]);

        return back()->withErrors([
            'publish' => 'Publish failed: ' . $e->getMessage()
        ]);
    }
}
    public function live(): JsonResponse
    {
        try {
            $ads = $this->adsMetricsQuery()->get();
            $metaSynced = $this->hydrateLiveMetricsFromMeta($ads, true, true);

            if (! $metaSynced) {
                $metaSynced = $this->hydrateLiveMetricsFromMeta($ads, false, true);
            }

            $this->hydratePlacementDeliveryFromMeta($ads);

            $metrics = $this->buildAdsMetrics($ads);

            return response()
                ->json([
                    'metrics' => $metrics,
                    'ads' => $ads->map(fn (Ad $ad) => $this->formatAdForLiveJson($ad))->values(),
                    'refreshed_at' => now()->toIso8601String(),
                    'meta_synced' => $metaSynced,
                ])
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        } catch (Throwable $e) {
            Log::error('ADS_LIVE_ENDPOINT_FAILED', [
                'error' => $e->getMessage(),
            ]);

            try {
                $ads = $this->adsMetricsQuery()->get();
                $metrics = $this->buildAdsMetrics($ads);

                return response()->json([
                    'metrics' => $metrics,
                    'ads' => $ads->map(fn (Ad $ad) => $this->formatAdForLiveJson($ad))->values(),
                    'refreshed_at' => now()->toIso8601String(),
                    'meta_synced' => false,
                    'warning' => 'Showing saved metrics. Meta sync will retry automatically.',
                ]);
            } catch (Throwable $fallbackError) {
                return response()->json([
                    'metrics' => [
                        'total_ads' => 0,
                        'active_ads' => 0,
                        'total_spend' => 0,
                        'total_clicks' => 0,
                    ],
                    'ads' => [],
                    'refreshed_at' => now()->toIso8601String(),
                    'meta_synced' => false,
                    'error' => 'Live refresh unavailable.',
                ], 500);
            }
        }
    }

    /**
     * Meta subcode 1815520: invalid/missing link for LINK_CLICKS, LANDING_PAGE_VIEWS, etc.
     * Creatives must belong to the same ad account as the ad set.
     */
    private function assertCreativeEligibleForMetaAd(Creative $creative, AdSet $adset): void
    {
        $creative->loadMissing('campaign');
        $adset->loadMissing('campaign');

        if (!$creative->campaign || !$adset->campaign) {
            throw new Exception('Creative and ad set must both be linked to a campaign.');
        }

        if ((int) $creative->campaign->ad_account_id !== (int) $adset->campaign->ad_account_id) {
            throw new Exception(
                'This creative belongs to a different Meta ad account than the selected ad set. Choose a creative from the same campaign, or recreate the creative under that ad account.'
            );
        }

        $goal = strtoupper((string) ($adset->optimization_goal ?? ''));

        $goalsRequiringHttpsWebsite = [
            'LINK_CLICKS',
            'LANDING_PAGE_VIEWS',
            'OFFSITE_CONVERSIONS',
        ];

        if (!in_array($goal, $goalsRequiringHttpsWebsite, true)) {
            return;
        }

        $url = trim((string) ($creative->destination_url ?? ''));
        if ($url === '') {
            throw new Exception(
                'This ad set optimizes for website visits. Add a destination URL on the creative, then create the ad again.'
            );
        }
    }

    /**
     * Create ad on Meta (no conversion_domain). Retries URL variants + inline creative spec.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createMetaAdWithLinkFallbacks(
        string $accountId,
        array $payload,
        Creative $creative,
        AdSet $adset
    ): array {
        $strategies = [];
        $urls = $this->meta->landingUrlCandidates((string) ($creative->destination_url ?? ''));

        // Prefer IG-enabled creatives first (plain creative_id often delivers Facebook-only).
        foreach ($urls as $url) {
            try {
                $newId = $this->instagramDelivery->createInstagramCreativeOnMeta($accountId, $creative, $adset, $url);
                $strategies[] = [
                    'label' => 'fresh_creative:'.$url,
                    'payload' => array_merge($payload, [
                        'creative' => ['id' => $newId],
                    ]),
                ];
            } catch (Throwable $e) {
                Log::warning('META_CREATIVE_RECREATE_SKIPPED', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($urls as $url) {
            $strategies[] = [
                'label' => 'inline_spec:'.$url,
                'payload' => array_merge($payload, [
                    'creative' => [
                        'spec' => $this->instagramDelivery->buildInlineLinkCreativeSpec($creative, $adset, $url),
                    ],
                ]),
            ];
        }

        $strategies[] = ['label' => 'creative_id', 'payload' => $payload];

        $lastError = null;

        foreach ($strategies as $strategy) {
            try {
                Log::info('META_AD_CREATE_ATTEMPT', [
                    'strategy' => $strategy['label'],
                ]);

                return $this->meta->createAd($accountId, $strategy['payload']);
            } catch (Throwable $e) {
                $lastError = $e;
                Log::warning('META_AD_CREATE_ATTEMPT_FAILED', [
                    'strategy' => $strategy['label'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw $lastError ?? new Exception('Meta ad creation failed.');
    }

    private function extractMetaSubcode(Throwable $e): ?int
    {
        $msg = $e->getMessage();
        if (preg_match('/Meta subcode\\s+(\\d+)/i', $msg, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/error_subcode["\']?\\s*[:=]\\s*(\\d+)/i', $msg, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}