<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MohamedTarek\Stampede\Events\EarlyRefreshCompleted;
use MohamedTarek\Stampede\Events\EarlyRefreshFailed;
use MohamedTarek\Stampede\Support\ClosureSerializer;
use MohamedTarek\Stampede\Support\Envelope;
use MohamedTarek\Stampede\Support\Options;
use Throwable;

/**
 * Recomputes an XFetch entry ahead of its logical expiry. The dispatching request
 * acquired the refresh lock; this job releases it by restoring the lock with the owner token.
 */
final class RefreshCacheJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var string
     *
     * @readonly
     */
    public $key;

    /**
     * @var int
     *
     * @readonly
     */
    public $ttlSeconds;

    /**
     * @var array<string, mixed>
     *
     * @readonly
     */
    public $options;

    /**
     * @var string|null
     *
     * @readonly
     */
    public $store;

    /**
     * @var mixed
     *
     * @readonly
     */
    public $callback;

    /**
     * @var string
     *
     * @readonly
     */
    public $lockOwner;

    /**
     * @param  array<string, mixed>  $options  Options::toArray() from the dispatching request
     * @param  mixed  $callback  ClosureSerializer::wrap() output
     */
    public function __construct(string $key, int $ttlSeconds, array $options, ?string $store, $callback, string $lockOwner)
    {
        $this->key = $key;
        $this->ttlSeconds = $ttlSeconds;
        $this->options = $options;
        $this->store = $store;
        $this->callback = $callback;
        $this->lockOwner = $lockOwner;
    }

    public function handle(Factory $cache, Dispatcher $events): void
    {
        $repository = $cache->store($this->store);
        $options = Options::fromArray($this->options);
        $lockName = $options->get('prefix').':refresh:'.$this->key;

        try {
            $envelope = Envelope::compute(ClosureSerializer::unwrap($this->callback), $this->ttlSeconds);

            $repository->put($this->key, $envelope->toArray(), $this->ttlSeconds + (int) $options->get('grace_seconds'));

            $events->dispatch(new EarlyRefreshCompleted($this->key, $this->store, 'queue', $envelope->computeSeconds));
        } catch (Throwable $exception) {
            $events->dispatch(new EarlyRefreshFailed($this->key, $this->store, 'queue', $exception));

            throw $exception;
        } finally {
            $store = $repository->getStore();

            if ($store instanceof LockProvider) {
                $store->restoreLock($lockName, $this->lockOwner)->release();
            }
        }
    }
}
