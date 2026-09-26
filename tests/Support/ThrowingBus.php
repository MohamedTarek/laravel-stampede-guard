<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Support;

use Illuminate\Contracts\Bus\Dispatcher;
use RuntimeException;

/**
 * A bus whose every dispatch fails, standing in for a queue outage.
 * Implements the union of the Dispatcher contract's methods across Laravel 6 to 13.
 */
class ThrowingBus implements Dispatcher
{
    /** @var int */
    public $attempts = 0;

    public function dispatch($command)
    {
        $this->attempts++;

        throw new RuntimeException('queue down');
    }

    public function dispatchSync($command, $handler = null)
    {
        return $this->dispatch($command);
    }

    public function dispatchNow($command, $handler = null)
    {
        return $this->dispatch($command);
    }

    public function dispatchAfterResponse($command, $handler = null)
    {
        $this->dispatch($command);
    }

    public function dispatchToQueue($command)
    {
        return $this->dispatch($command);
    }

    public function chain($jobs = null)
    {
        return null;
    }

    public function batch($jobs)
    {
        return null;
    }

    public function findBatch($batchId)
    {
        return null;
    }

    public function hasCommandHandler($command)
    {
        return false;
    }

    public function getCommandHandler($command)
    {
        return false;
    }

    public function pipeThrough(array $pipes)
    {
        return $this;
    }

    public function map(array $map)
    {
        return $this;
    }
}
