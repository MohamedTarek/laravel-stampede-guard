<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Events;

use Throwable;

final class EarlyRefreshFailed
{
    /** @param 'queue'|'inline' $mode */
    public function __construct(
        public readonly string $key,
        public readonly ?string $store,
        public readonly string $mode,
        public readonly Throwable $exception,
    ) {}
}
