<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Unit\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use MohamedTarek\Stampede\Support\StoreNameResolver;
use MohamedTarek\Stampede\Tests\TestCase;

class StoreNameResolverTest extends TestCase
{
    public function test_resolves_the_default_store_name()
    {
        $resolver = new StoreNameResolver($this->app['cache']);

        $this->assertSame('array', $resolver->resolve(Cache::store()));
    }

    public function test_resolves_a_named_store()
    {
        $this->app['config']->set('cache.stores.second', ['driver' => 'array']);
        $resolver = new StoreNameResolver($this->app['cache']);

        $this->assertSame('second', $resolver->resolve(Cache::store('second')));
    }

    public function test_returns_null_for_a_repository_the_manager_does_not_know()
    {
        $resolver = new StoreNameResolver($this->app['cache']);

        $this->assertNull($resolver->resolve(new Repository(new ArrayStore)));
    }

    public function test_does_not_instantiate_unresolved_stores()
    {
        $this->app['config']->set('cache.stores.broken', ['driver' => 'no-such-driver']);
        $resolver = new StoreNameResolver($this->app['cache']);

        $this->assertSame('array', $resolver->resolve(Cache::store()));
    }
}
