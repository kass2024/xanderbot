<?php

namespace App\Console\Commands;

use App\Models\Ad;
use Illuminate\Console\Command;

class CleanupOrphanAds extends Command
{
    protected $signature = 'ads:cleanup-orphans
                            {--dry-run : List orphan ads without deleting}';

    protected $description = 'Remove duplicate local ads with no creative (usually created by Meta sync after IG reprovision)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $legacyMetaIds = $this->collectLegacyMetaAdIds();

        $candidates = Ad::query()
            ->where(function ($query) use ($legacyMetaIds) {
                $query->whereNull('creative_id');

                if ($legacyMetaIds !== []) {
                    $query->orWhereIn('meta_ad_id', $legacyMetaIds);
                }
            })
            ->orWhere(function ($query) {
                $query->whereNull('meta_ad_id')
                    ->where('name', 'like', '% Copy');
            })
            ->get()
            ->unique('id');

        if ($candidates->isEmpty()) {
            $this->info('No orphan ads found.');

            return self::SUCCESS;
        }

        $removed = 0;

        foreach ($candidates as $ad) {
            $reason = $ad->creative_id
                ? 'legacy Meta ad id'
                : ($ad->meta_ad_id ? 'missing creative' : 'local duplicate copy');

            $this->line(sprintf(
                '%s #%d %s (meta: %s) — %s',
                $dryRun ? '[dry-run]' : 'Removing',
                $ad->id,
                $ad->name,
                $ad->meta_ad_id ?? 'none',
                $reason
            ));

            if ($dryRun) {
                $removed++;
                continue;
            }

            $ad->delete();
            $removed++;
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry run: {$removed} orphan ad(s) would be removed."
            : "Removed {$removed} orphan ad(s).");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function collectLegacyMetaAdIds(): array
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
