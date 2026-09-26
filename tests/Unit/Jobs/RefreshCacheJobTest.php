<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Unit\Jobs;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use MohamedTarek\Stampede\Events\EarlyRefreshCompleted;
use MohamedTarek\Stampede\Events\EarlyRefreshFailed;
use MohamedTarek\Stampede\Jobs\RefreshCacheJob;
use MohamedTarek\Stampede\Support\ClosureSerializer;
use MohamedTarek\Stampede\Support\Envelope;
use MohamedTarek\Stampede\Support\Options;
use MohamedTarek\Stampede\Tests\TestCase;
use RuntimeException;

class RefreshCacheJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function job(callable $callback, array $overrides = [], ?string $store = null): array
    {
        $repository = Cache::store($store);
        $lock = $repository->getStore()->lock('stampede:refresh:k', 60);
        $this->assertTrue($lock->get(), 'test precondition: refresh lock acquired');

        $job = new RefreshCacheJob(
            'k',
            120,
            Options::xfetch(config('stampede'), $overrides)->toArray(),
            $store,
            ClosureSerializer::wrap($callback),
            $lock->owner()
        );

        return [$job, $repository];
    }

    private function runJob(RefreshCacheJob $job): void
    {
        $job->handle($this->app->make(Factory::class), $this->app['events']);
    }

    public function test_rewrites_the_envelope_and_releases_the_lock()
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1700000000));
        [$job, $repository] = $this->job(function () {
            return 'fresh';
        });

        $this->runJob($job);

        $envelope = Envelope::fromCache($repository->get('k'));
        $this->assertNotNull($envelope);
        $this->assertSame('fresh', $envelope->value);
        $this->assertEqualsWithDelta(1700000120.0, $envelope->expiresAt, 0.001);
        $this->assertTrue($repository->getStore()->lock('stampede:refresh:k', 1)->get(), 'refresh lock must be released');
    }

    public function test_fires_completed_event()
    {
        Event::fake([EarlyRefreshCompleted::class]);
        [$job] = $this->job(function () {
            return 'fresh';
        });

        $this->runJob($job);

        Event::assertDispatched(EarlyRefreshCompleted::class, function (EarlyRefreshCompleted $event) {
            return $event->key === 'k' && $event->mode === 'queue' && $event->computeSeconds >= 0.0;
        });
    }

    public function test_failure_fires_event_releases_lock_and_rethrows()
    {
        Event::fake([EarlyRefreshFailed::class]);
        [$job, $repository] = $this->job(function () {
            throw new RuntimeException('db down');
        });

        try {
            $this->runJob($job);
            $this->fail('exception expected');
        } catch (RuntimeException $e) {
            $this->assertSame('db down', $e->getMessage());
        }

        Event::assertDispatched(EarlyRefreshFailed::class, function (EarlyRefreshFailed $event) {
            return $event->exception->getMessage() === 'db down' && $event->mode === 'queue';
        });
        $this->assertTrue($repository->getStore()->lock('stampede:refresh:k', 1)->get());
        $this->assertNull($repository->get('k'));
    }

    public function test_null_result_is_rejected()
    {
        [$job, $repository] = $this->job(function () {
            return null;
        });

        try {
            $this->runJob($job);
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->assertTrue($repository->getStore()->lock('stampede:refresh:k', 1)->get(), 'refresh lock must be released');
        $this->assertNull($repository->get('k'));
    }

    public function test_handle_survives_expired_lock()
    {
        [$job, $repository] = $this->job(function () {
            return 'late-but-fine';
        });
        $repository->getStore()->lock('stampede:refresh:k', 60)->forceRelease();

        $this->runJob($job);

        $this->assertSame('late-but-fine', Envelope::fromCache($repository->get('k'))->value);
    }

    public function test_handle_leaves_a_lock_reacquired_by_a_new_owner_alone()
    {
        [$job, $repository] = $this->job(function () {
            return 'late-but-fine';
        });
        $store = $repository->getStore();
        $store->lock('stampede:refresh:k', 60)->forceRelease();
        $this->assertTrue($store->lock('stampede:refresh:k', 60)->get(), 'test precondition: a new owner takes the lock');

        $this->runJob($job);

        $this->assertFalse($store->lock('stampede:refresh:k', 60)->get(), 'the new owner still holds the lock');
        $this->assertSame('late-but-fine', Envelope::fromCache($repository->get('k'))->value);
    }

    public function test_job_survives_serialization()
    {
        [$job] = $this->job(function () {
            return 'from-queue';
        });

        $restored = unserialize(serialize($job));
        $this->runJob($restored);

        $this->assertSame('from-queue', Envelope::fromCache(Cache::get('k'))->value);
    }

    public function test_uses_the_named_store()
    {
        $this->app['config']->set('cache.stores.second', ['driver' => 'array']);
        [$job] = $this->job(function () {
            return 'second';
        }, [], 'second');

        $this->runJob($job);

        $this->assertNull(Cache::get('k'));
        $this->assertSame('second', Envelope::fromCache(Cache::store('second')->get('k'))->value);
    }

    public function test_physical_ttl_includes_grace()
    {
        [$job, $repository] = $this->job(function () {
            return 'x';
        }, ['grace_seconds' => 2]);
        $job = new RefreshCacheJob($job->key, 1, $job->options, $job->store, $job->callback, $job->lockOwner);

        $this->runJob($job);

        sleep(2);
        $this->assertNotNull($repository->get('k'), 'past the ttl of 1s but inside the 2s grace window');
        sleep(2);
        $this->assertNull($repository->get('k'), 'ttl 1 + grace 2 must expire after ~3s');
    }
}
