<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Support;

use Closure;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * Makes a callback safe to put on a queue. This is the one file whose logic differs per branch:
 * 3.x uses laravel/serializable-closure, 1.x uses Illuminate\Queue\SerializableClosure (opis).
 *
 * 2.x uses laravel/serializable-closure on PHP 7.4+ and supports PHP 7.3 only through
 * opis/closure, because laravel/serializable-closure 1.x refuses to run below PHP 7.4.
 * This mirrors Laravel 8's Illuminate\Queue\SerializableClosureFactory. opis/closure is
 * required by illuminate/queue 8.x, the only Laravel line installable on PHP 7.3; on
 * Laravel 9/10 PHP is >= 8.0, so the opis branch is never taken there.
 */
final class ClosureSerializer
{
    /** @return mixed */
    public static function wrap(callable $callback)
    {
        if (! $callback instanceof Closure) {
            return $callback;
        }

        if (\PHP_VERSION_ID >= 70400 && class_exists(SerializableClosure::class)) {
            return new SerializableClosure($callback);
        }

        return new \Opis\Closure\SerializableClosure($callback);
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
