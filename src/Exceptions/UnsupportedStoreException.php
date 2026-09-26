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
        $hint = '';

        if ($store instanceof FileStore) {
            $hint = ' (the file store gained locks in Laravel 8)';
        } elseif ($store instanceof DatabaseStore) {
            $hint = ' (the database store gained locks in Laravel 7)';
        }

        return new self(sprintf(
            'Cache store [%s] does not support atomic locks%s. Use a store that implements %s, such as redis, memcached, database, file, dynamodb or array.',
            get_class($store),
            $hint,
            LockProvider::class
        ));
    }
}
