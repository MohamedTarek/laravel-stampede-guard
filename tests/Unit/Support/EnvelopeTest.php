<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Unit\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use MohamedTarek\Stampede\Support\Clock;
use MohamedTarek\Stampede\Support\Envelope;
use PHPUnit\Framework\TestCase;

class EnvelopeTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_clock_honours_carbon_test_now()
    {
        Carbon::setTestNow(Carbon::createFromTimestampMs(1700000000500));

        $this->assertEqualsWithDelta(1700000000.5, Clock::now(), 0.001);
    }

    public function test_round_trips_through_array()
    {
        $envelope = new Envelope(['rows' => 3], 1700000100.25, 2.5);

        $this->assertSame(['v' => ['rows' => 3], 'e' => 1700000100.25, 'd' => 2.5], $envelope->toArray());

        $parsed = Envelope::fromCache($envelope->toArray());

        $this->assertNotNull($parsed);
        $this->assertSame(['rows' => 3], $parsed->value);
        $this->assertSame(1700000100.25, $parsed->expiresAt);
        $this->assertSame(2.5, $parsed->computeSeconds);
    }

    public function test_integer_timestamps_are_accepted()
    {
        $parsed = Envelope::fromCache(['v' => 'x', 'e' => 1700000100, 'd' => 1]);

        $this->assertNotNull($parsed);
        $this->assertSame(1700000100.0, $parsed->expiresAt);
        $this->assertSame(1.0, $parsed->computeSeconds);
    }

    public function test_foreign_values_are_not_envelopes()
    {
        $foreign = [
            'plain string',
            [1, 2, 3],
            ['v' => 1, 'e' => 1.0],
            ['v' => 1, 'e' => 'soon', 'd' => 1.0],
            ['v' => null, 'e' => 1.0, 'd' => 1.0],
            new \stdClass,
        ];

        foreach ($foreign as $raw) {
            $this->assertNull(Envelope::fromCache($raw), 'should not parse: '.var_export($raw, true));
        }
    }

    public function test_falsy_values_survive_parsing()
    {
        foreach ([0, false, [], ''] as $value) {
            $parsed = Envelope::fromCache(['v' => $value, 'e' => 1.0, 'd' => 1.0]);
            $this->assertNotNull($parsed);
            $this->assertSame($value, $parsed->value);
        }
    }

    public function test_compute_times_the_callback_and_sets_logical_expiry()
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1700000000));

        $envelope = Envelope::compute(function () {
            usleep(20000);

            return 'result';
        }, 60);

        $this->assertSame('result', $envelope->value);
        $this->assertEqualsWithDelta(1700000060.0, $envelope->expiresAt, 0.001);
        $this->assertGreaterThanOrEqual(0.02, $envelope->computeSeconds);
        $this->assertLessThan(1.0, $envelope->computeSeconds);
    }

    public function test_compute_rejects_null()
    {
        $this->expectException(InvalidArgumentException::class);

        Envelope::compute(function () {
            return null;
        }, 60);
    }
}
