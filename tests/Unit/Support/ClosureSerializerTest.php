<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Unit\Support;

use InvalidArgumentException;
use MohamedTarek\Stampede\Support\ClosureSerializer;
use MohamedTarek\Stampede\Tests\Support\InvokableCounter;
use PHPUnit\Framework\TestCase;

class ClosureSerializerTest extends TestCase
{
    public function test_closure_survives_serialization()
    {
        $factor = 3;
        $wrapped = ClosureSerializer::wrap(function () use ($factor) {
            return 7 * $factor;
        });

        $callback = ClosureSerializer::unwrap(unserialize(serialize($wrapped)));

        $this->assertSame(21, $callback());
    }

    public function test_invokable_object_passes_through()
    {
        $wrapped = ClosureSerializer::wrap(new InvokableCounter(9));

        $this->assertInstanceOf(InvokableCounter::class, $wrapped);
        $this->assertSame(10, ClosureSerializer::unwrap(unserialize(serialize($wrapped)))());
    }

    public function test_static_array_callable_passes_through()
    {
        $wrapped = ClosureSerializer::wrap([InvokableCounter::class, 'fortyTwo']);

        $this->assertSame([InvokableCounter::class, 'fortyTwo'], $wrapped);
        $this->assertSame(42, ClosureSerializer::unwrap(unserialize(serialize($wrapped)))());
    }

    public function test_unwrap_rejects_non_callables()
    {
        $this->expectException(InvalidArgumentException::class);

        ClosureSerializer::unwrap('not a callable at all');
    }
}
