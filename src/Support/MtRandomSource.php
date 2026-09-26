<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Support;

final class MtRandomSource implements RandomSource
{
    public function float(): float
    {
        return mt_rand(1, mt_getrandmax()) / mt_getrandmax();
    }
}
