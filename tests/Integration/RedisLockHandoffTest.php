<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Integration;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use MohamedTarek\Stampede\Jobs\RefreshCacheJob;
use MohamedTarek\Stampede\Support\Envelope;
use MohamedTarek\Stampede\Support\RandomSource;
use MohamedTarek\Stampede\Tests\Support\FixedRandomSource;

class RedisLockHandoffTest extends RedisTestCase
{
    public function test_lock_remember_round_trips_on_redis()
    {
        $value = Cache::store('redis')->rememberWithLock('k', 60, function () {
            return ['rows' => 3];
        });

        $this->assertSame(['rows' => 3], $value);
        $this->assertSame(['rows' => 3], Cache::store('redis')->get('k'));
        $this->assertTrue(Cache::store('redis')->getStore()->lock('stampede:lock:k', 1)->get(), 'lock released');
    }

    public function test_job_releases_a_lock_taken_by_the_request_via_restore_lock()
    {
        $this->app->instance(RandomSource::class, new FixedRandomSource(1e-80));
        Cache::store('redis')->put('k', (new Envelope('stale', microtime(true) + 60, 1.0))->toArray(), 1000);

        $value = Cache::store('redis')->rememberXFetch('k', 60, function () {
            return 'fresh';
        });

        $this->assertSame('stale', $value);
        $this->assertSame('fresh', Envelope::fromCache(Cache::store('redis')->get('k'))->value);
        $this->assertTrue(Cache::store('redis')->getStore()->lock('stampede:refresh:k', 1)->get(), 'sync job released the lock');
    }

    public function test_queued_job_carries_the_redis_store_name()
    {
        Bus::fake();
        $this->app->instance(RandomSource::class, new FixedRandomSource(1e-80));
        Cache::store('redis')->put('k', (new Envelope('stale', microtime(true) + 60, 1.0))->toArray(), 1000);

        Cache::store('redis')->rememberXFetch('k', 60, function () {
            return 'fresh';
        });

        Bus::assertDispatched(RefreshCacheJob::class, function (RefreshCacheJob $job) {
            return $job->store === 'redis';
        });
        $this->assertFalse(Cache::store('redis')->getStore()->lock('stampede:refresh:k', 1)->get(), 'lock still held for the worker');
    }

    public function test_inline_refresh_on_redis()
    {
        $this->app->instance(RandomSource::class, new FixedRandomSource(1e-80));
        Cache::store('redis')->put('k', (new Envelope('stale', microtime(true) + 60, 1.0))->toArray(), 1000);

        $value = Cache::store('redis')->rememberXFetch('k', 60, function () {
            return 'fresh';
        }, ['refresh' => 'inline']);

        $this->assertSame('fresh', $value);
        $this->assertTrue(Cache::store('redis')->getStore()->lock('stampede:refresh:k', 1)->get());
    }
}
