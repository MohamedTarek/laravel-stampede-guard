# Changelog

## Unreleased (2.x)

- `Cache::rememberWithLock()`: mutex-guarded remember with double-checked locking.
- `Cache::rememberXFetch()`: probabilistic early expiration with queued or inline refresh.
- Events: `LockAcquired`, `LockWaited`, `EarlyRefreshScheduled`, `EarlyRefreshCompleted`, `EarlyRefreshFailed`.
- Supports Laravel 8, 9 and 10 on PHP 7.3+. See the 3.x line for Laravel 11-13 and the 1.x branch for Laravel 6-7.

## Unreleased (3.x)

- `Cache::rememberWithLock()`: mutex-guarded remember with double-checked locking.
- `Cache::rememberXFetch()`: probabilistic early expiration with queued or inline refresh.
- Events: `LockAcquired`, `LockWaited`, `EarlyRefreshScheduled`, `EarlyRefreshCompleted`, `EarlyRefreshFailed`.
- Supports Laravel 11, 12 and 13 on PHP 8.2+. See the 2.x and 1.x branches for Laravel 8-10 and 6-7.
