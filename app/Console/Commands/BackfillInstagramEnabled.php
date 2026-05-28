<?php

namespace App\Console\Commands;

use App\Services\InstagramDeliveryService;
use Illuminate\Console\Command;

class BackfillInstagramEnabled extends Command
{
    protected $signature = 'meta:backfill-ig-enabled';

    protected $description = 'Set instagram_enabled_at on ads whose creatives already have instagram_user_id';

    public function handle(InstagramDeliveryService $instagram): int
    {
        $count = $instagram->backfillInstagramEnabledFlags();

        $this->info("Backfilled {$count} ad(s). Refresh Ads Manager to see IG enabled.");

        return Command::SUCCESS;
    }
}
