<?php

declare(strict_types=1);

namespace MohamedTarek\Stampede\Tests\Concurrency;

use Closure;

/**
 * Forks N children that each run $work in a fresh Redis connection. A child that
 * returns true exits with SIGKILL, false with SIGTERM, so PHPUnit's shutdown handlers
 * never run inside the child and the parent reads the signal instead of an exit code.
 */
trait ForksWorkers
{
    /** @return array{ok: int, failed: int} */
    protected function fork(int $workers, Closure $work): array
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix extensions are required for concurrency tests.');
        }

        $pids = [];

        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }

            if ($pid === 0) {
                // Child: drop the inherited Redis socket so each worker connects on its own.
                $this->app->forgetInstance('redis');
                $this->app['cache']->forgetDriver('redis');

                $ok = false;

                try {
                    $ok = (bool) $work($i);
                } catch (\Throwable $e) {
                    fwrite(STDERR, "worker {$i}: ".$e->getMessage()."\n");
                }

                posix_kill(posix_getpid(), $ok ? SIGKILL : SIGTERM);
                exit(1); // unreachable
            }

            $pids[] = $pid;
        }

        $result = ['ok' => 0, 'failed' => 0];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $signal = pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null;
            $result[$signal === SIGKILL ? 'ok' : 'failed']++;
        }

        return $result;
    }
}
