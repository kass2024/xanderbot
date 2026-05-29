<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

use App\Services\MetaAdsService;
use App\Support\AdBudgetGuard;
use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\AdSet;
use App\Models\Ad;
use App\Models\Creative;

class SyncMetaAds extends Command
{
    protected $signature = 'meta:sync-ads';
    protected $description = 'Smart Meta sync (rate-limit safe + billing aware)';

    protected MetaAdsService $meta;

    protected int $maxRetries = 3;

    public function __construct(MetaAdsService $meta)
    {
        parent::__construct();
        $this->meta = $meta;
    }

    public function handle()
    {
        $this->info('🚀 Starting Meta Ads Sync...');

        try {

            $account = AdAccount::first();

            if (!$account) {
                $this->error('No Meta account connected');
                return Command::FAILURE;
            }

            $accountId = $account->meta_id;

            /*
            |----------------------------------------------------------
            | 🔴 CHECK ACCOUNT STATUS (CRITICAL)
            |----------------------------------------------------------
            */
            $status = $this->safeMetaCall(fn() =>
                $this->meta->getAccountStatus($accountId)
            );

            if (($status['account_status'] ?? 0) != 1) {

                Log::warning('META_ACCOUNT_DISABLED', [
                    'account_id' => $accountId,
                    'status' => $status['account_status'] ?? null
                ]);

                $this->error('Account disabled (billing issue)');
                return Command::FAILURE;
            }

            /*
            |----------------------------------------------------------
            | 1️⃣ CAMPAIGNS
            |----------------------------------------------------------
            */
            $campaigns = $this->safeMetaCall(fn() =>
                $this->meta->getCampaigns($accountId)
            );

            foreach ($campaigns['data'] ?? [] as $c) {

                Campaign::updateOrCreate(
                    ['meta_id' => $c['id']],
                    [
                        'ad_account_id' => $account->id,
                        'name' => $c['name'],
                        'status' => $c['status'],
                        'objective' => $c['objective'] ?? null
                    ]
                );
            }

            $campaignMap = Campaign::pluck('id', 'meta_id');

            /*
            |----------------------------------------------------------
            | 2️⃣ ADSETS
            |----------------------------------------------------------
            */
            $metaAdsets = $this->safeMetaCall(fn() =>
                $this->meta->getAdSets($accountId)
            );

            foreach ($metaAdsets['data'] ?? [] as $a) {

                $campaignId = $campaignMap[$a['campaign_id']] ?? null;
                if (!$campaignId) continue;

                $budget = isset($a['daily_budget'])
                    ? (int) $a['daily_budget']
                    : null;

                AdSet::updateOrCreate(
                    ['meta_id' => $a['id']],
                    [
                        'campaign_id' => $campaignId,
                        'name' => $a['name'],
                        'status' => $a['status'],
                        'daily_budget' => $budget
                    ]
                );
            }

            $adsetMap = AdSet::pluck('id', 'meta_id');

            $legacyMetaAdIds = $this->collectLegacyMetaAdIds();

            /*
            |----------------------------------------------------------
            | 3️⃣ ADS
            |----------------------------------------------------------
            */
            $metaAds = $this->safeMetaCall(fn() =>
                $this->meta->getAds($accountId)
            );

            /*
            |----------------------------------------------------------
            | 4️⃣ INSIGHTS (BATCH)
            |----------------------------------------------------------
            */
            $insights = $this->safeMetaCall(fn() =>
                $this->meta->getInsightsBatch($accountId)
            );

            $insightMap = collect($insights['data'] ?? [])
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

                    if (!$adsetId) continue;

                    /*
                    |----------------------------------------------
                    | 🔥 ALWAYS UPDATE (NO STALE DATA)
                    |----------------------------------------------
                    */
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
                            $pauseReason .= ' — ' . $reviewText;
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

                    $insight = $insightMap[$metaAdId] ?? [];

                    $todaySpend = (float)($insight['spend'] ?? 0);
                    $impressions = (int)($insight['impressions'] ?? 0);
                    $clicks = (int)($insight['clicks'] ?? 0);

                    $ctr = $impressions > 0
                        ? round(($clicks / $impressions) * 100, 2)
                        : 0;

                    $metricsPayload = AdBudgetGuard::metricsPayloadFromMetaToday($ad, $todaySpend);

                    $ad->update(AdBudgetGuard::filterPersistablePayload(array_merge([
                        'impressions' => $impressions,
                        'clicks' => $clicks,
                        'ctr' => $ctr,
                        'spend' => (float) ($insight['spend'] ?? $todaySpend),
                    ], $metricsPayload)));

                    $ad->refresh();
                    AdBudgetGuard::reconcileBudgetLimitPause($ad, $todaySpend);
                    AdBudgetGuard::enforce($ad, $this->meta, $todaySpend);

                } catch (\Throwable $e) {

                    Log::error('AD_SYNC_FAILED', [
                        'ad_id' => $metaAd['id'] ?? null,
                        'error' => $e->getMessage()
                    ]);
                }

                /*
                |----------------------------------------------
                | 🔒 SMART THROTTLE (RANDOM)
                |----------------------------------------------
                */
                usleep(rand(700000, 1200000));
            }

            $this->info('✅ Sync completed successfully');
            return Command::SUCCESS;

        } catch (\Throwable $e) {

            Log::error('META_SYNC_FATAL', [
                'error' => $e->getMessage()
            ]);

            $this->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    /*
    |--------------------------------------------------------------
    | 🔥 SAFE META CALL WITH RETRY + BACKOFF
    |--------------------------------------------------------------
    */
    private function safeMetaCall(callable $callback)
    {
        $attempt = 0;

        start:

        try {
            return $callback();

        } catch (\Throwable $e) {

            $attempt++;

            $message = $e->getMessage();

            if (
                str_contains($message, '"code":17') ||
                str_contains($message, 'rate limit') ||
                str_contains($message, 'User request limit')
            ) {

                if ($attempt <= $this->maxRetries) {

                    $delay = pow(2, $attempt);

                    Log::warning("RATE LIMIT - RETRY {$attempt} in {$delay}s");

                    sleep($delay);

                    goto start;
                }

                Log::error('MAX RETRIES REACHED');
            }

            throw $e;
        }
    }

    /**
     * Meta ad ids superseded by IG reprovision — do not import as separate local ads.
     *
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