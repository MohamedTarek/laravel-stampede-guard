<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Events;

final class EarlyRefreshScheduled
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

    /** @param 'queue'|'inline' $mode */
    public function __construct(string $key, ?string $store, string $mode)
    {
        $this->key = $key;
        $this->store = $store;
        $this->mode = $mode;
    }
}
