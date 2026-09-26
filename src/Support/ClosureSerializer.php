<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Support;

use Closure;
use Illuminate\Queue\SerializableClosure;
use InvalidArgumentException;

/**
 * Makes a callback safe to put on a queue. This is the one file whose logic differs per branch:
 * 3.x and 2.x use laravel/serializable-closure, 1.x uses Illuminate\Queue\SerializableClosure.
 *
 * 1.x variant: Laravel 6 and 7 ship Illuminate\Queue\SerializableClosure, which extends
 * Opis\Closure\SerializableClosure (opis/closure), on every supported PHP version, so no
 * PHP-version branching is needed. unwrap() also accepts the opis parent class directly.
 */
final class ClosureSerializer
{
    /** @return mixed */
    public static function wrap(callable $callback)
    {
        return $callback instanceof Closure ? new SerializableClosure($callback) : $callback;
    }

    /** @param mixed $wrapped */
    public static function unwrap($wrapped): callable
    {
        if ($wrapped instanceof SerializableClosure
            || $wrapped instanceof \Opis\Closure\SerializableClosure) {
            return $wrapped->getClosure();
        }

        if (! is_callable($wrapped)) {
            throw new InvalidArgumentException('The unserialized refresh callback is not callable.');
        }

        return $wrapped;
    }
}
