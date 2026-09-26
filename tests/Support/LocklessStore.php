<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Support;

use Illuminate\Contracts\Cache\Store;

/**
 * A minimal in-memory Store that deliberately does NOT implement LockProvider,
 * so tests can prove UnsupportedStoreException is thrown.
 */
class LocklessStore implements Store
{
    /** @var array<string, mixed> */
    private $items = [];

    public function get($key)
    {
        return $this->items[$key] ?? null;
    }

    public function many(array $keys)
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    public function put($key, $value, $seconds)
    {
        $this->items[$key] = $value;

        return true;
    }

    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1)
    {
        $this->items[$key] = ($this->items[$key] ?? 0) + $value;

        return $this->items[$key];
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value)
    {
        return $this->put($key, $value, 0);
    }

    public function touch($key, $seconds)
    {
        return array_key_exists($key, $this->items);
    }

    public function forget($key)
    {
        unset($this->items[$key]);

        return true;
    }

    public function flush()
    {
        $this->items = [];

        return true;
    }

    public function getPrefix()
    {
        return '';
    }
}
