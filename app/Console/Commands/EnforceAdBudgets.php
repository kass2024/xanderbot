<?php

namespace App\Console\Commands;

use App\Services\AdBudgetEnforcementService;
use Illuminate\Console\Command;

class EnforceAdBudgets extends Command
{
    protected $signature = 'ads:enforce-budgets';

    protected $description = 'Fetch live Meta today spend and pause ads before they exceed daily budget';

    public function handle(AdBudgetEnforcementService $enforcer): int
    {
        if (\App\Support\MetaRateLimit::isBlocked()) {
            $until = \App\Support\MetaRateLimit::blockedUntil();
            $this->warn(sprintf(
                'Meta API rate limit cooldown until %s — skipping budget enforcement.',
                $until?->toDateTimeString() ?? 'later'
            ));

            return Command::SUCCESS;
        }

        $stats = $enforcer->enforceAll();

        $this->line(sprintf(
            'Checked: %d | Newly paused: %d | Re-paused on Meta: %d | Errors: %d',
            $stats['checked'],
            $stats['paused'],
            $stats['re_paused'],
            $stats['errors'],
        ));

        return $stats['errors'] > 0 && $stats['paused'] === 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
