<?php

namespace App\Console\Commands;

use App\Services\DiscordReminderService;
use Illuminate\Console\Command;

class MonitorSendDiscordRemindersCommand extends Command
{
    protected $signature = 'monitor:send-discord-reminders';

    protected $description = 'Send periodic Discord reminders for down/degraded or unhealthy nodes';

    public function __construct(
        private readonly DiscordReminderService $discordReminderService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->discordReminderService->sendReminders();

        $this->info(sprintf(
            '[discord-reminder] checked=%d sent=%d at=%s',
            (int) ($result['checked'] ?? 0),
            (int) ($result['sent'] ?? 0),
            now('UTC')->format('Y-m-d\\TH:i:s.v\\Z')
        ));

        return self::SUCCESS;
    }
}
