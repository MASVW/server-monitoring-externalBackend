<?php

namespace App\Console\Commands;

use App\Services\TimeoutCheckerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class MonitorCheckTimeoutsCommand extends Command
{
    protected $signature = 'monitor:check-timeouts';

    protected $description = 'Check monitored nodes and mark down on heartbeat timeout';

    public function __construct(
        private readonly TimeoutCheckerService $timeoutCheckerService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $intervalSeconds = max(1, (int) config('monitoring.timeout_checker_interval_seconds', 60));
        $cacheKey = 'monitor:last_timeout_check_at';
        $lastRunTimestamp = Cache::get($cacheKey);

        if (is_numeric($lastRunTimestamp)) {
            $elapsed = time() - (int) $lastRunTimestamp;
            if ($elapsed < $intervalSeconds) {
                $this->info(sprintf(
                    '[timeout-checker] skipped elapsed=%ds interval=%ds',
                    $elapsed,
                    $intervalSeconds
                ));

                return self::SUCCESS;
            }
        }

        $result = $this->timeoutCheckerService->checkTimeouts();
        Cache::forever($cacheKey, time());

        $this->info(sprintf(
            '[timeout-checker] checked=%d marked_down=%d at=%s',
            $result['checked'],
            $result['marked_down'],
            now('UTC')->format('Y-m-d\\TH:i:s.v\\Z')
        ));

        return self::SUCCESS;
    }
}
