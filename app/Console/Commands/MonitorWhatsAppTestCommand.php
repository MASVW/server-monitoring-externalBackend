<?php

namespace App\Console\Commands;

use App\Services\WhatsAppAlertService;
use Illuminate\Console\Command;

class MonitorWhatsAppTestCommand extends Command
{
    protected $signature = 'monitor:whatsapp-test';

    protected $description = 'Send a WhatsApp test message via configured provider';

    public function __construct(
        private readonly WhatsAppAlertService $whatsAppAlertService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('whatsapp.enabled')) {
            $this->error('WhatsApp alert is disabled.');
            return self::FAILURE;
        }

        if (! $this->whatsAppAlertService->isConfigured()) {
            $this->error('WhatsApp is not configured.');
            return self::FAILURE;
        }

        try {
            $this->whatsAppAlertService->sendTestMessage();
            $this->info('WhatsApp test message sent successfully.');
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Failed to send WhatsApp test message: '.$exception->getMessage());
            return self::FAILURE;
        }
    }
}
