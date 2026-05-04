<?php

namespace App\Console\Commands;

use App\Services\AlertService;
use Illuminate\Console\Command;

class MonitorDiscordTestCommand extends Command
{
    protected $signature = 'monitor:discord-test';

    protected $description = 'Send a Discord test message';

    public function __construct(
        private readonly AlertService $alertService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->alertService->isDiscordConfigured()) {
            $this->error('Discord is not configured.');
            return self::FAILURE;
        }

        try {
            $this->alertService->sendTestAlert();
            $this->info('Discord test message sent successfully.');
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Failed to send Discord test message: '.$exception->getMessage());
            return self::FAILURE;
        }
    }
}
