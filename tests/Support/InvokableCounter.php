<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Support;

class InvokableCounter
{
    /** @var int */
    public $base;

    public function __construct(int $base)
    {
        $this->base = $base;
    }

    public function __invoke()
    {
        return $this->base + 1;
    }

    public static function fortyTwo()
    {
        return 42;
    }
}
