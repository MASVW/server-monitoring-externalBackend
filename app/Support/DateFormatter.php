<?php

namespace App\Support;

use Carbon\CarbonInterface;

class DateFormatter
{
    public static function isoUtc(?CarbonInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return $date->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
