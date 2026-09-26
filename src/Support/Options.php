<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Support;

use InvalidArgumentException;

final class Options
{
    private const LOCK_DEFAULTS = [
        'prefix' => 'stampede',
        'lock_seconds' => 10,
        'wait_seconds' => 5,
        'on_timeout' => 'throw',
    ];

    private const XFETCH_DEFAULTS = [
        'beta' => 1.0,
        'grace_seconds' => 300,
        'refresh' => 'queue',
        'refresh_lock_seconds' => 60,
        'queue' => ['connection' => null, 'queue' => null],
        'store' => null,
    ];

    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values) {}

    /**
     * @param  array<string, mixed>  $config  the whole config('stampede') array
     * @param  array<string, mixed>  $overrides
     */
    public static function lock(array $config, array $overrides = []): self
    {
        $values = array_merge(
            self::LOCK_DEFAULTS,
            ['prefix' => $config['prefix'] ?? self::LOCK_DEFAULTS['prefix']],
            $config['lock'] ?? [],
        );

        self::assertKnownKeys($overrides, array_keys($values));

        return new self(self::validate(array_merge($values, $overrides)));
    }

    /**
     * @param  array<string, mixed>  $config  the whole config('stampede') array
     * @param  array<string, mixed>  $overrides
     */
    public static function xfetch(array $config, array $overrides = []): self
    {
        $values = array_merge(
            self::LOCK_DEFAULTS,
            self::XFETCH_DEFAULTS,
            ['prefix' => $config['prefix'] ?? self::LOCK_DEFAULTS['prefix']],
            $config['lock'] ?? [],
            $config['xfetch'] ?? [],
        );

        self::assertKnownKeys($overrides, array_keys($values));

        if (isset($overrides['queue']) && is_array($overrides['queue']) && is_array($values['queue'])) {
            $overrides['queue'] = array_replace($values['queue'], $overrides['queue']);
        }

        return new self(self::validate(array_merge($values, $overrides)));
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self(self::validate($values));
    }

    public function get(string $key): mixed
    {
        if (! array_key_exists($key, $this->values)) {
            throw new InvalidArgumentException("Unknown stampede option [{$key}].");
        }

        return $this->values[$key];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<string>  $known
     */
    private static function assertKnownKeys(array $overrides, array $known): void
    {
        foreach (array_keys($overrides) as $key) {
            if (! in_array($key, $known, true)) {
                throw new InvalidArgumentException("Unknown stampede option [{$key}]. Known options: ".implode(', ', $known).'.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function validate(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = match ($key) {
                'prefix' => self::nonEmptyString($key, $value),
                'lock_seconds', 'refresh_lock_seconds' => self::intAtLeast($key, $value, 1),
                'wait_seconds', 'grace_seconds' => self::intAtLeast($key, $value, 0),
                'on_timeout' => self::oneOf($key, $value, ['throw', 'compute']),
                'refresh' => self::oneOf($key, $value, ['queue', 'inline']),
                'beta' => self::positiveFloat($key, $value),
                'queue' => self::queueRouting($key, $value),
                'store' => self::nullableString($key, $value),
                default => throw new InvalidArgumentException("Unknown stampede option [{$key}]."),
            };
        }

        return $values;
    }

    private static function nonEmptyString(string $key, mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Stampede option [{$key}] must be a non-empty string.");
        }

        return $value;
    }

    private static function nullableString(string $key, mixed $value): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("Stampede option [{$key}] must be a string or null.");
        }

        return $value;
    }

    private static function intAtLeast(string $key, mixed $value, int $min): int
    {
        if (! is_int($value) || $value < $min) {
            throw new InvalidArgumentException("Stampede option [{$key}] must be an integer >= {$min}.");
        }

        return $value;
    }

    private static function positiveFloat(string $key, mixed $value): float
    {
        if ((! is_int($value) && ! is_float($value)) || $value <= 0) {
            throw new InvalidArgumentException("Stampede option [{$key}] must be a number > 0.");
        }

        return (float) $value;
    }

    /** @param list<string> $allowed */
    private static function oneOf(string $key, mixed $value, array $allowed): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Stampede option [{$key}] must be one of: ".implode(', ', $allowed).'.');
        }

        return $value;
    }

    /** @return array{connection: ?string, queue: ?string} */
    private static function queueRouting(string $key, mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("Stampede option [{$key}] must be an array with 'connection' and 'queue' keys.");
        }

        foreach (array_keys($value) as $routingKey) {
            if (! in_array($routingKey, ['connection', 'queue'], true)) {
                throw new InvalidArgumentException("Stampede option [{$key}] only accepts 'connection' and 'queue' keys, got [{$routingKey}].");
            }
        }

        return [
            'connection' => self::nullableString("{$key}.connection", $value['connection'] ?? null),
            'queue' => self::nullableString("{$key}.queue", $value['queue'] ?? null),
        ];
    }
}
