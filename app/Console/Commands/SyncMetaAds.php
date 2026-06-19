<?php

namespace App\Console\Commands;

use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdSet;
use App\Models\Campaign;
use App\Support\MetaDeletedCampaigns;
use App\Models\Creative;
use App\Services\MetaAdsService;
use App\Support\AdBudgetGuard;
use App\Support\MetaRateLimit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncMetaAds extends Command
{
    protected $signature = 'meta:sync-ads
                            {--force : Run even when a Meta rate-limit cooldown is active}';

    protected $description = 'Smart Meta sync (rate-limit safe + billing aware)';

    protected MetaAdsService $meta;

    protected int $maxRetries = 5;

    public function __construct(MetaAdsService $meta)
    {
        parent::__construct();
        $this->meta = $meta;
    }

    public function handle()
    {
        if (! $this->option('force') && MetaRateLimit::isBlocked()) {
            $until = MetaRateLimit::blockedUntil();

            $this->warn(sprintf(
                'Meta API rate limit cooldown active until %s — skipping sync.',
                $until?->toDateTimeString() ?? 'later'
            ));

            return Command::SUCCESS;
        }

        $this->info('Starting Meta Ads Sync...');

        try {
            $account = AdAccount::first();

            if (! $account) {
                $this->error('No Meta account connected');

                return Command::FAILURE;
            }

            $accountId = $account->meta_id;

            $status = $this->safeMetaCall(fn () => $this->meta->getAccountStatus($accountId));

            if (($status['account_status'] ?? 0) != 1) {
                Log::warning('META_ACCOUNT_DISABLED', [
                    'account_id' => $accountId,
                    'status' => $status['account_status'] ?? null,
                ]);

                $this->error('Account disabled (billing issue)');

                return Command::FAILURE;
            }

            $this->pauseBetweenMetaCalls();

            $campaigns = $this->safeMetaCall(fn () => $this->meta->getCampaigns($accountId));

            foreach ($campaigns['data'] ?? [] as $c) {
                $metaId = (string) ($c['id'] ?? '');

                if ($metaId === '' || MetaDeletedCampaigns::contains($metaId)) {
                    continue;
                }

                if (strtoupper((string) ($c['status'] ?? '')) === 'DELETED') {
                    continue;
                }

                Campaign::upsertFromMeta($c, $account->id);
            }

            $campaignMap = Campaign::pluck('id', 'meta_id');

            $this->pauseBetweenMetaCalls();

            $metaAdsets = $this->safeMetaCall(fn () => $this->meta->getAdSets($accountId));

            foreach ($metaAdsets['data'] ?? [] as $a) {
                $campaignId = $campaignMap[$a['campaign_id']] ?? null;

                if (! $campaignId) {
                    continue;
                }

                $budget = isset($a['daily_budget'])
                    ? (int) $a['daily_budget']
                    : null;

                AdSet::updateOrCreate(
                    ['meta_id' => $a['id']],
                    [
                        'campaign_id' => $campaignId,
                        'name' => $a['name'],
                        'status' => $a['status'],
                        'daily_budget' => $budget,
                    ]
                );
            }

            $adsetMap = AdSet::pluck('id', 'meta_id');
            $legacyMetaAdIds = $this->collectLegacyMetaAdIds();

            $this->pauseBetweenMetaCalls();

            $metaAds = $this->safeMetaCall(fn () => $this->meta->getAds($accountId));

            $this->pauseBetweenMetaCalls();

            $lifetimeMap = [];

            try {
                $lifetimeMap = $this->safeMetaCall(fn () => $this->meta->getAdInsightsMap($accountId, 'maximum'));
            } catch (\Throwable $e) {
                if (MetaRateLimit::recordFromMessage($e->getMessage())) {
                    $this->warn('Meta rate limit while fetching lifetime insights — keeping stored lifetime spend.');
                } else {
                    Log::warning('META_SYNC_LIFETIME_INSIGHTS_FAILED', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->pauseBetweenMetaCalls();

            $todayInsights = $this->safeMetaCall(fn () => $this->meta->getInsightsBatch($accountId, 'today'));

            $todayMap = collect($todayInsights['data'] ?? [])
                ->keyBy('ad_id');

            foreach ($metaAds['data'] ?? [] as $metaAd) {
                try {
                    $metaAdId = (string) ($metaAd['id'] ?? '');

                    if ($metaAdId === '') {
                        continue;
                    }

                    if (in_array($metaAdId, $legacyMetaAdIds, true)) {
                        continue;
                    }

                    $adsetId = $adsetMap[$metaAd['adset_id']] ?? null;

                    if (! $adsetId) {
                        continue;
                    }

                    $effective = (string) ($metaAd['effective_status'] ?? '');
                    $review = $metaAd['ad_review_feedback'] ?? null;
                    $reviewText = null;

                    if (is_string($review) && $review !== '') {
                        $reviewText = $review;
                    } elseif (is_array($review) && $review !== []) {
                        $reviewText = json_encode($review, JSON_UNESCAPED_SLASHES);
                    }

                    $pauseReason = null;

                    if ($effective !== '' && strtoupper($effective) !== 'ACTIVE') {
                        $pauseReason = $effective;

                        if ($reviewText) {
                            $pauseReason .= ' — '.$reviewText;
                        }
                    }

                    $existing = Ad::where('meta_ad_id', $metaAdId)->first();

                    $localPayload = [
                        'adset_id' => $adsetId,
                        'name' => $metaAd['name'],
                    ];

                    if (! $existing) {
                        $creativeMetaId = (string) (data_get($metaAd, 'creative.id') ?? '');

                        if ($creativeMetaId !== '') {
                            $creative = Creative::where('meta_id', $creativeMetaId)->first();

                            if ($creative) {
                                $localPayload['creative_id'] = $creative->id;
                            }
                        }

                        if (empty($localPayload['creative_id'])) {
                            Log::info('AD_SYNC_SKIP_ORPHAN', [
                                'meta_ad_id' => $metaAdId,
                                'name' => $metaAd['name'] ?? null,
                            ]);

                            continue;
                        }
                    }

                    $intentionalPause = $existing
                        && $existing->status === Ad::STATUS_PAUSED
                        && in_array($existing->pause_reason, ['manual', 'budget_limit', 'budget'], true);

                    if (! $intentionalPause) {
                        $localPayload['status'] = $metaAd['status'] ?? 'ACTIVE';
                        $localPayload['pause_reason'] = $pauseReason;
                    }

                    $ad = Ad::updateOrCreate(
                        ['meta_ad_id' => $metaAdId],
                        $localPayload
                    );

                    $lifetime = $this->combineInsightRowsForAd($ad, $lifetimeMap);
                    $todayRow = $todayMap[$metaAdId] ?? [];
                    $todaySpend = (float) ($todayRow['spend'] ?? 0);

                    if ($lifetimeMap !== []) {
                        $impressions = max((int) ($ad->impressions ?? 0), (int) ($lifetime['impressions'] ?? 0));
                        $clicks = max((int) ($ad->clicks ?? 0), (int) ($lifetime['clicks'] ?? 0));
                        $spend = max((float) ($ad->spend ?? 0), (float) ($lifetime['spend'] ?? 0));
                    } else {
                        $impressions = (int) ($ad->impressions ?? 0);
                        $clicks = (int) ($ad->clicks ?? 0);
                        $spend = (float) ($ad->spend ?? 0);
                    }

                    $ctr = $impressions > 0
                        ? round(($clicks / $impressions) * 100, 2)
                        : (float) ($ad->ctr ?? 0);

                    $metricsPayload = AdBudgetGuard::metricsPayloadFromMetaToday($ad, $todaySpend);

                    $ad->update(AdBudgetGuard::filterPersistablePayload(array_merge([
                        'impressions' => $impressions,
                        'clicks' => $clicks,
                        'ctr' => $ctr,
                        'spend' => $spend,
                    ], $metricsPayload)));

                    $ad->refresh();
                    AdBudgetGuard::reconcileBudgetLimitPause($ad, $todaySpend);
                } catch (\Throwable $e) {
                    if (MetaRateLimit::recordFromMessage($e->getMessage())) {
                        $this->warn('Meta rate limit hit while syncing ads — stopping early.');

                        break;
                    }

                    Log::error('AD_SYNC_FAILED', [
                        'ad_id' => $metaAd['id'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            MetaRateLimit::clear();

            if ($lifetimeMap !== []) {
                $this->warmInsightsCache($accountId, $lifetimeMap, $todayMap);
            }

            $this->info('Sync completed successfully');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if (MetaRateLimit::recordFromMessage($e->getMessage())) {
                $until = MetaRateLimit::blockedUntil();

                $this->warn(sprintf(
                    'Meta API rate limit reached. Cooldown until %s. Budget enforcement will resume after cooldown.',
                    $until?->toDateTimeString() ?? 'later'
                ));

                return Command::SUCCESS;
            }

            Log::error('META_SYNC_FATAL', [
                'error' => $e->getMessage(),
            ]);

            $this->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function safeMetaCall(callable $callback)
    {
        $attempt = 0;

        start:

        try {
            return $callback();
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            if (! MetaRateLimit::isRateLimitMessage($message)) {
                throw $e;
            }

            $attempt++;

            if ($attempt > $this->maxRetries) {
                MetaRateLimit::block(900);
                Log::error('META_SYNC_MAX_RETRIES', ['attempts' => $attempt]);

                throw $e;
            }

            $delay = min(300, (int) (30 * (2 ** ($attempt - 1))));

            Log::warning('META_SYNC_RATE_LIMIT_RETRY', [
                'attempt' => $attempt,
                'delay_seconds' => $delay,
            ]);

            $this->warn("Meta rate limit — retry {$attempt}/{$this->maxRetries} in {$delay}s...");

            sleep($delay);

            goto start;
        }
    }

    private function pauseBetweenMetaCalls(): void
    {
        usleep(750000);
    }

    /**
     * @param  array<string, array<string, mixed>>  $lifetimeMap
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $todayMap
     */
    private function warmInsightsCache(string $accountId, array $lifetimeMap, $todayMap): void
    {
        try {
            Cache::put(
                'meta_ad_insights_maps:'.md5($accountId),
                [
                    'lifetime' => $lifetimeMap,
                    'today' => $todayMap->all(),
                ],
                now()->addSeconds(30)
            );
        } catch (\Throwable $e) {
            Log::warning('META_SYNC_CACHE_WARM_FAILED', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $map
     * @return array{impressions: int, clicks: int, spend: float}
     */
    private function combineInsightRowsForAd(Ad $ad, array $map): array
    {
        $impressions = 0;
        $clicks = 0;
        $spend = 0.0;

        foreach ($ad->metaIdsForMetrics() as $metaId) {
            if (! isset($map[$metaId])) {
                continue;
            }

            $row = $map[$metaId];
            $impressions += (int) ($row['impressions'] ?? 0);
            $clicks += (int) ($row['clicks'] ?? 0);
            $spend += (float) ($row['spend'] ?? 0);
        }

        return [
            'impressions' => $impressions,
            'clicks' => $clicks,
            'spend' => $spend,
        ];
    }

    /**
     * @return list<string>
     */
    private function collectLegacyMetaAdIds(): array
    {
        return Ad::query()
            ->whereNotNull('previous_meta_ad_ids')
            ->pluck('previous_meta_ad_ids')
            ->filter()
            ->flatMap(fn ($ids) => is_array($ids) ? $ids : [])
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
