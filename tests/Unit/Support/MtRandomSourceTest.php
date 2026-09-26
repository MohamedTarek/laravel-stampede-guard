<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Unit\Support;

use MohamedTarek\Stampede\Support\MtRandomSource;
use MohamedTarek\Stampede\Support\RandomSource;
use PHPUnit\Framework\TestCase;

class MtRandomSourceTest extends TestCase
{
    public function test_it_is_a_random_source_in_the_half_open_unit_interval()
    {
        $source = new MtRandomSource;

        $this->assertInstanceOf(RandomSource::class, $source);

        for ($i = 0; $i < 10000; $i++) {
            $value = $source->float();
            $this->assertGreaterThan(0.0, $value);
            $this->assertLessThanOrEqual(1.0, $value);
            $this->assertTrue(is_finite(log($value)));
        }
    }
}
