<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Exceptions;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\DynamoDbStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use RuntimeException;

final class UnsupportedStoreException extends RuntimeException
{
    /** Stores the message may suggest, in the order they are listed. */
    private const CANDIDATES = [
        'redis' => RedisStore::class,
        'memcached' => MemcachedStore::class,
        'dynamodb' => DynamoDbStore::class,
        'array' => ArrayStore::class,
        'database' => DatabaseStore::class,
        'file' => FileStore::class,
    ];

    public static function forStore(Store $store): self
    {
        $hint = '';

        if ($store instanceof FileStore) {
            $hint = ' (the file store gained locks in Laravel 8.15)';
        } elseif ($store instanceof DatabaseStore) {
            $hint = ' (the database store gained locks in Laravel 7.26)';
        }

        return new self(sprintf(
            'Cache store [%s] does not support atomic locks%s. Use a store that implements %s, such as %s.',
            get_class($store),
            $hint,
            LockProvider::class,
            self::storesWithLocks()
        ));
    }

    /**
     * Only the stores that implement LockProvider on the Laravel version running now:
     * database and file gained locks in minor releases this line still allows.
     */
    private static function storesWithLocks(): string
    {
        $names = [];

        foreach (self::CANDIDATES as $name => $class) {
            if (in_array(LockProvider::class, class_implements($class) ?: [], true)) {
                $names[] = $name;
            }
        }

        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' or '.$last;
    }
}
