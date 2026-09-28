<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Unit\Exceptions;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\DynamoDbStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Filesystem\Filesystem;
use MohamedTarek\Stampede\Exceptions\UnsupportedStoreException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class UnsupportedStoreExceptionTest extends TestCase
{
    public function test_message_names_the_store_class()
    {
        $exception = UnsupportedStoreException::forStore(new NullStore);

        $this->assertStringContainsString(NullStore::class, $exception->getMessage());
        $this->assertStringContainsString('LockProvider', $exception->getMessage());
    }

    public function test_message_hints_at_the_laravel_version_for_file_and_database()
    {
        $file = new FileStore(new Filesystem, sys_get_temp_dir());
        $database = (new ReflectionClass(DatabaseStore::class))->newInstanceWithoutConstructor();

        $this->assertStringContainsString('gained locks in Laravel 8.15', UnsupportedStoreException::forStore($file)->getMessage());
        $this->assertStringContainsString('gained locks in Laravel 7.26', UnsupportedStoreException::forStore($database)->getMessage());
    }

    public function test_message_only_suggests_stores_that_have_locks_on_the_installed_laravel_version()
    {
        $message = UnsupportedStoreException::forStore(new NullStore)->getMessage();
        $this->assertSame(1, preg_match('/, such as ([a-z, ]+)\.$/', $message, $match), $message);

        $suggested = preg_split('/, | or /', $match[1]);
        $stores = [
            'redis' => RedisStore::class,
            'memcached' => MemcachedStore::class,
            'dynamodb' => DynamoDbStore::class,
            'array' => ArrayStore::class,
            'database' => DatabaseStore::class,
            'file' => FileStore::class,
        ];

        foreach ($stores as $name => $class) {
            $this->assertSame(
                is_subclass_of($class, LockProvider::class),
                in_array($name, $suggested, true),
                "[{$name}] must be suggested exactly when {$class} implements LockProvider. Message: {$message}"
            );
        }
    }
}
