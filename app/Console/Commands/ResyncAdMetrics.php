<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\AdController;
use App\Models\Ad;
use App\Support\MetaRateLimit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ResyncAdMetrics extends Command
{
    protected $signature = 'ads:resync-metrics
                            {--discover : Find paused legacy Meta ad ids in the same ad set}
                            {--ad= : Local ads.id to resync only}';

    protected $description = 'Restore lifetime impressions/spend from Meta (current + previous ad ids after IG reprovision)';

    public function handle(AdController $ads): int
    {
        if (MetaRateLimit::isBlocked()) {
            $until = MetaRateLimit::blockedUntil();
            $this->warn(sprintf(
                'Meta API rate limit cooldown until %s — try again later.',
                $until?->toDateTimeString() ?? 'later'
            ));

            return Command::SUCCESS;
        }

        if (! Schema::hasColumn('ads', 'meta_ad_id')) {
            $this->error('ads.meta_ad_id column missing.');

            return Command::FAILURE;
        }

        $query = Ad::query()->whereNotNull('meta_ad_id');

        if ($id = $this->option('ad')) {
            $query->where('id', $id);
        }

        $collection = $query->get();

        if ($collection->isEmpty()) {
            $this->warn('No synced ads found.');

            return Command::SUCCESS;
        }

        $discover = $this->option('discover') || ! $this->option('ad');

        $this->info('Resyncing '.$collection->count().' ad(s) from Meta (lifetime + today)…');

        try {
            $stats = $ads->resyncMetricsFromMeta($collection, $discover);
        } catch (Throwable $e) {
            if (MetaRateLimit::recordFromMessage($e->getMessage())) {
                $this->warn('Meta rate limit reached — lifetime metrics were not updated.');

                return Command::SUCCESS;
            }

            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Updated', (string) ($stats['updated'] ?? 0)],
                ['Legacy ids discovered', (string) ($stats['discovered'] ?? 0)],
                ['Skipped (no Meta row)', (string) ($stats['skipped'] ?? 0)],
            ]
        );

        foreach ($stats['rows'] ?? [] as $row) {
            $this->line(sprintf(
                '  #%d %s — lifetime spend $%s, impr %s (ids: %s)',
                $row['id'],
                $row['name'],
                number_format($row['spend'], 2),
                number_format($row['impressions']),
                implode(', ', $row['meta_ids'])
            ));
        }

        $this->info('Done. Lifetime spend is in the Spend/Lifetime column — refresh Ads Manager.');

        return Command::SUCCESS;
    }
}
