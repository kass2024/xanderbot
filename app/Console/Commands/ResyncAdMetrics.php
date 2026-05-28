<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\AdController;
use App\Models\Ad;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ResyncAdMetrics extends Command
{
    protected $signature = 'meta:resync-ad-metrics
                            {--discover : Find paused legacy Meta ad ids in the same ad set}
                            {--ad= : Local ads.id to resync only}';

    protected $description = 'Restore impressions/spend from Meta (current + previous ad ids after IG reprovision)';

    public function handle(AdController $ads): int
    {
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

        $this->info('Resyncing '.$collection->count().' ad(s) from Meta…');

        try {
            $stats = $ads->resyncMetricsFromMeta($collection, (bool) $this->option('discover'));
        } catch (Throwable $e) {
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
                '  #%d %s — impr %s, spend $%s (ids: %s)',
                $row['id'],
                $row['name'],
                number_format($row['impressions']),
                number_format($row['spend'], 2),
                implode(', ', $row['meta_ids'])
            ));
        }

        $this->info('Done. Refresh Ads Manager in the browser.');

        return Command::SUCCESS;
    }
}
