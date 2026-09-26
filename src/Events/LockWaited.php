<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Events;

/** Fired only when the mutex was not free on the first attempt. */
final class LockWaited
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $store,
        public readonly float $waitedSeconds,
    ) {}
}
