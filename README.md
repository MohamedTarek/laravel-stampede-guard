# Laravel Stampede Guard

![tests](https://github.com/MohamedTarek/laravel-stampede-guard/actions/workflows/tests.yml/badge.svg)

A cache stampede (also called a thundering herd or dogpile) happens when a cached
value expires and many concurrent requests all read a miss at once, so every one
of them recomputes the same expensive value and hits the database (or whatever
backs the cache) at the same moment. This package gives Laravel's cache two
strategies that stop that from happening: a mutex-guarded `remember()` and a
probabilistic early-refresh `remember()`. Background and the motivating problem
are described in
["Backend Architecture: Laravel Cache Stampede Prevention"](https://smarttechdevs.hashnode.dev/backend-architecture-laravel-cache-stampede-prevention)
(Smart Tech Devs Engineering).

![The problem: a cache stampede](https://raw.githubusercontent.com/MohamedTarek/laravel-stampede-guard/main/art/stampede-problem.svg)

## Installation

```bash
composer require mohamedtarek/laravel-stampede-guard
```

Composer picks the release line that matches the `illuminate/cache` version
already required by your application:

| Branch | Laravel | PHP |
|---|---|---|
| `3.x` (`main`) | 11, 12, 13 | 8.2 to 8.5 |
| `2.x` | 8, 9, 10 | 7.3 to 8.3 |
| `1.x` | 6, 7 | 7.2.5 to 8.0 (Laravel 6 and 7 do not run on PHP 8.1+) |

Publish the config file:

```bash
php artisan vendor:publish --tag=stampede-config
```

This writes `config/stampede.php`.

## `rememberWithLock`

`remember()` guarded by an atomic lock with double-checked reads, so a cold key
is computed by exactly one worker while every other worker waits for the result
instead of recomputing it.

![rememberWithLock: one computes, the rest wait](https://raw.githubusercontent.com/MohamedTarek/laravel-stampede-guard/main/art/remember-with-lock.svg)

```php
use Illuminate\Support\Facades\Cache;

$value = Cache::rememberWithLock('report:2026-09', 3600, function () {
    return Report::expensive();
});
```

The macro is added to `Illuminate\Cache\Repository`, so it is available on
`Cache::store('redis')`, `Cache::store('memcached')`, and so on, not only the
default `Cache` facade.

```php
public function rememberWithLock(string $key, $ttl, Closure $callback, array $options = []): mixed
```

Options (merged from `config('stampede.prefix')` / `config('stampede.lock')`,
then per-call overrides):

| Option | Default | Meaning |
|---|---|---|
| `lock_seconds` | `10` | Seconds the computing worker may hold the mutex before it expires on its own. |
| `wait_seconds` | `5` | Seconds other workers block waiting for the mutex before giving up. |
| `on_timeout` | `'throw'` | What a waiter does on timeout: `'throw'` (`LockTimeoutException`) or `'compute'` (run the callback unlocked). |
| `prefix` | `'stampede'` | Namespace for the lock key: `"{prefix}:lock:{key}"`. |

If the underlying cache store does not support atomic locks, the call throws
`MohamedTarek\Stampede\Exceptions\UnsupportedStoreException` before any work is
done. A callback that returns `null` throws `InvalidArgumentException`.
Laravel's own `remember()` has no such check: it silently stores nothing
useful and recomputes the callback on every call.

## `rememberXFetch`

Probabilistic early expiration (the XFetch algorithm). A cached value is
served until its logical expiry; as that approaches, one reader "volunteers"
(the probability of volunteering rises with how long the value took to
compute) and refreshes it ahead of time, so no other reader ever sees a cold
key after the first fill.

![rememberXFetch: refresh before it expires](https://raw.githubusercontent.com/MohamedTarek/laravel-stampede-guard/main/art/remember-xfetch.svg)

```php
use Illuminate\Support\Facades\Cache;

$value = Cache::rememberXFetch('report:2026-09', 3600, function () {
    return Report::expensive();
});
```

```php
public function rememberXFetch(string $key, $ttl, callable $callback, array $options = []): mixed
```

Options (merged from `config('stampede.prefix')`, `config('stampede.lock')` and
`config('stampede.xfetch')`, then per-call overrides):

| Option | Default | Meaning |
|---|---|---|
| `beta` | `1.0` | XFetch tuning: values above `1` make workers volunteer earlier, values below `1` make them volunteer later. |
| `grace_seconds` | `300` | Physical TTL is `ttl + grace_seconds`. Stale data may be served for this long if refreshes keep failing. |
| `refresh` | `'queue'` | How the volunteer refreshes: `'queue'` (dispatches `RefreshCacheJob`) or `'inline'` (in the volunteer's own request). |
| `refresh_lock_seconds` | `60` | Seconds the "someone is refreshing" marker lives; it auto-expires if the job dies. |
| `queue.connection` | `null` | Queue connection `RefreshCacheJob` is dispatched on. `null` uses the framework default. |
| `queue.queue` | `null` | Queue name `RefreshCacheJob` is dispatched on. `null` uses the framework default. |
| `store` | `null` | Cache store name the refresh job reuses. `null` auto-detects the store the call was made on. |

If the repository was not resolved by name through the cache manager
(`Cache::repository($store)`, `Cache::build([...])`, `Cache::memo()`, a
hand-built `new Repository(...)`), the store name cannot be detected. In queue
mode that throws `InvalidArgumentException` at call time, because the job
would otherwise refresh the default store; pass the `store` option or use
`refresh => 'inline'`.

Behind the caller's key, the package stores an envelope instead of the raw
value:

```php
['v' => $value, 'e' => $logicalExpiryUnixTimestamp, 'd' => $computeSeconds]
```

`v` is the cached value, `e` is when the value logically expires, and `d` is
how long the callback took to compute it the last time it ran. Every read
decides whether to volunteer for an early refresh with:

```
now - d * beta * ln(rand) >= expiry
```

where `rand` is drawn fresh on each call from `(0, 1]`. `beta` scales how
aggressively the package refreshes ahead of expiry: raising it makes early
refreshes more likely (and earlier), lowering it makes the package wait
closer to the real expiry before anyone volunteers.

A key that has never been cached (a cold start) has no envelope to read a
compute time from, so `rememberXFetch` falls back to the same mutex used by
`rememberWithLock` for the first fill, then switches to the probabilistic
behaviour above once an envelope exists.

`rememberXFetch` requires a real TTL: a `null` TTL or a TTL that resolves to
zero or fewer seconds throws `InvalidArgumentException`, since a value cached
forever has no logical expiry to refresh ahead of.

A key used with `rememberXFetch` holds the envelope above, not your value, so
read it only through `rememberXFetch`. A plain `Cache::get()` returns the
envelope array, and mixing `Cache::remember()` and `rememberXFetch()` on the
same key makes them overwrite each other's format on every call. To move an
existing key to `rememberXFetch`, use a new key name.

Tagged caches are not supported: calling either macro on `Cache::tags([...])`
throws `InvalidArgumentException`.

## Queue vs inline refresh

- **Queue mode** (`refresh => 'queue'`, the default): the volunteering
  request returns the still-cached (stale) value immediately and dispatches
  `RefreshCacheJob` to recompute in the background.
- **Inline mode** (`refresh => 'inline'`): the volunteering request itself
  recomputes the value synchronously and returns the freshly computed value,
  not the stale one.

On the `sync` queue connection, `RefreshCacheJob` runs inside the dispatching
request rather than on a worker, so `EarlyRefreshCompleted` fires before
`EarlyRefreshScheduled` for that request.

When an early refresh fails, readers keep getting the stale value instead of
an exception:

- If the queue dispatch fails (for example, the queue backend is down) or an
  inline refresh throws, the volunteering request fires `EarlyRefreshFailed`,
  reports the exception through Laravel's exception handler, and returns the
  stale value. It keeps the refresh lock, so the next attempt happens only
  after `refresh_lock_seconds`: at most one attempt per window.
- A queued `RefreshCacheJob` that throws on the worker fires
  `EarlyRefreshFailed`, releases the refresh lock and rethrows, so the queue's
  retries and `failed_jobs` apply as for any other job.

Either way the stale value is served until `grace_seconds` runs out. After
that the key is physically gone, and the next read takes the cold path (the
mutex), where the callback's exception propagates as usual.

Because closures passed to `rememberXFetch` are serialized when dispatched to
a queue, capture ids rather than models, connections, or other
non-serializable values in the callback, the same rule as any other queued
job in Laravel.

## Events

All events are plain classes carrying `string $key` and `?string $store`.

| Event | Extra payload | Fired when |
|---|---|---|
| `LockAcquired` | `float $waitedSeconds` | The mutex was acquired (cold path of either strategy). |
| `LockWaited` | `float $waitedSeconds` | The mutex was acquired after waiting more than 0 seconds. |
| `EarlyRefreshScheduled` | `string $mode` (`'queue'`/`'inline'`) | A volunteer won the refresh lock (in queue mode: after the job was dispatched). |
| `EarlyRefreshCompleted` | `string $mode`, `float $computeSeconds` | The envelope was rewritten with a fresh value. |
| `EarlyRefreshFailed` | `string $mode`, `Throwable $exception` | The refresh callback threw, or the refresh job could not be dispatched. |

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

## Comparison

| | `Cache::remember` | `Cache::flexible` (Laravel 11.23+) | `rememberWithLock` | `rememberXFetch` |
|---|---|---|---|---|
| Cold key: workers computing | All of them (stampede) | All of them (stampede) | One; others wait for the lock | One, via the same mutex as `rememberWithLock` |
| Warm key near expiry | Stampede at the moment of expiry | One deterministic refresh, deferred until after the response | Same as a cold key: one recompute per expiry, others wait on the lock | Probabilistic refresh before expiry; readers keep getting the still-valid value |
| Needs a queue | No | No (uses `defer()`, not a queue) | No | Optional (default: queue; can run inline) |
| Min Laravel | Any | 11.23 | 6 | 6 |

## Store support

Atomic locks (`Illuminate\Contracts\Cache\LockProvider`) are required by both
strategies. Support by store and Laravel version:

| Store | Supported since |
|---|---|
| `redis` | Every supported Laravel version |
| `memcached` | Every supported Laravel version |
| `dynamodb` | Every supported Laravel version |
| `array` | Every supported Laravel version |
| `database` | Laravel 7 |
| `file` | Laravel 8 |

Any other store, or `database`/`file` on an older Laravel version than the
one listed, throws `MohamedTarek\Stampede\Exceptions\UnsupportedStoreException`
naming the store class and, where relevant, the Laravel version that added
lock support to it.

## Proof

`tests/Concurrency/StampedeTest.php` forks 50 worker processes against Redis 7
that all hit the same cold key at once, with a callback that sleeps 0.5
seconds before incrementing a counter. Results from a real run:

| Strategy | Callback calls for 50 concurrent workers |
|---|---|
| `Cache::remember` (plain, no protection) | 50 |
| `rememberWithLock` | 1 |
| `rememberXFetch` (cold start) | 1 |

## License

MIT. See [LICENSE.md](LICENSE.md). Copyright (c) 2026 Mohamed Tarek.
