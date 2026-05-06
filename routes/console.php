<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('monitor:check-timeouts')->everyMinute()->withoutOverlapping();
Schedule::command('monitor:send-discord-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('monitor:send-whatsapp-reminders')->everyMinute()->withoutOverlapping();
