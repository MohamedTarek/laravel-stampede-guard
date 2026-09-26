<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Unit\Strategies;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use MohamedTarek\Stampede\Events\LockAcquired;
use MohamedTarek\Stampede\Events\LockWaited;
use MohamedTarek\Stampede\Exceptions\UnsupportedStoreException;
use MohamedTarek\Stampede\Strategies\LockRemember;
use MohamedTarek\Stampede\Support\Options;
use MohamedTarek\Stampede\Tests\Support\HookedArrayStore;
use MohamedTarek\Stampede\Tests\Support\LocklessStore;
use MohamedTarek\Stampede\Tests\TestCase;
use RuntimeException;

class LockRememberTest extends TestCase
{
    /** @var Repository */
    private $repository;

    /** @var HookedArrayStore */
    private $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new HookedArrayStore;
        $this->repository = new Repository($this->store);
    }

    private function strategy(array $overrides = [], ?Repository $repository = null): LockRemember
    {
        return new LockRemember(
            $repository ?: $this->repository,
            Options::lock(config('stampede'), $overrides),
            $this->app['events'],
            'array'
        );
    }

    public function test_computes_and_caches_on_miss()
    {
        $calls = 0;
        $value = $this->strategy()->remember('k', 60, function () use (&$calls) {
            $calls++;

            return 'computed';
        });

        $this->assertSame('computed', $value);
        $this->assertSame(1, $calls);
        $this->assertSame('computed', $this->repository->get('k'));
    }

    public function test_returns_cached_value_without_calling_back()
    {
        $this->repository->put('k', 'cached', 60);

        $value = $this->strategy()->remember('k', 60, function () {
            $this->fail('callback must not run on a hit');
        });

        $this->assertSame('cached', $value);
    }

    public function test_falsy_values_are_hits()
    {
        foreach ([0, false, [], ''] as $i => $falsy) {
            $this->repository->put("k{$i}", $falsy, 60);

            $value = $this->strategy()->remember("k{$i}", 60, function () {
                $this->fail('callback must not run for a cached falsy value');
            });

            $this->assertSame($falsy, $value);
        }
    }

    public function test_null_from_callback_is_rejected_and_nothing_is_cached()
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->strategy()->remember('k', 60, function () {
                return null;
            });
        } finally {
            $this->assertFalse($this->repository->has('k'));
        }
    }

    public function test_store_without_locks_is_rejected()
    {
        $this->expectException(UnsupportedStoreException::class);

        $this->strategy([], new Repository(new LocklessStore))->remember('k', 60, function () {
            return 'x';
        });
    }

    public function test_lock_is_released_when_callback_throws()
    {
        try {
            $this->strategy()->remember('k', 60, function () {
                throw new RuntimeException('boom');
            });
            $this->fail('exception expected');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertTrue($this->store->lock('stampede:lock:k', 10)->get(), 'lock should be free again');
        $this->assertFalse($this->repository->has('k'));
    }

    public function test_lock_is_released_after_success()
    {
        $this->strategy()->remember('k', 60, function () {
            return 'x';
        });

        $this->assertTrue($this->store->lock('stampede:lock:k', 10)->get());
    }

    public function test_double_check_returns_value_filled_while_waiting()
    {
        $repository = $this->repository;
        $this->store->afterAcquire = function () use ($repository) {
            $repository->put('k', 'filled-by-other-worker', 60);
        };

        $value = $this->strategy()->remember('k', 60, function () {
            $this->fail('callback must not run when the double-check finds a value');
        });

        $this->assertSame('filled-by-other-worker', $value);
    }

    public function test_timeout_throws_by_default()
    {
        $this->store->lock('stampede:lock:k', 30)->get();

        $this->expectException(LockTimeoutException::class);

        $this->strategy(['wait_seconds' => 0])->remember('k', 60, function () {
            return 'x';
        });
    }

    public function test_timeout_can_fall_back_to_unlocked_compute()
    {
        $this->store->lock('stampede:lock:k', 30)->get();

        $value = $this->strategy(['wait_seconds' => 0, 'on_timeout' => 'compute'])->remember('k', 60, function () {
            return 'computed-anyway';
        });

        $this->assertSame('computed-anyway', $value);
        $this->assertSame('computed-anyway', $this->repository->get('k'));
    }

    public function test_events_on_uncontended_acquire()
    {
        Event::fake([LockAcquired::class, LockWaited::class]);

        $this->strategy()->remember('k', 60, function () {
            return 'x';
        });

        Event::assertDispatched(LockAcquired::class, function (LockAcquired $event) {
            return $event->key === 'k' && $event->store === 'array' && $event->waitedSeconds === 0.0;
        });
        Event::assertNotDispatched(LockWaited::class);
    }

    public function test_events_on_contended_acquire()
    {
        Event::fake([LockAcquired::class, LockWaited::class]);

        // "Someone else" holds the mutex for 1 second; we are allowed to wait up to 3.
        $this->store->lock('stampede:lock:k', 1)->get();

        $this->strategy(['wait_seconds' => 3])->remember('k', 60, function () {
            return 'x';
        });

        Event::assertDispatched(LockWaited::class, function (LockWaited $event) {
            return $event->key === 'k' && $event->waitedSeconds > 0.5;
        });
        Event::assertDispatched(LockAcquired::class);
    }

    public function test_custom_prefix_is_used_for_lock_names()
    {
        $this->strategy(['prefix' => 'app'])->remember('k', 60, function () {
            $this->assertFalse($this->store->lock('app:lock:k', 1)->get(), 'lock should be held under the custom prefix');

            return 'x';
        });
    }

    public function test_ttl_is_passed_through_to_put()
    {
        $this->strategy()->remember('k', 1, function () {
            return 'short-lived';
        });

        $this->assertSame('short-lived', $this->repository->get('k'));
        sleep(2);
        $this->assertNull($this->repository->get('k'));
    }
}
