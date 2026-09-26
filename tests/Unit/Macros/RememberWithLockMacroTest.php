<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Unit\Macros;

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use MohamedTarek\Stampede\Events\LockAcquired;
use MohamedTarek\Stampede\Tests\TestCase;

class RememberWithLockMacroTest extends TestCase
{
    public function test_macro_is_available_on_the_facade()
    {
        $value = Cache::rememberWithLock('k', 60, function () {
            return 'via-macro';
        });

        $this->assertSame('via-macro', $value);
        $this->assertSame('via-macro', Cache::get('k'));
    }

    public function test_macro_is_available_on_a_named_store_and_reports_its_name()
    {
        $this->app['config']->set('cache.stores.second', ['driver' => 'array']);
        Event::fake([LockAcquired::class]);

        Cache::store('second')->rememberWithLock('k', 60, function () {
            return 'second';
        });

        $this->assertNull(Cache::get('k'), 'default store must be untouched');
        $this->assertSame('second', Cache::store('second')->get('k'));
        Event::assertDispatched(LockAcquired::class, function (LockAcquired $event) {
            return $event->store === 'second';
        });
    }

    public function test_per_call_options_are_validated()
    {
        $this->expectException(InvalidArgumentException::class);

        Cache::rememberWithLock('k', 60, function () {
            return 'x';
        }, ['beta' => 2.0]);
    }

    public function test_tagged_cache_is_rejected()
    {
        try {
            Cache::tags(['reports'])->rememberWithLock('k', 60, function () {
                return 'x';
            });
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tagged caches are not supported', $e->getMessage());
        }

        $this->assertNull(Cache::tags(['reports'])->get('k'));
    }

    public function test_unnamed_repository_is_accepted()
    {
        $repository = Cache::repository(new ArrayStore);

        $value = $repository->rememberWithLock('k', 60, function () {
            return 'unnamed';
        });

        $this->assertSame('unnamed', $value);
        $this->assertSame('unnamed', $repository->get('k'));
    }

    public function test_config_changes_are_picked_up_per_call()
    {
        $this->app['config']->set('stampede.prefix', 'custom');

        Cache::rememberWithLock('k', 60, function () {
            $this->assertFalse(Cache::getStore()->lock('custom:lock:k', 1)->get());

            return 'x';
        });
    }
}
