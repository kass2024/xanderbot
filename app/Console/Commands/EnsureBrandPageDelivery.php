<?php

namespace App\Console\Commands;

use App\Services\InstagramDeliveryService;
use Illuminate\Console\Command;
use Throwable;

class EnsureBrandPageDelivery extends Command
{
    protected $signature = 'meta:ensure-brand-pages
                            {--reprovision : Create new Meta ads only when delivery is still on Audience Network (advanced)}';

    protected $description = 'Link all ads to META_PAGE_ID (Facebook) and the connected Instagram account on Meta';

    public function handle(InstagramDeliveryService $instagram): int
    {
        $pageId = trim((string) config('services.meta.page_id', ''));

        if ($pageId === '') {
            $this->error('META_PAGE_ID is missing in .env');

            return Command::FAILURE;
        }

        try {
            $instagram->assertInstagramConfigured($pageId);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->info('Facebook Page: '.$pageId);
        $this->info('Instagram user id: '.($instagram->verify()['instagram_user_id'] ?? '—'));

        try {
            $stats = $instagram->ensureBrandPageDeliveryAll((bool) $this->option('reprovision'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->table(
            ['Entity', 'Updated', 'Skipped', 'Failed'],
            [
                ['Ad sets', (string) $stats['adsets']['updated'], (string) $stats['adsets']['skipped'], (string) $stats['adsets']['failed']],
                ['Creatives', (string) $stats['creatives']['updated'], (string) $stats['creatives']['skipped'], (string) $stats['creatives']['failed']],
                ['Ads', (string) $stats['ads']['updated'], (string) $stats['ads']['skipped'], (string) $stats['ads']['failed']],
            ]
        );

        foreach ($stats['errors'] ?? [] as $error) {
            $this->warn($error);
        }

        $this->info($instagram->brandPageDeliverySummary($stats));

        return Command::SUCCESS;
    }
}
