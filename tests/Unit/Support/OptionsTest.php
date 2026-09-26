<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Unit\Support;

use InvalidArgumentException;
use MohamedTarek\Stampede\Support\Options;
use PHPUnit\Framework\TestCase;

class OptionsTest extends TestCase
{
    private function config()
    {
        return require __DIR__.'/../../../config/stampede.php';
    }

    public function test_lock_options_come_from_config()
    {
        $options = Options::lock($this->config());

        $this->assertSame('stampede', $options->get('prefix'));
        $this->assertSame(10, $options->get('lock_seconds'));
        $this->assertSame(5, $options->get('wait_seconds'));
        $this->assertSame('throw', $options->get('on_timeout'));
    }

    public function test_per_call_overrides_win_over_config()
    {
        $options = Options::lock($this->config(), ['wait_seconds' => 0, 'on_timeout' => 'compute']);

        $this->assertSame(0, $options->get('wait_seconds'));
        $this->assertSame('compute', $options->get('on_timeout'));
        $this->assertSame(10, $options->get('lock_seconds'));
    }

    public function test_missing_config_falls_back_to_built_in_defaults()
    {
        $options = Options::xfetch([]);

        $this->assertSame('stampede', $options->get('prefix'));
        $this->assertSame(1.0, $options->get('beta'));
        $this->assertSame(300, $options->get('grace_seconds'));
        $this->assertSame('queue', $options->get('refresh'));
        $this->assertSame(60, $options->get('refresh_lock_seconds'));
        $this->assertSame(['connection' => null, 'queue' => null], $options->get('queue'));
        $this->assertNull($options->get('store'));
        $this->assertSame(10, $options->get('lock_seconds'));
    }

    public function test_xfetch_queue_override_is_merged_per_key()
    {
        $options = Options::xfetch($this->config(), ['queue' => ['queue' => 'cache-refresh']]);

        $this->assertSame(['connection' => null, 'queue' => 'cache-refresh'], $options->get('queue'));
    }

    public function test_beta_is_normalised_to_float()
    {
        $this->assertSame(2.0, Options::xfetch([], ['beta' => 2])->get('beta'));
    }

    public function test_unknown_key_is_rejected()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ttl');

        Options::lock($this->config(), ['ttl' => 10]);
    }

    public function test_lock_options_do_not_accept_xfetch_keys()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('beta');

        Options::lock($this->config(), ['beta' => 2.0]);
    }

    public function test_invalid_values_are_rejected()
    {
        $invalid = [
            ['wait_seconds' => '5'],
            ['wait_seconds' => -1],
            ['lock_seconds' => 0],
            ['on_timeout' => 'retry'],
            ['beta' => 0],
            ['grace_seconds' => -1],
            ['refresh' => 'defer'],
            ['refresh_lock_seconds' => 0],
            ['queue' => 'high'],
            ['queue' => ['priority' => 'high']],
            ['store' => 5],
            ['prefix' => ''],
        ];

        foreach ($invalid as $override) {
            $key = key($override);

            try {
                Options::xfetch($this->config(), $override);
                $this->fail("Expected [{$key}] => ".var_export($override[$key], true).' to be rejected');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    public function test_round_trips_through_array()
    {
        $options = Options::xfetch($this->config(), ['refresh' => 'inline', 'store' => 'redis']);

        $rehydrated = Options::fromArray($options->toArray());

        $this->assertSame($options->toArray(), $rehydrated->toArray());
        $this->assertSame('inline', $rehydrated->get('refresh'));
    }

    public function test_reading_an_absent_key_throws()
    {
        $this->expectException(InvalidArgumentException::class);

        Options::lock($this->config())->get('beta');
    }
}
