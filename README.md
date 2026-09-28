<div align="center">

# Laravel Stampede Guard

**Stop cache stampedes in Laravel: one worker recomputes, everyone else keeps getting a value.**

[![Packagist Version](https://img.shields.io/packagist/v/mohamedtarek/laravel-stampede-guard?label=packagist)](https://packagist.org/packages/mohamedtarek/laravel-stampede-guard)
[![Total Downloads](https://img.shields.io/packagist/dt/mohamedtarek/laravel-stampede-guard)](https://packagist.org/packages/mohamedtarek/laravel-stampede-guard)
[![Tests](https://github.com/MohamedTarek/laravel-stampede-guard/actions/workflows/tests.yml/badge.svg)](https://github.com/MohamedTarek/laravel-stampede-guard/actions/workflows/tests.yml)
[![PHP 7.2 to 8.5](https://img.shields.io/badge/PHP-7.2%20to%208.5-777BB4?logo=php&logoColor=white)](#compatibility)
[![Laravel 6 to 13](https://img.shields.io/badge/Laravel-6%20to%2013-FF2D20?logo=laravel&logoColor=white)](#compatibility)
[![License MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

</div>

Two macros on Laravel's cache repository. `Cache::rememberWithLock()` puts an atomic lock
around the recompute so a cold key is computed exactly once. `Cache::rememberXFetch()`
refreshes a hot key *before* it expires, probabilistically and in the background, so no
reader ever sees a miss after the first fill. Works from Laravel 6 to Laravel 13.

The design, the lock handoff to a queued job, and the measurements are written up in
[Cache stampedes in Laravel: why a lock isn't enough](https://medium.com/@mt.elafifi/cache-stampedes-in-laravel-why-a-lock-isnt-enough-2111080ad4fc).

## The problem

A cache stampede (also called a thundering herd or dogpile) happens when a cached value
expires and every concurrent request reads a miss at the same instant. Each one recomputes
the same expensive value and hits the database at the same moment. Plain `Cache::remember()`
has no protection against this.

![The problem: a cache stampede](https://cdn.jsdelivr.net/gh/MohamedTarek/laravel-stampede-guard@main/art/stampede-problem.svg)

Background and the motivating incident are described in
["Backend Architecture: Laravel Cache Stampede Prevention"](https://smarttechdevs.hashnode.dev/backend-architecture-laravel-cache-stampede-prevention)
(Smart Tech Devs Engineering).

## Measured, not claimed

`tests/Concurrency/StampedeTest.php` forks 50 worker processes against Redis 7 that all hit
the same cold key at once, with a callback that sleeps 0.5 seconds before incrementing a
counter. Results from a real run:

| Strategy                                 | Callback calls for 50 concurrent workers |
|:-----------------------------------------|-----------------------------------------:|
| `Cache::remember` (plain, no protection) |                                       50 |
| `rememberWithLock`                       |                                        1 |
| `rememberXFetch` (cold start)            |                                        1 |

The same suite runs on every CI leg: 97 tests across Laravel 6 to 13, unit tests on the
array store, integration tests on Redis, and the fork proof above.

The cold-key test proves the callback runs once. The latency claim for a warm key is
measured separately by `tests/Benchmark/WarmKeyLatencyTest.php`: 8 reader processes call
the strategy in a loop for 12 seconds while a 3-second TTL expires four times, with a
300 ms callback and, for queue mode, a real `queue:work` process on Redis. Every read's
latency is recorded.

| Strategy                          | Reads | p50     | p99     | Worst read | Reads that waited for a recompute |
|:----------------------------------|------:|--------:|--------:|-----------:|----------------------------------:|
| `rememberWithLock`                | 4,303 | 0.86 ms | 2.91 ms |  2,015 ms  |                                24 |
| `rememberXFetch`, inline refresh  | 5,625 | 0.99 ms | 2.38 ms |    309 ms  |                                 7 |
| `rememberXFetch`, queued refresh  | 5,761 | 0.96 ms | 2.02 ms |     55 ms  |                                 0 |

Under the mutex, every reader that arrives during a recompute waits for it, so each
expiry stalls a handful of requests. Inline XFetch stalls exactly one request per
refresh, the volunteer. Queued XFetch stalls none: the worker pays. Run it yourself with
`vendor/bin/phpunit tests/Benchmark` (needs Redis, `pcntl` and `posix`; it is not part
of the default suites).

## Two strategies

| | `rememberWithLock` | `rememberXFetch` |
|:--|:--|:--|
| Mechanism | Blocking mutex with double-checked reads | Probabilistic early refresh (XFetch) |
| Cold key | One worker computes, the rest wait for the lock | Same mutex as `rememberWithLock` |
| Warm key near expiry | Everyone gets the value until it expires, then one recompute | One reader refreshes *before* expiry; readers never wait |
| Latency for readers | The waiters block during the recompute | Always the cached value (queue mode) or fresh (inline mode) |
| Needs a queue | No | Optional (default queue, can run inline) |
| Reach for it when | The value is cheap enough that a short wait is fine, or you cannot run a queue | The value is slow, the key is hot, and a stale-for-seconds value is acceptable |

## How it compares to Laravel's own tools

| | `Cache::remember` | `Cache::flexible` (Laravel 11.23+) | `rememberWithLock` | `rememberXFetch` |
|:--|:--|:--|:--|:--|
| Cold key: workers computing | All of them (stampede) | All of them (stampede) | One; others wait for the lock | One, via the same mutex as `rememberWithLock` |
| Warm key near expiry | Stampede at the moment of expiry | One deterministic refresh, deferred until after the response | Same as a cold key: one recompute per expiry, others wait on the lock | Probabilistic refresh before expiry; readers keep getting the still-valid value |
| Needs a queue | No | No (uses `defer()`, not a queue) | No | Optional (default: queue; can run inline) |
| Min Laravel | Any | 11.23 | 6 | 6 |

## Installation

```bash
composer require mohamedtarek/laravel-stampede-guard
```

Composer picks the release line that matches the `illuminate/cache` version already
required by your application (see [Compatibility](#compatibility)). Publish the config file
if you want to change the defaults:

```bash
php artisan vendor:publish --tag=stampede-config
```

This writes `config/stampede.php`. Every option can also be passed per call.

## Usage

### `rememberWithLock`

`remember()` guarded by an atomic lock with double-checked reads, so a cold key is computed
by exactly one worker while every other worker waits for the result instead of recomputing it.

```php
use Illuminate\Support\Facades\Cache;

$value = Cache::rememberWithLock('report:2026-09', 3600, function () {
    return Report::expensive();
});
```

![rememberWithLock: one computes, the rest wait](https://cdn.jsdelivr.net/gh/MohamedTarek/laravel-stampede-guard@main/art/remember-with-lock.svg)

The macro is added to `Illuminate\Cache\Repository`, so it is available on
`Cache::store('redis')`, `Cache::store('memcached')`, and so on, not only the default
`Cache` facade.

```php
public function rememberWithLock(string $key, $ttl, Closure $callback, array $options = []): mixed
```

Options (merged from `config('stampede.prefix')` and `config('stampede.lock')`, then
per-call overrides):

| Option         | Default       | Meaning |
|:---------------|:--------------|:--------|
| `lock_seconds` | `10`          | Seconds the computing worker may hold the mutex before it expires on its own. |
| `wait_seconds` | `5`           | Seconds other workers block waiting for the mutex before giving up. |
| `on_timeout`   | `'throw'`     | What a waiter does on timeout: `'throw'` (`LockTimeoutException`) or `'compute'` (run the callback unlocked). |
| `prefix`       | `'stampede'`  | Namespace for the lock key: `"{prefix}:lock:{key}"`. |

```php
// Wait at most one second, then compute without the lock rather than fail.
$value = Cache::rememberWithLock('report:2026-09', 3600, fn () => Report::expensive(), [
    'wait_seconds' => 1,
    'on_timeout' => 'compute',
]);
```

If the underlying cache store does not support atomic locks, the call throws
`MohamedTarek\Stampede\Exceptions\UnsupportedStoreException` before any work is done.
A callback that returns `null` throws `InvalidArgumentException`. Laravel's own
`remember()` has no such check: it silently stores nothing useful and recomputes the
callback on every call.

### `rememberXFetch`

Probabilistic early expiration (the XFetch algorithm). A cached value is served until its
logical expiry; as that approaches, one reader "volunteers" (the probability of
volunteering rises with how long the value took to compute) and refreshes it ahead of
time, so no other reader ever sees a cold key after the first fill.

```php
use Illuminate\Support\Facades\Cache;

$value = Cache::rememberXFetch('report:2026-09', 3600, function () {
    return Report::expensive();
});
```

![rememberXFetch: refresh before it expires](https://cdn.jsdelivr.net/gh/MohamedTarek/laravel-stampede-guard@main/art/remember-xfetch.svg)

```php
public function rememberXFetch(string $key, $ttl, callable $callback, array $options = []): mixed
```

Options (merged from `config('stampede.prefix')`, `config('stampede.lock')` and
`config('stampede.xfetch')`, then per-call overrides):

| Option                 | Default   | Meaning |
|:-----------------------|:----------|:--------|
| `beta`                 | `1.0`     | XFetch tuning: values above `1` make workers volunteer earlier, values below `1` make them volunteer later. |
| `grace_seconds`        | `300`     | Physical TTL is `ttl + grace_seconds`. Stale data may be served for this long if refreshes keep failing. |
| `refresh`              | `'queue'` | How the volunteer refreshes: `'queue'` (dispatches `RefreshCacheJob`) or `'inline'` (in the volunteer's own request). |
| `refresh_lock_seconds` | `60`      | Seconds the "someone is refreshing" marker lives; it auto-expires if the job dies. |
| `queue.connection`     | `null`    | Queue connection `RefreshCacheJob` is dispatched on. `null` uses the framework default. |
| `queue.queue`          | `null`    | Queue name `RefreshCacheJob` is dispatched on. `null` uses the framework default. |
| `store`                | `null`    | Cache store name the refresh job reuses. `null` auto-detects the store the call was made on. |

```php
// Refresh in the request itself and pay the compute cost there, no queue involved.
$value = Cache::rememberXFetch('report:2026-09', 3600, fn () => Report::expensive(), [
    'refresh' => 'inline',
    'grace_seconds' => 60,
]);

// Route the background refresh to a dedicated queue.
$value = Cache::rememberXFetch('report:2026-09', 3600, fn () => Report::expensive(), [
    'queue' => ['connection' => 'redis', 'queue' => 'cache-refresh'],
]);
```

#### How the decision is made

Behind the caller's key, the package stores an envelope instead of the raw value:

```php
['v' => $value, 'e' => $logicalExpiryUnixTimestamp, 'd' => $computeSeconds]
```

`v` is the cached value, `e` is when the value logically expires, and `d` is how long the
callback took to compute it the last time it ran. Every read decides whether to volunteer
for an early refresh with:

```
now - d * beta * ln(rand) >= expiry
```

where `rand` is drawn fresh on each call from `(0, 1]`. `beta` scales how aggressively the
package refreshes ahead of expiry: raising it makes early refreshes more likely (and
earlier), lowering it makes the package wait closer to the real expiry before anyone
volunteers.

A key that has never been cached (a cold start) has no envelope to read a compute time
from, so `rememberXFetch` falls back to the same mutex used by `rememberWithLock` for the
first fill, then switches to the probabilistic behaviour above once an envelope exists.

#### Things to know

- **A real TTL is required.** A `null` TTL or a TTL that resolves to zero or fewer seconds
  throws `InvalidArgumentException`, since a value cached forever has no logical expiry to
  refresh ahead of.
- **Read the key only through `rememberXFetch`.** The key holds the envelope above, not your
  value. A plain `Cache::get()` returns the envelope array, and mixing `Cache::remember()` and
  `rememberXFetch()` on the same key makes them overwrite each other's format on every call.
  To move an existing key to `rememberXFetch`, use a new key name.
- **Tagged caches are not supported.** Calling either macro on `Cache::tags([...])` throws
  `InvalidArgumentException`.
- **Unnamed repositories need the `store` option in queue mode.** If the repository was not
  resolved by name through the cache manager (`Cache::repository($store)`,
  `Cache::build([...])`, `Cache::memo()`, a hand-built `new Repository(...)`), the store name
  cannot be detected. In queue mode that throws `InvalidArgumentException` at call time,
  because the job would otherwise refresh the default store; pass the `store` option or use
  `refresh => 'inline'`.
- **Closures are serialized in queue mode.** Capture ids rather than models, connections, or
  other non-serializable values in the callback, the same rule as any other queued job in
  Laravel.

### Queue vs inline refresh

- **Queue mode** (`refresh => 'queue'`, the default): the volunteering request returns the
  still-cached (stale) value immediately and dispatches `RefreshCacheJob` to recompute in
  the background.
- **Inline mode** (`refresh => 'inline'`): the volunteering request itself recomputes the
  value synchronously and returns the freshly computed value, not the stale one.

On the `sync` queue connection, `RefreshCacheJob` runs inside the dispatching request
rather than on a worker, so `EarlyRefreshCompleted` fires before `EarlyRefreshScheduled`
for that request.

When an early refresh fails, readers keep getting the stale value instead of an exception:

- If the queue dispatch fails (for example, the queue backend is down) or an inline refresh
  throws, the volunteering request fires `EarlyRefreshFailed`, reports the exception through
  Laravel's exception handler, and returns the stale value. It keeps the refresh lock, so
  the next attempt happens only after `refresh_lock_seconds`: at most one attempt per window.
- A queued `RefreshCacheJob` that throws on the worker fires `EarlyRefreshFailed`, releases
  the refresh lock and rethrows, so the queue's retries and `failed_jobs` apply as for any
  other job.

Either way the stale value is served until `grace_seconds` runs out. After that the key is
physically gone, and the next read takes the cold path (the mutex), where the callback's
exception propagates as usual.

## Events

All events are plain classes carrying `string $key` and `?string $store`.

| Event                   | Extra payload                              | Fired when |
|:------------------------|:-------------------------------------------|:-----------|
| `LockAcquired`          | `float $waitedSeconds`                     | The mutex was acquired (cold path of either strategy). |
| `LockWaited`            | `float $waitedSeconds`                     | The mutex was acquired after waiting more than 0 seconds. |
| `EarlyRefreshScheduled` | `string $mode` (`'queue'`/`'inline'`)      | A volunteer won the refresh lock (in queue mode: after the job was dispatched). |
| `EarlyRefreshCompleted` | `string $mode`, `float $computeSeconds`    | The envelope was rewritten with a fresh value. |
| `EarlyRefreshFailed`    | `string $mode`, `Throwable $exception`     | The refresh callback threw, or the refresh job could not be dispatched. |

```php
use Illuminate\Support\Facades\Event;
use MohamedTarek\Stampede\Events\EarlyRefreshFailed;

Event::listen(function (EarlyRefreshFailed $event) {
    Log::warning('Stampede refresh failed', [
        'key' => $event->key,
        'store' => $event->store,
        'mode' => $event->mode,
        'exception' => $event->exception,
    ]);
});
```

## Store support

Atomic locks (`Illuminate\Contracts\Cache\LockProvider`) are required by both strategies.
Support by store and Laravel version:

| Store       | Supported since                 |
|:------------|:--------------------------------|
| `redis`     | Every supported Laravel version |
| `memcached` | Every supported Laravel version |
| `dynamodb`  | Every supported Laravel version |
| `array`     | Every supported Laravel version |
| `database`  | Laravel 7.26                    |
| `file`      | Laravel 8.15                    |

Any other store, or `database`/`file` on an older Laravel version than the one listed,
throws `MohamedTarek\Stampede\Exceptions\UnsupportedStoreException` naming the store class.

The `database` store keeps its locks in a separate `cache_locks` table. Laravel 11 and later
create it in the default migrations, and `php artisan cache:table` creates it from Laravel
8.53. On Laravel 7.26 to 8.52 the generated migration only creates the `cache` table, so
add `cache_locks` yourself, or both macros fail with a "no such table: cache_locks" query
error:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCacheLocksTable extends Migration
{
    public function up()
    {
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    public function down()
    {
        Schema::dropIfExists('cache_locks');
    }
}
```

The table name can be changed with the `lock_table` key of the `database` store in
`config/cache.php`.

## Advanced configuration

The published `config/stampede.php`, with its defaults:

```php
<?php

return [
    // Namespace for the package's lock keys: "{prefix}:lock:{key}" for the
    // mutex and "{prefix}:refresh:{key}" for the XFetch refresh marker.
    'prefix' => 'stampede',

    'lock' => [
        // Seconds the computing worker may hold the mutex before it expires on its own.
        'lock_seconds' => 10,
        // Seconds other workers block waiting for the mutex before giving up.
        'wait_seconds' => 5,
        // What waiters do on timeout: 'throw' (LockTimeoutException) or 'compute' (run the callback unlocked).
        'on_timeout' => 'throw',
    ],

    'xfetch' => [
        // XFetch tuning: values above 1 refresh earlier, values below 1 refresh later.
        'beta' => 1.0,
        // Physical TTL = ttl + grace_seconds. Stale data may be served for this long if refreshes keep failing.
        'grace_seconds' => 300,
        // How the volunteer refreshes: 'queue' (dispatches RefreshCacheJob) or 'inline' (in its own request).
        'refresh' => 'queue',
        // Seconds the "someone is refreshing" marker lives; it auto-expires if the job dies.
        'refresh_lock_seconds' => 60,
        // Queue routing for RefreshCacheJob. null means the framework defaults.
        'queue' => [
            'connection' => null,
            'queue' => null,
        ],
    ],
];
```

How the values combine:

- `prefix` and the `lock` block apply to `rememberWithLock()`. The `xfetch` block applies to
  `rememberXFetch()`, which also uses the `lock` block for its cold-start mutex.
- Every key can be overridden per call through the `$options` array of either macro, using
  the same names without the `lock.` or `xfetch.` prefix, for example
  `['wait_seconds' => 0, 'on_timeout' => 'compute']` or `['refresh' => 'inline', 'grace_seconds' => 60]`.
  The `queue` override is merged per key, so `['queue' => ['queue' => 'cache']]` keeps the
  default connection.
- `rememberXFetch()` accepts one extra per-call key, `store`, which names the cache store the
  refresh job should reuse when it cannot be detected. It has no config default.
- Unknown keys, wrong types (`'5'` instead of `5`) and values outside the allowed set
  (`on_timeout`, `refresh`) throw `InvalidArgumentException` at call time.
- Config is read on every call, so `config(['stampede.xfetch.refresh' => 'inline'])` in a
  test takes effect immediately.

A typical production setup pins the refresh job to its own queue so a slow recompute never
delays user-facing jobs:

```php
'xfetch' => [
    'refresh' => 'queue',
    'queue' => [
        'connection' => 'redis',
        'queue' => 'cache-refresh',
    ],
],
```

and runs `php artisan queue:work redis --queue=cache-refresh,default`.

## Compatibility

| Branch          | Laravel    | PHP                                                            |
|:----------------|:-----------|:---------------------------------------------------------------|
| `3.x` (`main`)  | 11, 12, 13 | 8.2 to 8.5                                                     |
| `2.x`           | 8, 9, 10   | 7.3 to 8.3                                                     |
| `1.x`           | 6, 7       | 7.2.5 to 8.0 (Laravel 6 and 7 do not run on PHP 8.1+)          |

`composer require mohamedtarek/laravel-stampede-guard` resolves the right line from the
`illuminate/cache` version already installed in your application. The API, the tests and
this README are identical on every line; only the PHP syntax of the source differs.

## License

MIT. See [LICENSE.md](LICENSE.md). Copyright (c) 2026 Mohamed Tarek.
