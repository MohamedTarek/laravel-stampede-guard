# Security policy

## Supported versions

Security fixes are released for the latest patch of each line.

| Line  | Laravel    | Receives security fixes |
|:------|:-----------|:------------------------|
| `3.x` | 11, 12, 13 | Yes                     |
| `2.x` | 8, 9, 10   | Yes                     |
| `1.x` | 6, 7       | Yes                     |

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's private vulnerability reporting:

https://github.com/MohamedTarek/laravel-stampede-guard/security/advisories/new

Include the package version, the cache store and queue connection involved, and a
reproduction if you have one. You will receive an acknowledgement within a few days and
a fix or a mitigation before any details are published. Credit is given in the changelog
unless you prefer otherwise.

## Scope notes

The package stores an envelope (`['v', 'e', 'd']`) under the caller's cache key and takes
atomic locks named `{prefix}:lock:{key}` and `{prefix}:refresh:{key}`. It does not
serialize anything beyond what Laravel's queue already serializes for a queued closure, and
it never logs cached values. Reports about the security of the cache backend itself
(Redis, Memcached, DynamoDB) belong with those projects.
