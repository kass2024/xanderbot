<?php

namespace App\Console\Commands;

use App\Models\AdSet;
use App\Services\MetaAdsService;
use Illuminate\Console\Command;
use Throwable;

class PushAdSetTargeting extends Command
{
    protected $signature = 'meta:push-adset-targeting
                            {--adset= : Local ad set ID to push (default: all with meta_id)}
                            {--dry-run : Show what would be pushed without calling Meta}';

    protected $description = 'Push local ad set targeting (countries/cities) to Meta so live ads deliver to the saved locations';

    public function handle(MetaAdsService $meta): int
    {
        $query = AdSet::query()->whereNotNull('meta_id');

        if ($adsetId = $this->option('adset')) {
            $query->where('id', $adsetId);
        }

        $adsets = $query->get();

        if ($adsets->isEmpty()) {
            $this->warn('No ad sets with a Meta ID found.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $pushed = 0;
        $failed = 0;

        foreach ($adsets as $adset) {
            $targeting = is_array($adset->targeting) ? $adset->targeting : [];

            if ($targeting === []) {
                $this->line("Skipping #{$adset->id} ({$adset->name}): no local targeting.");
                continue;
            }

            $metaTargeting = $targeting;
            unset($metaTargeting['locales']);

            $countries = $metaTargeting['geo_locations']['countries'] ?? [];
            $cityCount = count($metaTargeting['geo_locations']['cities'] ?? []);

            $this->line(sprintf(
                'Ad set #%d %s → Meta %s | countries: %s | cities: %d',
                $adset->id,
                $adset->name,
                $adset->meta_id,
                implode(', ', $countries) ?: '(none)',
                $cityCount
            ));

            if ($dryRun) {
                $pushed++;
                continue;
            }

            try {
                $meta->updateAdSet((string) $adset->meta_id, [
                    'targeting' => $metaTargeting,
                ]);
                $pushed++;
            } catch (Throwable $e) {
                $failed++;
                $this->error("  Failed: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry run complete. {$pushed} ad set(s) would be updated."
            : "Done. Pushed {$pushed} ad set(s)." . ($failed ? " {$failed} failed." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
