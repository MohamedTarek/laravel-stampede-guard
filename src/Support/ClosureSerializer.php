<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Support;

use Closure;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * Makes a callback safe to put on a queue. This is the one file that differs per branch:
 * 3.x/2.x use laravel/serializable-closure, 1.x uses Illuminate\Queue\SerializableClosure (opis).
 */
final class ClosureSerializer
{
    public static function wrap(callable $callback): mixed
    {
        return $callback instanceof Closure ? new SerializableClosure($callback) : $callback;
    }

    public static function unwrap(mixed $wrapped): callable
    {
        if ($wrapped instanceof SerializableClosure) {
            return $wrapped->getClosure();
        }

        if (! is_callable($wrapped)) {
            throw new InvalidArgumentException('The unserialized refresh callback is not callable.');
        }

        return $wrapped;
    }
}
