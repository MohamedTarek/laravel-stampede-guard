# Contributing

Thanks for taking the time. This document covers how to report a problem, how to set up
the project locally, how the three release lines relate to each other, and what a pull
request needs to be merged.

## Reporting a bug

Open a GitHub issue with:

- the package version and release line (`composer show mohamedtarek/laravel-stampede-guard`),
- the Laravel and PHP versions,
- the cache store (`redis`, `memcached`, `database`, ...) and, for `rememberXFetch()`, the
  refresh mode (`queue` or `inline`) and queue connection,
- the smallest code that reproduces it, ideally as a failing test.

Concurrency bugs are the interesting ones here. If you can, say how many workers were
involved and whether the callback ran more than once (the `LockAcquired`,
`EarlyRefreshScheduled` and `EarlyRefreshCompleted` events make that easy to log).

## Reporting a security issue

Do not open a public issue. Use GitHub's private vulnerability reporting on the repository
("Security" tab, "Report a vulnerability"). You will get a response before anything is
published.

## Local setup

```bash
git clone https://github.com/MohamedTarek/laravel-stampede-guard.git
cd laravel-stampede-guard
composer install
```

Composer resolves the newest Laravel your local PHP supports. The `2.x` and `1.x` branches
need older PHP versions to run for real; see "Release lines" below.

### Running the tests

```bash
composer test      # phpunit: all three suites
composer analyse   # phpstan (Larastan)
composer lint      # pint --test on main and 2.x, php-cs-fixer --dry-run on 1.x
composer fix       # apply the style fixer
```

The suite has three parts:

| Suite         | Backs onto                     | Skips when                          |
|:--------------|:-------------------------------|:------------------------------------|
| `Unit`        | The array store, no services   | never                               |
| `Integration` | A real Redis                   | Redis is unreachable                |
| `Concurrency` | Redis plus `pcntl` and `posix` | either extension or Redis is missing |

For the full suite, start a Redis on the default port:

```bash
docker run -d --rm --name stampede-redis -p 6379:6379 redis:7
```

`REDIS_HOST` and `REDIS_PORT` override the defaults (`127.0.0.1:6379`); the tests use
database `1` and flush it before every test, so do not point them at a Redis you care about.

The concurrency suite forks 50 worker processes and asserts the callback ran exactly once
per strategy. It is the proof behind the numbers in the README, so please keep it passing.

`tests/Benchmark/` holds the warm-key latency benchmark. It is not in any default suite
and CI does not run it; run it by hand with `vendor/bin/phpunit tests/Benchmark` when you
change anything on the XFetch refresh path, and update the README table if the numbers
move.

## Release lines

Three branches are maintained in parallel and must stay behaviourally identical:

| Branch | Laravel    | PHP          | Source syntax floor | Style tool     | PHPStan |
|:-------|:-----------|:-------------|:--------------------|:---------------|:--------|
| `main` | 11, 12, 13 | 8.2 to 8.5   | PHP 8.2             | Pint           | level 8 |
| `2.x`  | 8, 9, 10   | 7.3 to 8.3   | PHP 7.3             | Pint           | level 8 |
| `1.x`  | 6, 7       | 7.2.5 to 8.0 | PHP 7.2             | PHP CS Fixer   | level 5 |

Rules that keep the lines in sync:

- `tests/`, `README.md`, `CHANGELOG.md` and this file are byte-identical on every branch.
  Tests are therefore written in PHP 7.3 syntax even on `main`: no arrow functions, typed
  properties, constructor promotion, `match`, `mixed` or numeric separators.
- `src/` uses PHP 8.2 features on `main`. On `2.x` and `1.x` the same code is written in
  PHP 7.3 syntax: no typed properties, promotion, `readonly`, `match`, `?->`, union types,
  `mixed`, `$object::class` or trailing commas in calls. Immutable properties carry a
  `@readonly` docblock tag instead of the keyword.
- Only two things differ in logic between lines: `src/Support/ClosureSerializer.php`
  (which closure serializer is available) and dependency constraints in `composer.json`.
- A fix lands on `main` first, then is ported to `2.x` and `1.x` in the same pull request or
  in a follow-up that references it.

To check a port against an old PHP without installing it, use Docker:

```bash
# Syntax only, fast
docker run --rm -v "$PWD":/app -w /app php:7.3-cli sh -c \
  'for f in $(find src tests config -name "*.php"); do php -l "$f" || exit 1; done'
```

For a full test run on an old PHP, install the dependencies with the matching constraints
(the CI workflow on each branch shows the exact `composer require` lines per Laravel
version) and run `vendor/bin/phpunit` inside a `php:7.x-cli` container with
`REDIS_HOST=host.docker.internal`.

## Pull requests

1. Branch from `main` (or from `2.x` / `1.x` only for a fix that cannot apply to `main`).
2. Add or update tests. A behaviour change without a test will be sent back.
3. Run `composer test`, `composer analyse` and `composer lint` locally.
4. Keep the public API identical across lines. If you add an option, add it to
   `config/stampede.php`, to `Options`, to the README tables and to the changelog.
5. Use a conventional commit subject (`feat:`, `fix:`, `docs:`, `test:`, `ci:`, `chore:`)
   and describe in the PR which release lines the change affects.
6. CI must be green on every leg of the matrix, including the `prefer-lowest` runs.

Small, focused pull requests are reviewed fastest.

## Coding standards

- `declare(strict_types=1);` in every PHP file.
- Style is enforced by Pint with the `laravel` preset (`pint.json`) on `main` and `2.x`, and
  by PHP CS Fixer (`.php-cs-fixer.dist.php`) on `1.x`, configured to accept the same output.
- Cache reads use a sentinel object as the default so that cached `0`, `false`, `[]` and
  `''` count as hits. Never test a cached value for truthiness.
- Every path that acquires a lock must release it in a `finally` block or hand it to the
  refresh job explicitly. Reviews check this first.

## Releasing

Each line is tagged independently: `3.x.y` on `main`, `2.x.y` on `2.x`, `1.x.y` on `1.x`.
Update `CHANGELOG.md` (identical on all branches), tag the commit, and push the tag;
Packagist updates automatically through the repository webhook. Published tags are never
moved.
