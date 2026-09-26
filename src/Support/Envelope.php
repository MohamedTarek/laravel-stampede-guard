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
    public function __construct(
        public readonly mixed $value,
        public readonly float $expiresAt,
        public readonly float $computeSeconds,
    ) {}

    /** Returns null for anything that is not an envelope this package wrote. */
    public static function fromCache(mixed $raw): ?self
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

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
