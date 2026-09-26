<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Support;

use MohamedTarek\Stampede\Support\RandomSource;

class FixedRandomSource implements RandomSource
{
    /** @var float */
    private $value;

    public function __construct(float $value)
    {
        $this->value = $value;
    }

    public function float(): float
    {
        return $this->value;
    }
}
