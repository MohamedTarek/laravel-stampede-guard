<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests;

use MohamedTarek\Stampede\StampedeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [StampedeServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.stores.redis', [
            'driver' => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'cache',
        ]);
        $app['config']->set('database.redis.cache', [
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'database' => 1,
        ]);
    }
}
