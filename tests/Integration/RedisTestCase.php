<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Integration;

use Illuminate\Support\Facades\Cache;
use MohamedTarek\Stampede\Tests\TestCase;
use Predis\Client;
use Throwable;

abstract class RedisTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis') && ! class_exists(Client::class)) {
            $this->markTestSkipped('Neither phpredis nor predis is installed.');
        }

        try {
            Cache::store('redis')->getStore()->connection()->flushdb();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis is not reachable: '.$e->getMessage());
        }
    }
}
