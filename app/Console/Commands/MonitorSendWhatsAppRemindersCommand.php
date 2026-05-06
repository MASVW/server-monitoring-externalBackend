<?php

namespace App\Console\Commands;

use App\Services\WhatsAppAlertService;
use Illuminate\Console\Command;

class MonitorSendWhatsAppRemindersCommand extends Command
{
    protected $signature = 'monitor:send-whatsapp-reminders';

    protected $description = 'Send periodic WhatsApp reminders for active incidents';

    public function __construct(
        private readonly WhatsAppAlertService $whatsAppAlertService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->whatsAppAlertService->sendScheduledReminders();

        $this->info(sprintf(
            '[whatsapp-reminder] checked=%d sent=%d at=%s',
            (int) ($result['checked'] ?? 0),
            (int) ($result['sent'] ?? 0),
            now('UTC')->format('Y-m-d\\TH:i:s.v\\Z')
        ));

        if (($result['skipped'] ?? null) !== null) {
            $this->line('[whatsapp-reminder] skipped='.$result['skipped']);
        }

        return self::SUCCESS;
    }
}
