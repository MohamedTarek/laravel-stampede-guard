<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede;

use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Cache\TaggedCache;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use MohamedTarek\Stampede\Strategies\LockRemember;
use MohamedTarek\Stampede\Strategies\XFetchRemember;
use MohamedTarek\Stampede\Support\MtRandomSource;
use MohamedTarek\Stampede\Support\Options;
use MohamedTarek\Stampede\Support\RandomSource;
use MohamedTarek\Stampede\Support\StoreNameResolver;

final class StampedeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/stampede.php', 'stampede');

        $this->app->singleton(StoreNameResolver::class, function ($app) {
            return new StoreNameResolver($app['cache']);
        });

        $this->app->bind(RandomSource::class, MtRandomSource::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/stampede.php' => config_path('stampede.php'),
        ], 'stampede-config');

        $this->registerMacros();
    }

    /** @param array<string, mixed> $options */
    public function createLockStrategy(Repository $repository, array $options): LockRemember
    {
        $this->rejectTaggedCache($repository, 'rememberWithLock');

        return new LockRemember(
            $repository,
            Options::lock($this->config(), $options),
            $this->app->make(Dispatcher::class),
            $this->app->make(StoreNameResolver::class)->resolve($repository)
        );
    }

    /** @param array<string, mixed> $options */
    public function createXFetchStrategy(Repository $repository, array $options): XFetchRemember
    {
        $this->rejectTaggedCache($repository, 'rememberXFetch');

        $resolved = Options::xfetch($this->config(), $options);

        /** @var ?string $storeName */
        $storeName = $resolved->get('store') ?? $this->app->make(StoreNameResolver::class)->resolve($repository);

        if ($storeName === null && $resolved->get('refresh') === 'queue') {
            // The queued job rebuilds the repository by name; without one it would
            // write into the default store instead of the store this call was made on.
            throw new InvalidArgumentException(
                'rememberXFetch() could not determine the cache store name of this repository, which a queued refresh needs to find it again. '
                .'Pass the "store" option (the name from config/cache.php) or use "refresh" => "inline".'
            );
        }

        return new XFetchRemember(
            $repository,
            $resolved,
            $this->app->make(Dispatcher::class),
            $this->app->make(BusDispatcher::class),
            $this->app->make(RandomSource::class),
            $storeName,
            $this->app->make(ExceptionHandler::class)
        );
    }

    private function rejectTaggedCache(Repository $repository, string $macro): void
    {
        if ($repository instanceof TaggedCache) {
            throw new InvalidArgumentException(
                $macro.'() cannot be called on a tagged cache: tagged caches are not supported by this package. Call it on an untagged store instead.'
            );
        }
    }

    private function registerMacros(): void
    {
        $provider = $this;

        Repository::macro('rememberWithLock', function (string $key, $ttl, Closure $callback, array $options = []) use ($provider) {
            /** @var Repository $this */
            return $provider->createLockStrategy($this, $options)->remember($key, $ttl, $callback);
        });

        Repository::macro('rememberXFetch', function (string $key, $ttl, callable $callback, array $options = []) use ($provider) {
            /** @var Repository $this */
            return $provider->createXFetchStrategy($this, $options)->remember($key, $ttl, $callback);
        });
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return (array) $this->app->make('config')->get('stampede', []);
    }
}
