<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Support;

use Illuminate\Support\Carbon;

final class Clock
{
    /** Current unix time with microsecond precision. Respects Carbon::setTestNow(). */
    public static function now(): float
    {
        return (float) Carbon::now()->format('U.u');
    }
}
