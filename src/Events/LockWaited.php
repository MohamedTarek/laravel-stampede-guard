<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Events;

/** Fired only when the mutex was not free on the first attempt. */
final class LockWaited
{
    /**
     * @var string
     *
     * @readonly
     */
    public $key;

    /**
     * @var string|null
     *
     * @readonly
     */
    public $store;

    /**
     * @var float
     *
     * @readonly
     */
    public $waitedSeconds;

    public function __construct(string $key, ?string $store, float $waitedSeconds)
    {
        $this->key = $key;
        $this->store = $store;
        $this->waitedSeconds = $waitedSeconds;
    }
}
