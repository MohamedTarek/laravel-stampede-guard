<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Events;

final class LockAcquired
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
