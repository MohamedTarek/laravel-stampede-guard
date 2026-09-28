# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses
[Semantic Versioning](https://semver.org/) per release line.

Three lines are maintained in parallel and receive the same fixes:

| Line  | Branch | Laravel    | PHP          |
|:------|:-------|:-----------|:-------------|
| `3.x` | `main` | 11, 12, 13 | 8.2 to 8.5   |
| `2.x` | `2.x`  | 8, 9, 10   | 7.3 to 8.3   |
| `1.x` | `1.x`  | 6, 7       | 7.2.5 to 8.0 |

## [Unreleased]

### Added

- `tests/Benchmark/WarmKeyLatencyTest.php`: a manual warm-key latency benchmark (8
  readers, 12 s, four expiries, 300 ms callback, real Redis queue worker) and its results
  in the README, so the "readers never wait" claim of `rememberXFetch` is measured, not
  asserted.

### Fixed

- README "Store support": the `database` store has locks from Laravel 7.26 and the `file`
  store from Laravel 8.15, not from 7.0 and 8.0 as listed. The section now also covers the
  `cache_locks` table, which `php artisan cache:table` only creates from Laravel 8.53, with
  the migration to add on older versions.
- `UnsupportedStoreException` now says the `database` store gained locks in Laravel 7.26
  and the `file` store in 8.15, and only suggests stores that have locks on the Laravel
  version in use. On Laravel 6 and 7 it used to suggest `database` and `file`, which throw
  the same exception there.

## [3.0.1] - 2026-09-27

Documentation and repository housekeeping only; no code changes.

### Changed

- README restructured: hero and badges, the concurrency proof and the strategy comparison
  moved above the fold, usage before configuration, and the compatibility table under
  Installation.
- README diagrams (`art/*.svg`) are now served through jsDelivr so they render on Packagist.
- `CHANGELOG.md` rewritten in Keep a Changelog format.

### Added

- `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, issue forms and a pull request
  template.
- Three explanatory diagrams: the stampede, `rememberWithLock`, `rememberXFetch`.

## [2.0.1] - 2026-09-27

Same documentation and housekeeping changes as 3.0.1, on the `2.x` line. No code changes.

## [1.0.1] - 2026-09-27

Same documentation and housekeeping changes as 3.0.1, on the `1.x` line. No code changes.

## [3.0.0] - 2026-09-27

First release of the `3.x` line, for Laravel 11, 12 and 13 on PHP 8.2 to 8.5.

### Added

- `Cache::rememberWithLock()`: `remember()` guarded by an atomic lock with double-checked
  reads, so a cold key is computed by exactly one worker. Options `lock_seconds`,
  `wait_seconds`, `on_timeout` (`throw` or `compute`) and `prefix`.
- `Cache::rememberXFetch()`: probabilistic early expiration (XFetch). The cached value is
  stored as an envelope `['v', 'e', 'd']`; one reader volunteers to refresh it before the
  logical expiry, either through a queued `RefreshCacheJob` or inline. Options `beta`,
  `grace_seconds`, `refresh` (`queue` or `inline`), `refresh_lock_seconds`,
  `queue.connection`, `queue.queue` and the per-call `store`.
- Cold-start protection for `rememberXFetch()` through the same mutex as
  `rememberWithLock()`.
- Stale-while-failing behaviour: when a queue dispatch or an inline refresh fails on a warm
  key, the request fires `EarlyRefreshFailed`, reports the exception, serves the stale value
  and keeps the refresh lock so retries are bounded to one per `refresh_lock_seconds`.
- Events `LockAcquired`, `LockWaited`, `EarlyRefreshScheduled`, `EarlyRefreshCompleted` and
  `EarlyRefreshFailed`.
- `UnsupportedStoreException` for stores without atomic locks, thrown before any work is
  done and naming the Laravel version that added lock support to `file` and `database`.
- `InvalidArgumentException` for tagged caches, for unnamed repositories in queue mode, for
  `null` or non-positive TTLs, for `null` callback results and for unknown or mistyped
  options.
- Publishable `config/stampede.php` (`--tag=stampede-config`); every option can also be
  overridden per call.
- Test suite of 97 tests: unit tests on the array store, Redis integration tests for the
  lock handoff between request and job, and a 50-process fork proof that the callback runs
  exactly once under contention.
- PHPStan level 8 via Larastan, Laravel Pint, and a CI matrix over PHP 8.2 to 8.5 and
  Laravel 11 to 13 with a Redis service.

## [2.0.0] - 2026-09-27

First release of the `2.x` line, for Laravel 8, 9 and 10 on PHP 7.3 to 8.3.

### Added

- Everything in 3.0.0, with `src/` written in PHP 7.3 syntax.
- Queued refresh closures use `laravel/serializable-closure` on PHP 7.4 and later, and fall
  back to `opis/closure` on PHP 7.3, where the former cannot run.
- CI matrix over PHP 7.3 to 8.3 and Laravel 8 to 10.

## [1.0.0] - 2026-09-27

First release of the `1.x` line, for Laravel 6 and 7 on PHP 7.2.5 to 8.0.

### Added

- Everything in 2.0.0, with `src/` also valid on PHP 7.2.
- Queued refresh closures use Laravel's own opis-based
  `Illuminate\Queue\SerializableClosure`.
- Minimum framework versions of Laravel 6.18.1 and 7.0.5: earlier releases lack array cache
  locks (before 6.3) or fail when releasing an expired array lock.
- Static analysis at PHPStan level 5 via Larastan 1 and style via PHP CS Fixer.
- CI matrix over PHP 7.2 to 8.0 and Laravel 6 to 7. Laravel 6 and 7 do not boot on PHP 8.1.

[Unreleased]: https://github.com/MohamedTarek/laravel-stampede-guard/compare/3.0.1...main
[3.0.1]: https://github.com/MohamedTarek/laravel-stampede-guard/releases/tag/3.0.1
[2.0.1]: https://github.com/MohamedTarek/laravel-stampede-guard/releases/tag/2.0.1
[1.0.1]: https://github.com/MohamedTarek/laravel-stampede-guard/releases/tag/1.0.1
[3.0.0]: https://github.com/MohamedTarek/laravel-stampede-guard/releases/tag/3.0.0
[2.0.0]: https://github.com/MohamedTarek/laravel-stampede-guard/releases/tag/2.0.0
[1.0.0]: https://github.com/MohamedTarek/laravel-stampede-guard/releases/tag/1.0.0
