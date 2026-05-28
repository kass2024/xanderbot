<?php

namespace App\Console\Commands;

use App\Models\Ad;
use App\Services\InstagramDeliveryService;
use App\Services\MetaAdsService;
use Illuminate\Console\Command;
use Throwable;

class DebugAdInstagram extends Command
{
    protected $signature = 'meta:debug-ad-ig
                            {ad : Local ad ID or Meta ad ID}
                            {--run : Execute curl-equivalent API calls (not just print commands)}';

    protected $description = 'Debug Instagram delivery for one ad (Meta API checks + curl commands)';

    public function handle(InstagramDeliveryService $instagram, MetaAdsService $meta): int
    {
        $key = trim((string) $this->argument('ad'));

        $query = Ad::query()->with(['creative', 'adSet.campaign.adAccount']);

        if (strlen($key) >= 12 && ctype_digit($key)) {
            $ad = (clone $query)->where('meta_ad_id', $key)->first();
        } else {
            $ad = null;
        }

        if (! $ad) {
            $ad = (clone $query)->where('id', $key)->first();
        }

        if (! $ad) {
            $this->error('Ad not found: '.$key.' (use local ads.id or meta_ad_id from the table below).');
            $this->newLine();

            $rows = Ad::query()
                ->whereNotNull('meta_ad_id')
                ->orderBy('id')
                ->get(['id', 'name', 'meta_ad_id', 'status']);

            if ($rows->isEmpty()) {
                $this->warn('No synced ads in database. Sync from Meta or create ads in Ads Manager first.');

                return Command::FAILURE;
            }

            $this->info('Synced ads in database:');
            $this->table(
                ['Local ID', 'Meta ad ID', 'Status', 'Name'],
                $rows->map(fn (Ad $a) => [
                    $a->id,
                    $a->meta_ad_id,
                    $a->status,
                    \Illuminate\Support\Str::limit($a->name, 50),
                ])->all()
            );
            $this->comment('Example: php artisan meta:debug-ad-ig '.$rows->first()->id.' --run');

            return Command::FAILURE;
        }

        $placementDelivery = [];
        if ($this->option('run') && $ad->meta_ad_id) {
            $accountId = $ad->adSet?->campaign?->adAccount?->meta_id
                ?? config('services.meta.ad_account_id');
            try {
                $map = $meta->getAdPlacementInsightsMap($accountId, 'maximum');
                $placementDelivery = $map[(string) $ad->meta_ad_id] ?? [];
            } catch (Throwable $e) {
                $this->warn('Placement insights failed: '.$e->getMessage());
            }
        }

        $audit = $instagram->auditAdDelivery($ad, $placementDelivery, $this->option('run'));

        $this->info('Instagram delivery audit: '.$ad->name);
        $this->table(['Field', 'Value'], [
            ['Status', $audit['status'].' — '.$audit['status_label']],
            ['Ad set targets IG', ($audit['configured_adset'] ?? false) ? 'yes' : 'no'],
            ['Creative has IG id (local)', ($audit['configured_creative'] ?? false) ? 'yes' : 'no'],
            ['Meta creative has IG id', $audit['meta_creative_has_ig'] === null ? 'unknown' : (($audit['meta_creative_has_ig'] ?? false) ? 'yes' : 'no')],
            ['IG impressions', number_format($audit['instagram_impressions'] ?? 0)],
            ['FB impressions', number_format($audit['facebook_impressions'] ?? 0)],
            ['Audience Network impr.', number_format($audit['audience_network_impressions'] ?? 0)],
            ['instagram_user_id', (string) ($audit['instagram_user_id'] ?? '—')],
            ['Enabled at', (string) ($audit['instagram_enabled_at'] ?? '—')],
        ]);

        $this->newLine();
        $this->info('Checklist');
        foreach ($audit['checks'] as $check) {
            $mark = ($check['ok'] ?? false) ? '✓' : '✗';
            $note = ! empty($check['note']) ? ' ('.$check['note'].')' : '';
            $this->line("  {$mark} ".$check['label'].$note);
        }

        if ($this->option('run') && $ad->meta_ad_id) {
            $this->newLine();
            $this->info('Live Meta API');
            try {
                $live = $meta->getAdWithCreativeSpec((string) $ad->meta_ad_id);
                $this->line('  Ad status: '.($live['status'] ?? '—'));
                $oss = $live['creative']['object_story_spec'] ?? null;
                if (is_string($oss)) {
                    $oss = json_decode($oss, true);
                }
                $igActor = is_array($oss) ? ($oss['instagram_user_id'] ?? null) : null;
                $this->line('  creative.object_story_spec.instagram_user_id: '.($igActor ?: 'MISSING'));
            } catch (Throwable $e) {
                $this->error('  '.$e->getMessage());
            }

            try {
                $rows = $meta->getInsights((string) $ad->meta_ad_id, 'maximum', ['breakdowns' => 'publisher_platform']);
                $this->line('  publisher_platform breakdown:');
                if ($rows === []) {
                    $this->line('    (no breakdown rows)');
                }
                foreach ($rows as $row) {
                    $this->line('    - '.($row['publisher_platform'] ?? '?').': '
                        .($row['impressions'] ?? 0).' impr, $'.($row['spend'] ?? 0));
                }
                if (! empty($audit['delivery_warning'])) {
                    $this->warn('  '.$audit['delivery_warning']);
                }
            } catch (Throwable $e) {
                $this->error('  Insights: '.$e->getMessage());
            }
        }

        $this->newLine();
        $this->comment('Curl commands (run on server with your token):');
        foreach ($audit['curl_commands'] as $block) {
            $this->line('');
            $this->line($block['title']);
            $this->line($block['command']);
        }

        if (! $this->option('run')) {
            $this->comment('Add --run to call Meta API from this command.');
        }

        return ($audit['status'] === 'live' || $audit['status'] === 'enabled')
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}
