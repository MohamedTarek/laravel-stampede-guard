<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Unit\Strategies;

use DateInterval;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus as BusFacade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Mockery;
use MohamedTarek\Stampede\Events\EarlyRefreshCompleted;
use MohamedTarek\Stampede\Events\EarlyRefreshFailed;
use MohamedTarek\Stampede\Events\EarlyRefreshScheduled;
use MohamedTarek\Stampede\Events\LockAcquired;
use MohamedTarek\Stampede\Exceptions\UnsupportedStoreException;
use MohamedTarek\Stampede\Jobs\RefreshCacheJob;
use MohamedTarek\Stampede\Strategies\XFetchRemember;
use MohamedTarek\Stampede\Support\Envelope;
use MohamedTarek\Stampede\Support\Options;
use MohamedTarek\Stampede\Tests\Support\FixedRandomSource;
use MohamedTarek\Stampede\Tests\Support\HookedArrayStore;
use MohamedTarek\Stampede\Tests\Support\LocklessStore;
use MohamedTarek\Stampede\Tests\Support\ThrowingBus;
use MohamedTarek\Stampede\Tests\TestCase;
use RuntimeException;

class XFetchRememberTest extends TestCase
{
    /** rand = 1 gives ln(rand) = 0, so the decision reduces to now >= expiry: never refresh early. */
    const NEVER = 1.0;

    /** rand = e^-200 makes the term huge, so any entry expiring within ~200 x d seconds refreshes. */
    const ALWAYS = 1.3838965267367376e-87;

    /** @var HookedArrayStore */
    private $store;

    /** @var Repository */
    private $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new HookedArrayStore;
        $this->repository = new Repository($this->store);
        Carbon::setTestNow(Carbon::createFromTimestamp(1700000000));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function strategy(float $random, array $overrides = [], ?Repository $repository = null, ?Bus $bus = null, ?ExceptionHandler $exceptions = null): XFetchRemember
    {
        return new XFetchRemember(
            $repository ?: $this->repository,
            Options::xfetch(config('stampede'), $overrides),
            $this->app['events'],
            $bus ?: $this->app->make(Bus::class),
            new FixedRandomSource($random),
            'array',
            $exceptions
        );
    }

    /** An exception handler that expects exactly one report() of an exception with the given message. */
    private function expectReported(string $message): ExceptionHandler
    {
        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with(Mockery::on(function ($exception) use ($message) {
            return $exception instanceof RuntimeException && $exception->getMessage() === $message;
        }));

        return $handler;
    }

    private function seedEnvelope($value = 'cached', int $secondsUntilExpiry = 100, float $compute = 1.0): void
    {
        $this->repository->put('k', (new Envelope($value, 1700000000.0 + $secondsUntilExpiry, $compute))->toArray(), 1000);
    }

    public function test_cold_start_computes_under_the_mutex_and_stores_an_envelope()
    {
        Event::fake([LockAcquired::class]);

        $value = $this->strategy(self::NEVER)->remember('k', 100, function () {
            return 'computed';
        });

        $this->assertSame('computed', $value);
        $envelope = Envelope::fromCache($this->repository->get('k'));
        $this->assertSame('computed', $envelope->value);
        $this->assertEqualsWithDelta(1700000100.0, $envelope->expiresAt, 0.001);
        Event::assertDispatched(LockAcquired::class);
    }

    public function test_cold_start_double_check_returns_value_another_worker_stored()
    {
        $repository = $this->repository;
        $this->store->afterAcquire = function () use ($repository) {
            $repository->put('k', (new Envelope('other-worker', 1700000100.0, 1.0))->toArray(), 1000);
        };

        $value = $this->strategy(self::NEVER)->remember('k', 100, function () {
            $this->fail('must not compute');
        });

        $this->assertSame('other-worker', $value);
    }

    public function test_foreign_value_under_the_key_is_replaced()
    {
        $this->repository->put('k', 'plain-value', 1000);

        $value = $this->strategy(self::NEVER)->remember('k', 100, function () {
            return 'computed';
        });

        $this->assertSame('computed', $value);
        $this->assertSame('computed', Envelope::fromCache($this->repository->get('k'))->value);
    }

    public function test_hit_returns_cached_value_without_refresh_when_not_volunteering()
    {
        $this->seedEnvelope();
        Event::fake([EarlyRefreshScheduled::class]);

        $value = $this->strategy(self::NEVER)->remember('k', 100, function () {
            $this->fail('must not compute');
        });

        $this->assertSame('cached', $value);
        Event::assertNotDispatched(EarlyRefreshScheduled::class);
    }

    public function test_falsy_value_inside_envelope_is_a_hit()
    {
        foreach ([0, false, [], ''] as $falsy) {
            $this->seedEnvelope($falsy);

            $value = $this->strategy(self::NEVER)->remember('k', 100, function () {
                $this->fail('must not compute');
            });

            $this->assertSame($falsy, $value);
        }
    }

    public function test_decision_boundary()
    {
        // now = expiry - 100, d = 1, beta = 1. Need -ln(rand) >= 100, i.e. rand <= e^-100.
        $this->seedEnvelope('cached', 100, 1.0);
        BusFacade::fake();

        $this->strategy(exp(-99.0))->remember('k', 100, function () {
            return 'x';
        });
        BusFacade::assertNotDispatched(RefreshCacheJob::class);

        $this->strategy(exp(-101.0))->remember('k', 100, function () {
            return 'x';
        });
        BusFacade::assertDispatched(RefreshCacheJob::class);
    }

    public function test_beta_scales_the_decision()
    {
        $this->seedEnvelope('cached', 100, 1.0);
        BusFacade::fake();

        $this->strategy(exp(-60.0), ['beta' => 2.0])->remember('k', 100, function () {
            return 'x';
        });

        BusFacade::assertDispatched(RefreshCacheJob::class);
    }

    public function test_queue_mode_dispatches_job_keeps_lock_and_returns_stale_value()
    {
        $this->seedEnvelope('stale');
        BusFacade::fake();
        Event::fake([EarlyRefreshScheduled::class]);

        $value = $this->strategy(self::ALWAYS, ['queue' => ['queue' => 'refreshes']])->remember('k', 100, function () {
            return 'fresh';
        });

        $this->assertSame('stale', $value);
        BusFacade::assertDispatched(RefreshCacheJob::class, function (RefreshCacheJob $job) {
            return $job->key === 'k'
                && $job->ttlSeconds === 100
                && $job->store === 'array'
                && $job->lockOwner !== ''
                && $job->queue === 'refreshes';
        });
        $this->assertFalse($this->store->lock('stampede:refresh:k', 1)->get(), 'refresh lock stays held for the job');
        Event::assertDispatched(EarlyRefreshScheduled::class, function (EarlyRefreshScheduled $event) {
            return $event->mode === 'queue';
        });
    }

    public function test_second_volunteer_does_not_dispatch_while_lock_is_held()
    {
        $this->seedEnvelope('stale');
        BusFacade::fake();
        $this->store->lock('stampede:refresh:k', 60)->get();

        $value = $this->strategy(self::ALWAYS)->remember('k', 100, function () {
            return 'fresh';
        });

        $this->assertSame('stale', $value);
        BusFacade::assertNotDispatched(RefreshCacheJob::class);
    }

    public function test_sync_queue_runs_the_job_inline_and_hands_the_lock_back()
    {
        // The job rebuilds the repository through the CacheManager, so this test must use
        // the manager's own "array" repository rather than the hand-built one.
        $repository = Cache::store();
        $repository->put('k', (new Envelope('stale', 1700000100.0, 1.0))->toArray(), 1000);
        Event::fake([EarlyRefreshCompleted::class]);

        $value = $this->strategy(self::ALWAYS, [], $repository)->remember('k', 100, function () {
            return 'fresh';
        });

        $this->assertSame('stale', $value, 'the volunteer still returns what it read');
        $this->assertSame('fresh', Envelope::fromCache($repository->get('k'))->value);
        $this->assertTrue($repository->getStore()->lock('stampede:refresh:k', 1)->get(), 'job released the lock via restoreLock');
        Event::assertDispatched(EarlyRefreshCompleted::class);
    }

    public function test_inline_mode_refreshes_now_and_returns_fresh_value()
    {
        $this->seedEnvelope('stale');
        BusFacade::fake();
        Event::fake([EarlyRefreshScheduled::class, EarlyRefreshCompleted::class]);

        $value = $this->strategy(self::ALWAYS, ['refresh' => 'inline'])->remember('k', 100, function () {
            return 'fresh';
        });

        $this->assertSame('fresh', $value);
        $this->assertSame('fresh', Envelope::fromCache($this->repository->get('k'))->value);
        $this->assertTrue($this->store->lock('stampede:refresh:k', 1)->get());
        BusFacade::assertNotDispatched(RefreshCacheJob::class);
        Event::assertDispatched(EarlyRefreshScheduled::class, function (EarlyRefreshScheduled $event) {
            return $event->mode === 'inline';
        });
        Event::assertDispatched(EarlyRefreshCompleted::class);
    }

    public function test_inline_mode_failure_serves_stale_keeps_lock_and_fires_event()
    {
        $this->seedEnvelope('stale');
        Event::fake([EarlyRefreshFailed::class, EarlyRefreshCompleted::class]);

        $value = $this->strategy(self::ALWAYS, ['refresh' => 'inline'], null, null, $this->expectReported('boom'))->remember('k', 100, function () {
            throw new RuntimeException('boom');
        });

        $this->assertSame('stale', $value);
        $this->assertSame('stale', Envelope::fromCache($this->repository->get('k'))->value);
        $this->assertFalse($this->store->lock('stampede:refresh:k', 1)->get(), 'refresh lock stays held until refresh_lock_seconds runs out');
        Event::assertDispatched(EarlyRefreshFailed::class, function (EarlyRefreshFailed $event) {
            return $event->mode === 'inline' && $event->exception->getMessage() === 'boom';
        });
        Event::assertNotDispatched(EarlyRefreshCompleted::class);
    }

    public function test_inline_mode_scheduled_listener_failure_serves_stale_and_keeps_lock()
    {
        $this->seedEnvelope('stale');
        Event::fake([EarlyRefreshFailed::class]);
        $this->app['events']->listen(EarlyRefreshScheduled::class, function () {
            throw new RuntimeException('listener boom');
        });

        $calls = 0;

        $value = $this->strategy(self::ALWAYS, ['refresh' => 'inline'], null, null, $this->expectReported('listener boom'))->remember('k', 100, function () use (&$calls) {
            $calls++;

            return 'fresh';
        });

        $this->assertSame('stale', $value);
        $this->assertSame(0, $calls, 'the callback must not run after the listener threw');
        $this->assertFalse($this->store->lock('stampede:refresh:k', 1)->get(), 'refresh lock stays held until refresh_lock_seconds runs out');
        $this->assertSame('stale', Envelope::fromCache($this->repository->get('k'))->value);
        Event::assertDispatched(EarlyRefreshFailed::class, function (EarlyRefreshFailed $event) {
            return $event->mode === 'inline';
        });
    }

    public function test_queue_dispatch_failure_serves_stale_keeps_lock_and_fires_event()
    {
        $this->seedEnvelope('stale');
        Event::fake([EarlyRefreshFailed::class, EarlyRefreshScheduled::class]);
        $bus = new ThrowingBus;

        $value = $this->strategy(self::ALWAYS, [], null, $bus, $this->expectReported('queue down'))->remember('k', 100, function () {
            return 'fresh';
        });

        $this->assertSame('stale', $value);
        $this->assertSame(1, $bus->attempts);
        $this->assertSame('stale', Envelope::fromCache($this->repository->get('k'))->value);
        $this->assertFalse($this->store->lock('stampede:refresh:k', 1)->get(), 'refresh lock stays held until refresh_lock_seconds runs out');
        Event::assertDispatched(EarlyRefreshFailed::class, function (EarlyRefreshFailed $event) {
            return $event->mode === 'queue' && $event->key === 'k' && $event->exception->getMessage() === 'queue down';
        });
        Event::assertNotDispatched(EarlyRefreshScheduled::class);
    }

    public function test_read_after_failed_dispatch_serves_stale_without_retrying_while_lock_is_held()
    {
        $this->seedEnvelope('stale');
        Event::fake([EarlyRefreshFailed::class]);
        $bus = new ThrowingBus;
        $callback = function () {
            return 'fresh';
        };

        $first = $this->strategy(self::ALWAYS, [], null, $bus)->remember('k', 100, $callback);
        $second = $this->strategy(self::ALWAYS, [], null, $bus)->remember('k', 100, $callback);

        $this->assertSame('stale', $first);
        $this->assertSame('stale', $second);
        $this->assertSame(1, $bus->attempts, 'one dispatch attempt per refresh_lock_seconds window');
        Event::assertDispatchedTimes(EarlyRefreshFailed::class, 1);
    }

    public function test_accepts_date_interval_ttl()
    {
        $this->strategy(self::NEVER)->remember('k', new DateInterval('PT2M'), function () {
            return 'x';
        });

        $this->assertEqualsWithDelta(1700000120.0, Envelope::fromCache($this->repository->get('k'))->expiresAt, 0.001);
    }

    public function test_null_ttl_is_rejected()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->strategy(self::NEVER)->remember('k', null, function () {
            return 'x';
        });
    }

    public function test_zero_ttl_is_rejected()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->strategy(self::NEVER)->remember('k', 0, function () {
            return 'x';
        });
    }

    public function test_store_without_locks_is_rejected()
    {
        $repository = new Repository(new LocklessStore);

        $this->expectException(UnsupportedStoreException::class);

        $this->strategy(self::NEVER, [], $repository)->remember('k', 100, function () {
            return 'x';
        });
    }

    public function test_store_without_locks_is_rejected_before_a_foreign_value_is_touched()
    {
        $repository = new Repository(new LocklessStore);
        $repository->put('k', 'plain-value', 1000);

        try {
            $this->strategy(self::NEVER, [], $repository)->remember('k', 100, function () {
                return 'x';
            });
            $this->fail('UnsupportedStoreException expected');
        } catch (UnsupportedStoreException $e) {
            $this->assertSame('plain-value', $repository->get('k'));
        }
    }

    public function test_physical_ttl_is_ttl_plus_grace()
    {
        Carbon::setTestNow();
        $this->strategy(self::NEVER, ['grace_seconds' => 1])->remember('k', 1, function () {
            return 'x';
        });

        sleep(1);
        $this->assertNotNull($this->repository->get('k'), 'still physically present inside the grace window');
        sleep(2);
        $this->assertNull($this->repository->get('k'));
    }
}
