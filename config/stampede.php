<?php

declare(strict_types=1);

return [
    /*
    | Namespace for the package's lock keys. Locks are named
    | "{prefix}:lock:{key}" (mutex) and "{prefix}:refresh:{key}" (XFetch volunteer).
    */
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
        // XFetch tuning: > 1 refreshes earlier, < 1 refreshes later.
        'beta' => 1.0,
        // Physical TTL = ttl + grace_seconds. Stale data may be served for this long if refreshes keep failing.
        'grace_seconds' => 300,
        // How the volunteer refreshes: 'queue' (RefreshCacheJob) or 'inline' (in the volunteer's own request).
        'refresh' => 'queue',
        // Seconds the "someone is refreshing" marker lives; auto-expires if the job dies.
        'refresh_lock_seconds' => 60,
        // Queue routing for RefreshCacheJob. null means the framework defaults.
        'queue' => [
            'connection' => null,
            'queue' => null,
        ],
    ],
];
