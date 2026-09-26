<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Unit;

use MohamedTarek\Stampede\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged_with_defaults()
    {
        $this->assertSame('stampede', config('stampede.prefix'));
        $this->assertSame(10, config('stampede.lock.lock_seconds'));
        $this->assertSame('queue', config('stampede.xfetch.refresh'));
    }
}
