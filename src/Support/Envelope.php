<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Support;

use InvalidArgumentException;

/**
 * The payload rememberXFetch() stores under the caller's key:
 * the value, its logical expiry, and how long it took to compute.
 */
final class Envelope
{
    /**
     * @var mixed
     *
     * @readonly
     */
    public $value;

    /**
     * @var float
     *
     * @readonly
     */
    public $expiresAt;

    /**
     * @var float
     *
     * @readonly
     */
    public $computeSeconds;

    /** @param mixed $value */
    public function __construct($value, float $expiresAt, float $computeSeconds)
    {
        $this->value = $value;
        $this->expiresAt = $expiresAt;
        $this->computeSeconds = $computeSeconds;
    }

    /**
     * Returns null for anything that is not an envelope this package wrote.
     *
     * @param  mixed  $raw
     */
    public static function fromCache($raw): ?self
    {
        if (! is_array($raw) || ! array_key_exists('v', $raw) || ! isset($raw['e'], $raw['d'])) {
            return null;
        }

        if ($raw['v'] === null || ! self::isNumber($raw['e']) || ! self::isNumber($raw['d'])) {
            return null;
        }

        return new self($raw['v'], (float) $raw['e'], (float) $raw['d']);
    }

    /** Runs the callback, measures it, and stamps the logical expiry. */
    public static function compute(callable $callback, int $ttlSeconds): self
    {
        $started = microtime(true);
        $value = $callback();
        $computeSeconds = microtime(true) - $started;

        if ($value === null) {
            throw new InvalidArgumentException('Cache callbacks must not return null; null cannot be cached.');
        }

        return new self($value, Clock::now() + $ttlSeconds, $computeSeconds);
    }

    /** @return array{v: mixed, e: float, d: float} */
    public function toArray(): array
    {
        return ['v' => $this->value, 'e' => $this->expiresAt, 'd' => $this->computeSeconds];
    }

    /** @param mixed $value */
    private static function isNumber($value): bool
    {
        return is_int($value) || is_float($value);
    }
}
