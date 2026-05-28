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
            ['IG impressions (max lifetime/7d)', number_format($audit['instagram_impressions'] ?? 0)],
            ['IG impressions (recent / '.($audit['insights_recent_preset'] ?? 'last_7d').')', number_format($audit['instagram_impressions_recent'] ?? 0)],
            ['Ad created (local)', (string) ($ad->created_at ?? '—')],
            ['IG impressions (lifetime)', number_format($audit['instagram_impressions_lifetime'] ?? 0)],
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

            if ($ad->adSet?->meta_id) {
                try {
                    $adSetLive = $meta->getAdSet((string) $ad->adSet->meta_id);
                    $targeting = $adSetLive['targeting'] ?? [];
                    if (is_string($targeting)) {
                        $decoded = json_decode($targeting, true);
                        $targeting = is_array($decoded) ? $decoded : [];
                    }
                    $platforms = is_array($targeting['publisher_platforms'] ?? null)
                        ? implode(', ', $targeting['publisher_platforms'])
                        : '(automatic / not set)';
                    $igPos = is_array($targeting['instagram_positions'] ?? null)
                        ? implode(', ', $targeting['instagram_positions'])
                        : '—';
                    $this->line('  Ad set publisher_platforms (live Meta): '.$platforms);
                    $this->line('  Ad set instagram_positions (live Meta): '.$igPos);
                    if (is_string($platforms) && str_contains($platforms, 'audience_network')) {
                        $this->warn('  Audience Network is still in ad set targeting — run meta:enable-instagram --force-adsets');
                    }
                } catch (Throwable $e) {
                    $this->warn('  Ad set targeting fetch failed: '.$e->getMessage());
                }
            }

            try {
                $this->line('  publisher_platform breakdown (maximum / lifetime):');
                $rows = $meta->getInsights((string) $ad->meta_ad_id, 'maximum', ['breakdowns' => 'publisher_platform']);
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

                foreach (['last_7d' => 'last 7 days', 'today' => 'today'] as $preset => $label) {
                    $this->line("  Totals ({$label}, all platforms):");
                    $totals = $meta->getInsights((string) $ad->meta_ad_id, $preset);
                    if ($totals === []) {
                        $this->line('    (no spend/impressions in this period)');
                    } else {
                        $this->line('    impressions: '.($totals['impressions'] ?? 0)
                            .', spend: $'.($totals['spend'] ?? 0));
                    }

                    $this->line("  publisher_platform breakdown ({$label}):");
                    $recent = $meta->getInsights((string) $ad->meta_ad_id, $preset, ['breakdowns' => 'publisher_platform']);
                    if ($recent === []) {
                        $this->line('    (no platform rows — no delivery in this period)');
                    }
                    foreach ($recent as $row) {
                        $this->line('    - '.($row['publisher_platform'] ?? '?').': '
                            .($row['impressions'] ?? 0).' impr, $'.($row['spend'] ?? 0));
                    }
                }

                $hasIgToday = false;
                $todayRows = $meta->getInsights((string) $ad->meta_ad_id, 'today', ['breakdowns' => 'publisher_platform']);
                foreach ($todayRows as $row) {
                    if (($row['publisher_platform'] ?? '') === 'instagram' && (int) ($row['impressions'] ?? 0) > 0) {
                        $hasIgToday = true;
                    }
                }
                if (! $hasIgToday && ($audit['instagram_impressions'] ?? 0) === 0) {
                    $this->newLine();
                    $this->comment('  → Config is correct (IG enabled). For ads created today, Meta last_7d often excludes today (shows $0).');
                    $this->comment('  → If today shows audience_network: ad set likely included AN at create — new ad sets now force FB+IG only.');
                    $this->comment('  → Duplicate ad set (Automatic) + new ad, or confirm live publisher_platforms above is facebook, instagram only.');
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
