<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Exceptions;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use RuntimeException;

final class UnsupportedStoreException extends RuntimeException
{
    public static function forStore(Store $store): self
    {
        $hint = match (true) {
            $store instanceof FileStore => ' (the file store gained locks in Laravel 8)',
            $store instanceof DatabaseStore => ' (the database store gained locks in Laravel 7)',
            default => '',
        };

        return new self(sprintf(
            'Cache store [%s] does not support atomic locks%s. Use a store that implements %s, such as redis, memcached, database, file, dynamodb or array.',
            $store::class,
            $hint,
            LockProvider::class,
        ));
    }
}
