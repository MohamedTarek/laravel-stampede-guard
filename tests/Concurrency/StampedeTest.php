<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Concurrency;

use Illuminate\Support\Facades\Cache;
use MohamedTarek\Stampede\Tests\Integration\RedisTestCase;

/**
 * The claim this package makes, measured: N workers hit a cold key at once,
 * the expensive callback runs once. The control test shows plain remember() does not.
 */
class StampedeTest extends RedisTestCase
{
    use ForksWorkers;

    const WORKERS = 50;

    private function callbackCount(): int
    {
        return (int) Cache::store('redis')->get('calls', 0);
    }

    public function test_remember_with_lock_computes_once_under_contention()
    {
        $result = $this->fork(self::WORKERS, function () {
            $value = Cache::store('redis')->rememberWithLock('hot', 60, function () {
                Cache::store('redis')->increment('calls');
                usleep(500000);

                return 'payload';
            }, ['wait_seconds' => 10]);

            return $value === 'payload';
        });

        $this->assertSame(['ok' => self::WORKERS, 'failed' => 0], $result);
        $this->assertSame(1, $this->callbackCount());
    }

    public function test_remember_xfetch_cold_start_computes_once_under_contention()
    {
        $result = $this->fork(self::WORKERS, function () {
            $value = Cache::store('redis')->rememberXFetch('hot', 60, function () {
                Cache::store('redis')->increment('calls');
                usleep(500000);

                return 'payload';
            }, ['wait_seconds' => 10]);

            return $value === 'payload';
        });

        $this->assertSame(['ok' => self::WORKERS, 'failed' => 0], $result);
        $this->assertSame(1, $this->callbackCount());
    }

    public function test_control_plain_remember_stampedes()
    {
        $result = $this->fork(self::WORKERS, function () {
            $value = Cache::store('redis')->remember('hot', 60, function () {
                Cache::store('redis')->increment('calls');
                usleep(500000);

                return 'payload';
            });

            return $value === 'payload';
        });

        $this->assertSame(['ok' => self::WORKERS, 'failed' => 0], $result);
        $this->assertGreaterThan(1, $this->callbackCount(), 'plain remember() should have stampeded');
        fwrite(STDOUT, sprintf("\nplain remember(): callback ran %d times for %d workers\n", $this->callbackCount(), self::WORKERS));
    }
}
