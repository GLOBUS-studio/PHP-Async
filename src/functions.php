<?php

declare(strict_types=1);

namespace GlobusStudio\Async;

use Revolt\EventLoop;

/**
 * Suspend the current fiber for the given number of seconds without
 * blocking the event loop. May be called from any context; outside of a
 * fiber the call drives the loop until the timer fires.
 */
function delay(float $seconds): void
{
    $suspension = EventLoop::getSuspension();
    EventLoop::delay($seconds, static function () use ($suspension): void {
        $suspension->resume();
    });
    $suspension->suspend();
}

/**
 * Create and start a new asynchronous task.
 *
 * @param callable(Async): mixed $callback
 */
function async(callable $callback): Async
{
    return Async::run($callback);
}

/**
 * Await an Async task. Convenience wrapper that mirrors `await` keyword
 * conventions in other languages.
 */
function await(Async $task): mixed
{
    return $task->await();
}
