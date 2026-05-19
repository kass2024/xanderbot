<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;

class ResetWhatsAppConversation extends Command
{
    protected $signature = 'whatsapp:reset-conversation {phone : E.164 digits only}';

    protected $description = 'Clear stuck onboarding/handoff so Hello gets FAQ replies again';

    public function handle(): int
    {
        $phone = preg_replace('/\D+/', '', (string) $this->argument('phone')) ?: '';

        if ($phone === '') {
            $this->error('Invalid phone.');

            return self::FAILURE;
        }

        $updated = Conversation::where('phone_number', $phone)->update([
            'status' => 'bot',
            'is_profile_completed' => 1,
            'profile_step' => 'completed',
            'assigned_agent_id' => null,
            'escalation_reason' => null,
            'escalation_started_at' => null,
        ]);

        $this->info("Updated {$updated} conversation(s) for ***".substr($phone, -4));
        $this->comment('User can send Hello again for FAQ bot.');

        return self::SUCCESS;
    }
}
