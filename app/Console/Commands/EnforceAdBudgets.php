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
