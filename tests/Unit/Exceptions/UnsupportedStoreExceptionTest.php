<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Unit\Exceptions;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\NullStore;
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

        $this->assertStringContainsString('Laravel 8', UnsupportedStoreException::forStore($file)->getMessage());
        $this->assertStringContainsString('Laravel 7', UnsupportedStoreException::forStore($database)->getMessage());
    }
}
