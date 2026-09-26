# Changelog

## Unreleased (3.x)

- `Cache::rememberWithLock()`: mutex-guarded remember with double-checked locking.
- `Cache::rememberXFetch()`: probabilistic early expiration with queued or inline refresh.
- Events: `LockAcquired`, `LockWaited`, `EarlyRefreshScheduled`, `EarlyRefreshCompleted`, `EarlyRefreshFailed`.
- Supports Laravel 11, 12 and 13 on PHP 8.2+. See the 2.x and 1.x branches for Laravel 8-10 and 6-7.
