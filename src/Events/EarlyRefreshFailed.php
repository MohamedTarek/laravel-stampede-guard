<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Events;

use Throwable;

final class EarlyRefreshFailed
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
     * @var Throwable
     *
     * @readonly
     */
    public $exception;

    /** @param 'queue'|'inline' $mode */
    public function __construct(string $key, ?string $store, string $mode, Throwable $exception)
    {
        $this->key = $key;
        $this->store = $store;
        $this->mode = $mode;
        $this->exception = $exception;
    }
}
