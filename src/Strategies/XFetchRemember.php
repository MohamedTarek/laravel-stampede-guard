<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Strategies;

use DateTimeInterface;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\InteractsWithTime;
use InvalidArgumentException;
use MohamedTarek\Stampede\Events\EarlyRefreshCompleted;
use MohamedTarek\Stampede\Events\EarlyRefreshFailed;
use MohamedTarek\Stampede\Events\EarlyRefreshScheduled;
use MohamedTarek\Stampede\Exceptions\UnsupportedStoreException;
use MohamedTarek\Stampede\Jobs\RefreshCacheJob;
use MohamedTarek\Stampede\Support\Clock;
use MohamedTarek\Stampede\Support\ClosureSerializer;
use MohamedTarek\Stampede\Support\Envelope;
use MohamedTarek\Stampede\Support\Options;
use MohamedTarek\Stampede\Support\RandomSource;
use stdClass;
use Throwable;

/**
 * Probabilistic early expiration (XFetch, Vattani et al.).
 * A cached value is served until its logical expiry; as that approaches, one reader
 * "volunteers" (probability rises with the value's own compute time) and refreshes it
 * ahead of time, so no reader ever sees a cold key after the first fill.
 */
final class XFetchRemember
{
    use InteractsWithTime;

    public function __construct(
        private readonly Repository $repository,
        private readonly Options $options,
        private readonly Dispatcher $events,
        private readonly BusDispatcher $bus,
        private readonly RandomSource $random,
        private readonly ?string $storeName = null,
        private readonly ?ExceptionHandler $exceptions = null,
    ) {}

    public function remember(string $key, mixed $ttl, callable $callback): mixed
    {
        $store = $this->repository->getStore();
        if (! $store instanceof LockProvider) {
            throw UnsupportedStoreException::forStore($store);
        }

        $ttlSeconds = $this->ttlSeconds($ttl);
        $missing = new stdClass;

        $raw = $this->repository->get($key, $missing);
        $envelope = $raw === $missing ? null : Envelope::fromCache($raw);

        if ($envelope === null) {
            if ($raw !== $missing) {
                // Something else wrote a non-envelope under this key; treat it as a miss.
                $this->repository->forget($key);
            }

            return $this->coldStart($key, $ttlSeconds, $callback);
        }

        if (! $this->shouldRefresh($envelope)) {
            return $envelope->value;
        }

        $lock = $store->lock($this->refreshLockName($key), (int) $this->options->get('refresh_lock_seconds'));

        if (! $lock->get()) {
            // Another worker already volunteered.
            return $envelope->value;
        }

        if ($this->options->get('refresh') === 'inline') {
            return $this->refreshInline($key, $ttlSeconds, $callback, $lock, $envelope);
        }

        // The still-valid (stale) value is served whether or not the dispatch succeeded.
        $this->dispatchRefreshJob($key, $ttlSeconds, $callback, $lock);

        return $envelope->value;
    }

    private function coldStart(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $mutex = new LockRemember($this->repository, $this->options, $this->events, $this->storeName);

        $stored = $mutex->remember($key, $this->physicalTtl($ttlSeconds), function () use ($callback, $ttlSeconds): array {
            return Envelope::compute($callback, $ttlSeconds)->toArray();
        });

        $envelope = Envelope::fromCache($stored);

        return $envelope === null ? $stored : $envelope->value;
    }

    /** XFetch: now - d * beta * ln(rand) >= expiry, with rand in (0, 1] so ln(rand) <= 0. */
    private function shouldRefresh(Envelope $envelope): bool
    {
        $beta = (float) $this->options->get('beta');

        return Clock::now() - $envelope->computeSeconds * $beta * log($this->random->float()) >= $envelope->expiresAt;
    }

    /**
     * Refreshes in the volunteer's own request. A failure serves the stale value and keeps
     * the refresh lock, so the next attempt waits for refresh_lock_seconds.
     */
    private function refreshInline(string $key, int $ttlSeconds, callable $callback, Lock $lock, Envelope $stale): mixed
    {
        try {
            $this->events->dispatch(new EarlyRefreshScheduled($key, $this->storeName, 'inline'));

            $fresh = Envelope::compute($callback, $ttlSeconds);

            $this->repository->put($key, $fresh->toArray(), $this->physicalTtl($ttlSeconds));
        } catch (Throwable $exception) {
            $this->refreshFailed($key, 'inline', $exception);

            return $stale->value;
        }

        try {
            $this->events->dispatch(new EarlyRefreshCompleted($key, $this->storeName, 'inline', $fresh->computeSeconds));
        } finally {
            $lock->release();
        }

        return $fresh->value;
    }

    /**
     * Hands the refresh to a queued job, which releases the lock when it finishes. A failed
     * dispatch keeps the lock, so the next attempt waits for refresh_lock_seconds.
     */
    private function dispatchRefreshJob(string $key, int $ttlSeconds, callable $callback, Lock $lock): bool
    {
        try {
            $job = new RefreshCacheJob(
                $key,
                $ttlSeconds,
                $this->options->toArray(),
                $this->storeName,
                ClosureSerializer::wrap($callback),
                $lock->owner(),
            );

            /** @var array{connection: ?string, queue: ?string} $routing */
            $routing = $this->options->get('queue');

            if ($routing['connection'] !== null) {
                $job->onConnection($routing['connection']);
            }

            if ($routing['queue'] !== null) {
                $job->onQueue($routing['queue']);
            }

            $this->bus->dispatch($job);
        } catch (Throwable $exception) {
            $this->refreshFailed($key, 'queue', $exception);

            return false;
        }

        $this->events->dispatch(new EarlyRefreshScheduled($key, $this->storeName, 'queue'));

        return true;
    }

    /**
     * Failure contract of an early refresh: fire the event, report the exception, and let the
     * caller serve the stale value. The refresh lock is deliberately left to expire.
     *
     * @param  'queue'|'inline'  $mode
     */
    private function refreshFailed(string $key, string $mode, Throwable $exception): void
    {
        $this->events->dispatch(new EarlyRefreshFailed($key, $this->storeName, $mode, $exception));

        $this->exceptions?->report($exception);
    }

    private function ttlSeconds(mixed $ttl): int
    {
        if ($ttl === null) {
            throw new InvalidArgumentException('rememberXFetch() requires a TTL; a value cached forever has no logical expiry to refresh ahead of.');
        }

        $duration = $this->parseDateInterval($ttl);

        if ($duration instanceof DateTimeInterface) {
            $duration = $this->secondsUntil($duration);
        }

        $seconds = (int) $duration;

        if ($seconds <= 0) {
            throw new InvalidArgumentException('rememberXFetch() TTL must be at least one second.');
        }

        return $seconds;
    }

    private function physicalTtl(int $ttlSeconds): int
    {
        return $ttlSeconds + (int) $this->options->get('grace_seconds');
    }

    private function refreshLockName(string $key): string
    {
        return $this->options->get('prefix').':refresh:'.$key;
    }
}
