<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Benchmark;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use MohamedTarek\Stampede\Tests\Concurrency\ForksWorkers;
use MohamedTarek\Stampede\Tests\Integration\RedisTestCase;

/**
 * Reader latency on a WARM key while it crosses its logical expiry several times.
 *
 * Not part of the default suites (phpunit.xml lists Unit, Integration and Concurrency
 * only); run it by hand with `vendor/bin/phpunit tests/Benchmark`. It needs Redis,
 * pcntl and posix, forks READERS reader processes that call the strategy in a tight
 * loop for DURATION seconds, and, for queue mode, one process that runs the Redis
 * queue worker. Each reader records every call's latency in a Redis list; the parent
 * aggregates and prints p50 / p99 / max and how many reads waited for a recompute.
 *
 * The callback sleeps COMPUTE_MS, so any read that took roughly COMPUTE_MS or longer
 * paid for a recompute (its own, or waited on someone else's under the mutex).
 */
class WarmKeyLatencyTest extends RedisTestCase
{
    use ForksWorkers;

    const READERS = 8;

    const DURATION = 12;      // seconds each reader keeps reading

    const TTL = 3;            // seconds; the run crosses expiry about four times

    const COMPUTE_MS = 300;   // callback cost

    const PAUSE_US = 15000;   // pause between reads per reader (~65 reads/s each)

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('queue.default', 'redis');
        $app['config']->set('queue.connections.redis', [
            'driver' => 'redis',
            'connection' => 'cache',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => null,
        ]);
    }

    public function test_warm_key_latency_across_expiry()
    {
        $results = [];

        $results['Cache::rememberWithLock'] = $this->measure('lock', function () {
            return Cache::store('redis')->rememberWithLock('hot', self::TTL, function () {
                usleep(self::COMPUTE_MS * 1000);

                return 'payload';
            });
        }, false);

        $results['Cache::rememberXFetch (inline)'] = $this->measure('inline', function () {
            return Cache::store('redis')->rememberXFetch('hot', self::TTL, function () {
                usleep(self::COMPUTE_MS * 1000);

                return 'payload';
            }, ['refresh' => 'inline', 'grace_seconds' => 30]);
        }, false);

        $results['Cache::rememberXFetch (queue)'] = $this->measure('queue', function () {
            return Cache::store('redis')->rememberXFetch('hot', self::TTL, function () {
                usleep(self::COMPUTE_MS * 1000);

                return 'payload';
            }, ['refresh' => 'queue', 'grace_seconds' => 30]);
        }, true);

        $this->printTable($results);

        // Each strategy served every read; the lock strategy made readers wait for the
        // recompute, XFetch in queue mode made none of them wait.
        foreach ($results as $name => $r) {
            $this->assertGreaterThan(0, $r['reads'], $name.' recorded no reads');
        }
        $this->assertGreaterThan(0, $results['Cache::rememberWithLock']['slow']);
        $this->assertSame(0, $results['Cache::rememberXFetch (queue)']['slow']);
    }

    /** @return array{reads:int,p50:float,p99:float,max:float,slow:int} */
    private function measure(string $label, \Closure $read, bool $withWorker): array
    {
        $redis = Cache::store('redis')->getStore()->connection();
        $redis->flushdb();
        $listKey = 'bench:'.$label;

        // Warm the key so the run starts from a cached value, not a cold fill.
        $read();

        $deadline = microtime(true) + self::DURATION;
        $workers = self::READERS + ($withWorker ? 1 : 0);

        $result = $this->fork($workers, function ($i) use ($read, $deadline, $listKey, $withWorker) {
            if ($withWorker && $i === 0) {
                while (microtime(true) < $deadline) {
                    Artisan::call('queue:work', [
                        'connection' => 'redis',
                        '--stop-when-empty' => true,
                        '--sleep' => 0,
                        '--tries' => 1,
                    ]);
                    usleep(20000);
                }

                return true;
            }

            $conn = Cache::store('redis')->getStore()->connection();
            $samples = [];

            while (microtime(true) < $deadline) {
                $t = microtime(true);
                $value = $read();
                $samples[] = (int) round((microtime(true) - $t) * 1000000);

                if ($value !== 'payload') {
                    return false;
                }

                usleep(self::PAUSE_US);
            }

            foreach (array_chunk($samples, 500) as $chunk) {
                $conn->rpush($listKey, ...$chunk);
            }

            return true;
        });

        $this->assertSame(0, $result['failed'], $label.': a worker failed');

        $samples = array_map('intval', $redis->lrange($listKey, 0, -1));
        sort($samples);
        $n = count($samples);
        $slowThreshold = (int) (self::COMPUTE_MS * 0.8 * 1000);

        return [
            'reads' => $n,
            'p50' => $n ? $samples[(int) floor($n * 0.50)] / 1000 : 0.0,
            'p99' => $n ? $samples[min($n - 1, (int) floor($n * 0.99))] / 1000 : 0.0,
            'max' => $n ? $samples[$n - 1] / 1000 : 0.0,
            'slow' => count(array_filter($samples, function ($us) use ($slowThreshold) {
                return $us >= $slowThreshold;
            })),
        ];
    }

    /** @param array<string, array{reads:int,p50:float,p99:float,max:float,slow:int}> $results */
    private function printTable(array $results): void
    {
        $out = sprintf(
            "\nWarm key, %d readers, %ds, ttl %ds, callback %dms\n%-32s %8s %9s %9s %9s %12s\n",
            self::READERS, self::DURATION, self::TTL, self::COMPUTE_MS,
            'strategy', 'reads', 'p50 ms', 'p99 ms', 'max ms', 'waited>=240ms'
        );
        foreach ($results as $name => $r) {
            $out .= sprintf("%-32s %8d %9.2f %9.2f %9.2f %12d\n", $name, $r['reads'], $r['p50'], $r['p99'], $r['max'], $r['slow']);
        }
        fwrite(STDOUT, $out);
    }
}
