<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Support;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;

/**
 * Finds the name a Repository was resolved under, so a queued job can rebuild it.
 * Only already-resolved stores are inspected; nothing is instantiated as a side effect.
 */
final class StoreNameResolver
{
    public function __construct(private readonly CacheManager $manager) {}

    public function resolve(Repository $repository): ?string
    {
        /** @var array<string, Repository> $stores */
        $stores = Closure::bind(
            fn (): array => $this->stores, // reads CacheManager::$stores once bound
            $this->manager,
            CacheManager::class,
        )();

        foreach ($stores as $name => $resolved) {
            if ($resolved === $repository) {
                return (string) $name;
            }
        }

        return null;
    }
}
