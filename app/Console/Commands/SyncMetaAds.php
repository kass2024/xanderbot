<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

use App\Services\MetaAdsService;
use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\AdSet;
use App\Models\Ad;

class SyncMetaAds extends Command
{
    protected $signature = 'meta:sync-ads';

    protected $description = 'Synchronize Meta campaigns, adsets, ads and enforce budget logic';

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
                $this->error('❌ No Meta Ad Account connected.');
                return Command::FAILURE;
            }

            $accountId = $account->meta_id;

            /*
            |------------------------------------------------------------------
            | 1️⃣ CAMPAIGNS
            |------------------------------------------------------------------
            */
            $campaigns = $this->meta->getCampaigns($accountId);

            foreach ($campaigns['data'] ?? [] as $metaCampaign) {

                Campaign::updateOrCreate(
                    ['meta_id' => $metaCampaign['id']],
                    [
                        'ad_account_id' => $account->id,
                        'name' => $metaCampaign['name'],
                        'status' => $metaCampaign['status'],
                        'objective' => $metaCampaign['objective'] ?? null
                    ]
                );
            }

            $campaignMap = Campaign::pluck('id', 'meta_id');

            /*
            |------------------------------------------------------------------
            | 2️⃣ ADSETS
            |------------------------------------------------------------------
            */
            $metaAdsets = $this->meta->getAdSets($accountId);

            foreach ($metaAdsets['data'] ?? [] as $metaAdset) {

                $campaignId = $campaignMap[$metaAdset['campaign_id']] ?? null;
                if (!$campaignId) continue;

                $budget = isset($metaAdset['daily_budget'])
                    ? ((int)$metaAdset['daily_budget']) / 100
                    : null;

                AdSet::updateOrCreate(
                    ['meta_id' => $metaAdset['id']],
                    [
                        'campaign_id' => $campaignId,
                        'name' => $metaAdset['name'],
                        'status' => $metaAdset['status'],
                        'daily_budget' => $budget
                    ]
                );
            }

            /*
            |------------------------------------------------------------------
            | 3️⃣ ADS
            |------------------------------------------------------------------
            */
            $adsetMap = AdSet::pluck('id', 'meta_id');

            $metaAds = $this->meta->getAds($accountId);

            foreach ($metaAds['data'] ?? [] as $metaAd) {

                try {

                    $metaAdId = $metaAd['id'];
                    $adsetId = $adsetMap[$metaAd['adset_id']] ?? null;

                    if (!$adsetId) continue;

                    /*
                    |----------------------------------------------------------
                    | CREATE / GET AD (DO NOT TRUST META STATUS)
                    |----------------------------------------------------------
                    */
                    $ad = Ad::firstOrCreate(
                        ['meta_ad_id' => $metaAdId],
                        [
                            'adset_id' => $adsetId,
                            'name' => $metaAd['name'],
                            'status' => 'ACTIVE'
                        ]
                    );

                    /*
                    |----------------------------------------------------------
                    | INSIGHTS
                    |----------------------------------------------------------
                    */
                    $lifetime = $this->meta->getInsights($metaAdId, 'maximum');
                    $today    = $this->meta->getInsights($metaAdId, 'today');

                    $impressions = (int)($lifetime['impressions'] ?? 0);
                    $clicks      = (int)($lifetime['clicks'] ?? 0);
                    $lifetimeSpend = (float)($lifetime['spend'] ?? 0);
                    $todaySpend    = (float)($today['spend'] ?? 0);

                    $ctr = $impressions > 0
                        ? round(($clicks / $impressions) * 100, 2)
                        : 0;

                    /*
                    |----------------------------------------------------------
                    | 🔥 BUDGET GUARD (CORE LOGIC)
                    |----------------------------------------------------------
                    */
                    $status = $ad->status;
                    $pauseReason = $ad->pause_reason;

                    Log::info('BUDGET_CHECK', [
                        'ad_id' => $metaAdId,
                        'spend' => $todaySpend,
                        'budget' => $ad->daily_budget,
                        'status' => $status
                    ]);

                    if ($ad->daily_budget) {

                        // 🔴 PAUSE
                        if ($todaySpend >= $ad->daily_budget) {

                            if ($ad->pause_reason !== 'budget') {

                                Log::warning('AUTO_PAUSING_AD', [
                                    'ad' => $metaAdId,
                                    'spend' => $todaySpend,
                                    'budget' => $ad->daily_budget
                                ]);

                                $this->meta->updateAd($metaAdId, [
                                    'status' => 'PAUSED'
                                ]);

                                $status = 'PAUSED';
                                $pauseReason = 'budget';
                            }

                        } else {

                            // 🟢 RESUME ONLY IF paused by budget
                            if ($ad->pause_reason === 'budget') {

                                Log::info('AUTO_RESUMING_AD', [
                                    'ad' => $metaAdId
                                ]);

                                $this->meta->updateAd($metaAdId, [
                                    'status' => 'ACTIVE'
                                ]);

                                $status = 'ACTIVE';
                                $pauseReason = null;
                            }
                        }
                    }

                    /*
                    |----------------------------------------------------------
                    | SAVE EVERYTHING
                    |----------------------------------------------------------
                    */
                    $ad->update([
                        'status' => $status,
                        'pause_reason' => $pauseReason,

                        'impressions' => $impressions,
                        'clicks' => $clicks,
                        'ctr' => $ctr,

                        'spend' => $lifetimeSpend,
                        'daily_spend' => $todaySpend,
                        'spend_date' => Carbon::today()->toDateString()
                    ]);

                } catch (\Throwable $e) {

                    Log::error('AD_SYNC_FAILED', [
                        'ad_id' => $metaAd['id'] ?? null,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->info('✅ Sync complete.');

            return Command::SUCCESS;

        } catch (\Throwable $e) {

            Log::error('META_SYNC_FATAL', [
                'error' => $e->getMessage()
            ]);

            $this->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}