<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Unit\Macros;

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use MohamedTarek\Stampede\Jobs\RefreshCacheJob;
use MohamedTarek\Stampede\Support\Envelope;
use MohamedTarek\Stampede\Support\MtRandomSource;
use MohamedTarek\Stampede\Support\RandomSource;
use MohamedTarek\Stampede\Tests\Support\FixedRandomSource;
use MohamedTarek\Stampede\Tests\TestCase;

class RememberXFetchMacroTest extends TestCase
{
    public function test_macro_is_available_on_the_facade()
    {
        $value = Cache::rememberXFetch('k', 60, function () {
            return 'via-macro';
        });

        $this->assertSame('via-macro', $value);
        $this->assertSame('via-macro', Envelope::fromCache(Cache::get('k'))->value);
    }

    public function test_random_source_is_bound_to_mt_by_default()
    {
        $this->assertInstanceOf(MtRandomSource::class, $this->app->make(RandomSource::class));
    }

    public function test_random_source_can_be_swapped_in_the_container()
    {
        Bus::fake();
        $this->app->instance(RandomSource::class, new FixedRandomSource(1e-80));
        Cache::put('k', (new Envelope('stale', microtime(true) + 60, 1.0))->toArray(), 1000);

        $value = Cache::rememberXFetch('k', 60, function () {
            return 'fresh';
        });

        $this->assertSame('stale', $value);
        Bus::assertDispatched(RefreshCacheJob::class);
    }

    public function test_named_store_is_carried_into_the_job()
    {
        Bus::fake();
        $this->app['config']->set('cache.stores.second', ['driver' => 'array']);
        $this->app->instance(RandomSource::class, new FixedRandomSource(1e-80));
        Cache::store('second')->put('k', (new Envelope('stale', microtime(true) + 60, 1.0))->toArray(), 1000);

        Cache::store('second')->rememberXFetch('k', 60, function () {
            return 'fresh';
        });

        Bus::assertDispatched(RefreshCacheJob::class, function (RefreshCacheJob $job) {
            return $job->store === 'second';
        });
    }

    public function test_store_option_overrides_detection()
    {
        Bus::fake();
        $this->app['config']->set('cache.stores.second', ['driver' => 'array']);
        $this->app->instance(RandomSource::class, new FixedRandomSource(1e-80));
        Cache::put('k', (new Envelope('stale', microtime(true) + 60, 1.0))->toArray(), 1000);

        Cache::rememberXFetch('k', 60, function () {
            return 'fresh';
        }, ['store' => 'second']);

        Bus::assertDispatched(RefreshCacheJob::class, function (RefreshCacheJob $job) {
            return $job->store === 'second';
        });
    }

    public function test_tagged_cache_is_rejected()
    {
        try {
            Cache::tags(['reports'])->rememberXFetch('k', 60, function () {
                return 'x';
            });
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tagged caches are not supported', $e->getMessage());
        }

        $this->assertNull(Cache::tags(['reports'])->get('k'));
    }

    public function test_tagged_cache_is_rejected_in_inline_mode_and_with_a_store_option()
    {
        foreach ([['refresh' => 'inline'], ['store' => 'array']] as $options) {
            try {
                Cache::tags(['reports'])->rememberXFetch('k', 60, function () {
                    return 'x';
                }, $options);
                $this->fail('InvalidArgumentException expected');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('tagged caches are not supported', $e->getMessage());
            }
        }
    }

    public function test_unnamed_repository_in_queue_mode_is_rejected()
    {
        Bus::fake();
        $repository = Cache::repository(new ArrayStore);

        try {
            $repository->rememberXFetch('k', 60, function () {
                return 'x';
            }, ['refresh' => 'queue']);
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('"store" option', $e->getMessage());
            $this->assertStringContainsString('inline', $e->getMessage());
        }

        $this->assertNull($repository->get('k'));
        $this->assertNull(Cache::get('k'), 'the default store must be untouched');
        Bus::assertNotDispatched(RefreshCacheJob::class);
    }

    public function test_unnamed_repository_in_inline_mode_fills_and_refreshes()
    {
        Bus::fake();
        $this->app->instance(RandomSource::class, new FixedRandomSource(1e-80));
        $repository = Cache::repository(new ArrayStore);

        $first = $repository->rememberXFetch('cold', 60, function () {
            return 'first';
        }, ['refresh' => 'inline']);
        // A seeded envelope with a 1s compute time, so the fixed random source volunteers.
        $repository->put('k', (new Envelope('stale', microtime(true) + 60, 1.0))->toArray(), 1000);
        $refreshed = $repository->rememberXFetch('k', 60, function () {
            return 'refreshed';
        }, ['refresh' => 'inline']);

        $this->assertSame('first', $first);
        $this->assertSame('first', Envelope::fromCache($repository->get('cold'))->value);
        $this->assertSame('refreshed', $refreshed);
        $this->assertSame('refreshed', Envelope::fromCache($repository->get('k'))->value);
        $this->assertNull(Cache::get('k'), 'the default store must be untouched');
        Bus::assertNotDispatched(RefreshCacheJob::class);
    }

    public function test_unnamed_repository_with_a_store_option_is_accepted_in_queue_mode()
    {
        Bus::fake();
        $this->app->instance(RandomSource::class, new FixedRandomSource(1e-80));
        $repository = Cache::repository(new ArrayStore);
        $repository->put('k', (new Envelope('stale', microtime(true) + 60, 1.0))->toArray(), 1000);

        $value = $repository->rememberXFetch('k', 60, function () {
            return 'fresh';
        }, ['refresh' => 'queue', 'store' => 'array']);

        $this->assertSame('stale', $value);
        Bus::assertDispatched(RefreshCacheJob::class, function (RefreshCacheJob $job) {
            return $job->store === 'array';
        });
    }

    public function test_end_to_end_with_sync_queue_refreshes_the_default_store()
    {
        $this->app->instance(RandomSource::class, new FixedRandomSource(1e-80));
        Cache::put('k', (new Envelope('stale', microtime(true) + 60, 1.0))->toArray(), 1000);

        $value = Cache::rememberXFetch('k', 60, function () {
            return 'fresh';
        });

        $this->assertSame('stale', $value);
        $this->assertSame('fresh', Envelope::fromCache(Cache::get('k'))->value);
        $this->assertTrue(Cache::getStore()->lock('stampede:refresh:k', 1)->get());
    }
}
