<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Events;

final class EarlyRefreshCompleted
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
     * @var 'queue'|'inline'
     *
     * @readonly
     */
    public $mode;

    /**
     * @var float
     *
     * @readonly
     */
    public $computeSeconds;

    /** @param 'queue'|'inline' $mode */
    public function __construct(string $key, ?string $store, string $mode, float $computeSeconds)
    {
        $this->key = $key;
        $this->store = $store;
        $this->mode = $mode;
        $this->computeSeconds = $computeSeconds;
    }
}
