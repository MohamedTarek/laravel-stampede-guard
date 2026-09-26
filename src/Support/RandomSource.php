<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Support;

interface RandomSource
{
    /** A uniformly distributed float in (0, 1]. Never 0, so log() stays finite. */
    public function float(): float;
}
