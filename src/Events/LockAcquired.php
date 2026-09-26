<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Events;

final class LockAcquired
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $store,
        public readonly float $waitedSeconds,
    ) {}
}
