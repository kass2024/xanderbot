<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

use App\Services\MetaAdsService;
use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\AdSet;
use App\Models\Ad;

class SyncMetaAds extends Command
{
    protected $signature = 'meta:sync-ads';
    protected $description = 'Meta sync with batch insights + budget enforcement';

    protected MetaAdsService $meta;

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
            | 1️⃣ CAMPAIGNS
            |----------------------------------------------------------
            */
            $campaigns = $this->meta->getCampaigns($accountId);

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
            $metaAdsets = $this->meta->getAdSets($accountId);

            foreach ($metaAdsets['data'] ?? [] as $a) {

                $campaignId = $campaignMap[$a['campaign_id']] ?? null;
                if (!$campaignId) continue;

                $budget = isset($a['daily_budget'])
                    ? ((int)$a['daily_budget']) / 100
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

            /*
            |----------------------------------------------------------
            | 3️⃣ ADS
            |----------------------------------------------------------
            */
            $adsetMap = AdSet::pluck('id', 'meta_id');
            $metaAds = $this->meta->getAds($accountId);

            /*
            |----------------------------------------------------------
            | 🔥 4️⃣ BATCH INSIGHTS (CRITICAL FIX)
            |----------------------------------------------------------
            */
            $insights = $this->meta->getInsightsBatch($accountId);

            $insightMap = collect($insights['data'] ?? [])
                ->keyBy('ad_id');

            foreach ($metaAds['data'] ?? [] as $metaAd) {

                try {

                    $metaAdId = $metaAd['id'];
                    $adsetId = $adsetMap[$metaAd['adset_id']] ?? null;

                    if (!$adsetId) continue;

                    $ad = Ad::firstOrCreate(
                        ['meta_ad_id' => $metaAdId],
                        [
                            'adset_id' => $adsetId,
                            'name' => $metaAd['name'],
                            'status' => 'ACTIVE'
                        ]
                    );

                    /*
                    |--------------------------------------------------
                    | GET INSIGHT FROM BATCH
                    |--------------------------------------------------
                    */
                    $insight = $insightMap[$metaAdId] ?? [];

                    $todaySpend = (float)($insight['spend'] ?? 0);
                    $impressions = (int)($insight['impressions'] ?? 0);
                    $clicks = (int)($insight['clicks'] ?? 0);

                    $ctr = $impressions > 0
                        ? round(($clicks / $impressions) * 100, 2)
                        : 0;

                    /*
                    |--------------------------------------------------
                    | 🔥 BUDGET GUARD
                    |--------------------------------------------------
                    */
                    $status = $ad->status;
                    $pauseReason = $ad->pause_reason;

                    Log::info('BUDGET_CHECK', [
                        'ad' => $metaAdId,
                        'spend' => $todaySpend,
                        'budget' => $ad->daily_budget,
                        'status' => $status
                    ]);

                    if ($ad->daily_budget) {

                        // 🔴 PAUSE
                        if ($todaySpend >= $ad->daily_budget) {

                            if ($ad->pause_reason !== 'budget') {

                                Log::warning('AUTO_PAUSE', [
                                    'ad' => $metaAdId
                                ]);

                                $this->safeMetaCall(function () use ($metaAdId) {
                                    return $this->meta->updateAd($metaAdId, [
                                        'status' => 'PAUSED'
                                    ]);
                                });

                                $status = 'PAUSED';
                                $pauseReason = 'budget';
                            }

                        } else {

                            // 🟢 RESUME
                            if ($ad->pause_reason === 'budget') {

                                Log::info('AUTO_RESUME', [
                                    'ad' => $metaAdId
                                ]);

                                $this->safeMetaCall(function () use ($metaAdId) {
                                    return $this->meta->updateAd($metaAdId, [
                                        'status' => 'ACTIVE'
                                    ]);
                                });

                                $status = 'ACTIVE';
                                $pauseReason = null;
                            }
                        }
                    }

                    /*
                    |--------------------------------------------------
                    | SAVE
                    |--------------------------------------------------
                    */
                    $ad->update([
                        'status' => $status,
                        'pause_reason' => $pauseReason,

                        'impressions' => $impressions,
                        'clicks' => $clicks,
                        'ctr' => $ctr,

                        'daily_spend' => $todaySpend,
                        'spend_date' => Carbon::today()->toDateString()
                    ]);

                } catch (\Throwable $e) {

                    Log::error('AD_SYNC_FAILED', [
                        'ad_id' => $metaAd['id'] ?? null,
                        'error' => $e->getMessage()
                    ]);
                }

                // 🔒 Throttle (Meta safe)
                usleep(200000);
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
    | 🔥 SAFE META CALL (RATE LIMIT PROTECTION)
    |--------------------------------------------------------------
    */
    private function safeMetaCall(callable $callback)
    {
        try {
            return $callback();

        } catch (\Throwable $e) {

            if (str_contains($e->getMessage(), 'limit')) {

                Log::warning('RATE_LIMIT_HIT - RETRYING...');
                sleep(2);

                return $callback();
            }

            throw $e;
        }
    }
}