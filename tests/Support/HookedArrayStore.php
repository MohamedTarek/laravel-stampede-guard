<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Support;

use Closure;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;

class HookedArrayStore extends ArrayStore
{
    /** @var Closure|null */
    public $afterAcquire;

    public function lock($name, $seconds = 0, $owner = null)
    {
        return new HookedArrayLock($this, $name, $seconds, $owner, $this->afterAcquire);
    }
}

class HookedArrayLock extends ArrayLock
{
    /** @var Closure|null */
    private $hook;

    public function __construct($store, $name, $seconds, $owner, ?Closure $hook)
    {
        parent::__construct($store, $name, $seconds, $owner);
        $this->hook = $hook;
    }

    public function acquire()
    {
        $acquired = parent::acquire();

        if ($acquired && $this->hook !== null) {
            ($this->hook)();
        }

        return $acquired;
    }
}
