<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Strategies;

use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use MohamedTarek\Stampede\Events\LockAcquired;
use MohamedTarek\Stampede\Events\LockWaited;
use MohamedTarek\Stampede\Exceptions\UnsupportedStoreException;
use MohamedTarek\Stampede\Support\Options;
use stdClass;

/**
 * remember() guarded by an atomic lock with double-checked reads,
 * so a cold key is computed by exactly one worker.
 */
final class LockRemember
{
    public function __construct(
        private readonly Repository $repository,
        private readonly Options $options,
        private readonly Dispatcher $events,
        private readonly ?string $storeName = null,
    ) {}

    public function remember(string $key, mixed $ttl, Closure $callback): mixed
    {
        $missing = new stdClass;

        $value = $this->repository->get($key, $missing);
        if ($value !== $missing) {
            return $value;
        }

        $store = $this->repository->getStore();
        if (! $store instanceof LockProvider) {
            throw UnsupportedStoreException::forStore($store);
        }

        $lock = $store->lock($this->lockName($key), (int) $this->options->get('lock_seconds'));

        $started = microtime(true);
        $contended = ! $lock->get();

        if ($contended) {
            try {
                $lock->block((int) $this->options->get('wait_seconds'));
            } catch (LockTimeoutException $exception) {
                if ($this->options->get('on_timeout') === 'compute') {
                    return $this->compute($key, $ttl, $callback);
                }

                throw $exception;
            }
        }

        $waitedSeconds = $contended ? microtime(true) - $started : 0.0;

        try {
            $this->events->dispatch(new LockAcquired($key, $this->storeName, $waitedSeconds));

            if ($contended) {
                $this->events->dispatch(new LockWaited($key, $this->storeName, $waitedSeconds));
            }

            // Double-checked locking: another worker may have filled the key while we waited.
            $value = $this->repository->get($key, $missing);
            if ($value !== $missing) {
                return $value;
            }

            return $this->compute($key, $ttl, $callback);
        } finally {
            $lock->release();
        }
    }

    private function compute(string $key, mixed $ttl, Closure $callback): mixed
    {
        $value = $callback();

        if ($value === null) {
            throw new InvalidArgumentException("Callback for cache key [{$key}] returned null; null cannot be cached.");
        }

        $this->repository->put($key, $value, $ttl);

        return $value;
    }

    private function lockName(string $key): string
    {
        return $this->options->get('prefix').':lock:'.$key;
    }
}
