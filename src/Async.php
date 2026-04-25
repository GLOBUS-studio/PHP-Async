<?php

declare(strict_types=1);

namespace GlobusStudio\Async;

use Fiber;
use GlobusStudio\Async\Exception\CancelledException;
use GlobusStudio\Async\Exception\TimeoutException;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Throwable;

/**
 * Promise-style asynchronous task backed by a Fiber and the Revolt event loop.
 *
 * An Async instance represents an operation that will eventually either
 * resolve with a value or reject with a Throwable. Callbacks registered
 * with then/catch/finally fire exactly once and may be attached at any
 * time, even after the task has already settled.
 *
 * The user callback receives the owning Async instance as its single
 * argument and may use it to emit progress events.
 */
final class Async
{
    public const STATE_PENDING = 'pending';
    public const STATE_FULFILLED = 'fulfilled';
    public const STATE_REJECTED = 'rejected';

    /** @var callable */
    private $callback;

    private ?Fiber $fiber = null;

    private string $state = self::STATE_PENDING;

    private mixed $result = null;

    private ?Throwable $error = null;

    /** @var array<string, list<callable>> */
    private array $listeners = [];

    /** @var list<Suspension> */
    private array $waiters = [];

    private bool $started = false;

    private ?string $timeoutWatcher = null;

    /**
     * @param callable(self): mixed $callback Operation to execute asynchronously.
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    /**
     * Convenience factory that creates and immediately starts a task.
     *
     * @param callable(self): mixed $callback
     */
    public static function run(callable $callback): self
    {
        return (new self($callback))->start();
    }

    /**
     * Schedule the task on the event loop. Idempotent.
     */
    public function start(): self
    {
        if ($this->started || $this->state !== self::STATE_PENDING) {
            return $this;
        }
        $this->started = true;

        EventLoop::queue(function (): void {
            if ($this->state !== self::STATE_PENDING) {
                return;
            }
            $this->fiber = new Fiber(function (): void {
                try {
                    $value = ($this->callback)($this);
                    $this->settle(self::STATE_FULFILLED, $value, null);
                } catch (Throwable $e) {
                    $this->settle(self::STATE_REJECTED, null, $e);
                }
            });
            $this->fiber->start();
        });

        return $this;
    }

    /**
     * Block the current fiber (or main thread) until the task settles.
     *
     * @throws Throwable Re-throws the rejection reason if the task failed.
     */
    public function await(): mixed
    {
        $this->start();

        if ($this->state === self::STATE_PENDING) {
            $suspension = EventLoop::getSuspension();
            $this->waiters[] = $suspension;
            $suspension->suspend();
        }

        if ($this->state === self::STATE_REJECTED) {
            assert($this->error !== null);
            throw $this->error;
        }

        return $this->result;
    }

    /**
     * Register fulfillment and (optionally) rejection handlers.
     *
     * @param callable(mixed): void $onFulfilled
     * @param (callable(Throwable): void)|null $onRejected
     */
    public function then(callable $onFulfilled, ?callable $onRejected = null): self
    {
        $this->on('resolve', $onFulfilled);
        if ($onRejected !== null) {
            $this->on('reject', $onRejected);
        }
        return $this;
    }

    /**
     * Register a rejection handler.
     *
     * @param callable(Throwable): void $onRejected
     */
    public function catch(callable $onRejected): self
    {
        return $this->on('reject', $onRejected);
    }

    /**
     * Register a handler that runs once the task settles, regardless of outcome.
     *
     * @param callable(): void $callback
     */
    public function finally(callable $callback): self
    {
        return $this->on('finally', $callback);
    }

    /**
     * Register a listener for an arbitrary event. Built-in events are
     * "resolve", "reject", "finally", "progress" and "cancel".
     *
     * If the task has already settled, terminal listeners are queued
     * for asynchronous invocation rather than dropped.
     */
    public function on(string $event, callable $listener): self
    {
        $this->listeners[$event][] = $listener;

        if ($event === 'resolve' && $this->state === self::STATE_FULFILLED) {
            $value = $this->result;
            EventLoop::queue(static fn () => $listener($value));
        } elseif ($event === 'reject' && $this->state === self::STATE_REJECTED) {
            $error = $this->error;
            EventLoop::queue(static fn () => $listener($error));
        } elseif ($event === 'finally' && $this->state !== self::STATE_PENDING) {
            EventLoop::queue(static fn () => $listener());
        }

        return $this;
    }

    /**
     * Set a maximum execution time for the task. After the timeout elapses
     * the task is rejected with a TimeoutException unless it has already
     * settled. Calling timeout() implicitly starts the task.
     */
    public function timeout(float $seconds): self
    {
        if ($this->timeoutWatcher !== null) {
            EventLoop::cancel($this->timeoutWatcher);
        }

        $this->timeoutWatcher = EventLoop::delay($seconds, function () use ($seconds): void {
            $this->timeoutWatcher = null;
            if ($this->state === self::STATE_PENDING) {
                $this->settle(
                    self::STATE_REJECTED,
                    null,
                    new TimeoutException(\sprintf('Operation timed out after %.3fs.', $seconds))
                );
            }
        });

        return $this->start();
    }

    /**
     * Cooperatively cancel the task. If the task has not yet settled it is
     * rejected with a CancelledException and the "cancel" event is emitted.
     * The underlying fiber is not forcibly terminated; user code should
     * observe the cancelled state at suspension points.
     */
    public function cancel(): void
    {
        if ($this->state !== self::STATE_PENDING) {
            return;
        }
        $this->emit('cancel', null);
        $this->settle(self::STATE_REJECTED, null, new CancelledException('Task cancelled.'));
    }

    /**
     * Register a progress listener. Sugar for on('progress', ...).
     *
     * @param callable(mixed): void $callback
     */
    public function onProgress(callable $callback): self
    {
        return $this->on('progress', $callback);
    }

    /**
     * Emit a progress update from inside the task callback.
     */
    public function progress(mixed $data): void
    {
        $this->emit('progress', $data);
    }

    /**
     * Enqueue this task in the AsyncManager with the given priority.
     */
    public function setPriority(int $priority): self
    {
        AsyncManager::addTask($this, $priority);
        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    public function isSettled(): bool
    {
        return $this->state !== self::STATE_PENDING;
    }

    /**
     * Await every task and return the resolved values, preserving keys.
     * Rejection of any task propagates immediately.
     *
     * @param array<array-key, self> $tasks
     * @return array<array-key, mixed>
     */
    public static function all(array $tasks): array
    {
        foreach ($tasks as $task) {
            $task->start();
        }
        $results = [];
        foreach ($tasks as $key => $task) {
            $results[$key] = $task->await();
        }
        return $results;
    }

    /**
     * Wait for the first task to settle and return its result. Other tasks
     * are cancelled. If the winner is rejected, the rejection is thrown.
     *
     * @param array<array-key, self> $tasks
     */
    public static function race(array $tasks): mixed
    {
        if ($tasks === []) {
            throw new \InvalidArgumentException('race() requires at least one task.');
        }

        $suspension = EventLoop::getSuspension();
        $settled = false;
        $value = null;
        $error = null;

        $resolve = static function (mixed $r, ?Throwable $e) use (&$settled, &$value, &$error, $suspension): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $value = $r;
            $error = $e;
            $suspension->resume();
        };

        foreach ($tasks as $task) {
            $task->start();
            $task->then(
                static function (mixed $r) use ($resolve): void {
                    $resolve($r, null);
                },
                static function (Throwable $e) use ($resolve): void {
                    $resolve(null, $e);
                }
            );
        }

        $suspension->suspend();

        foreach ($tasks as $task) {
            if ($task->isPending()) {
                $task->cancel();
            }
        }

        if ($error !== null) {
            throw $error;
        }
        return $value;
    }

    /**
     * Wait for the first task to fulfill and return its result. If every
     * task rejects, the last rejection reason is thrown.
     *
     * @param array<array-key, self> $tasks
     */
    public static function any(array $tasks): mixed
    {
        if ($tasks === []) {
            throw new \InvalidArgumentException('any() requires at least one task.');
        }

        $suspension = EventLoop::getSuspension();
        $remaining = \count($tasks);
        $resolved = false;
        $settled = false;
        $value = null;
        $lastError = null;

        foreach ($tasks as $task) {
            $task->start();
            $task->then(
                static function (mixed $r) use (&$value, &$resolved, &$settled, $suspension): void {
                    if ($settled) {
                        return;
                    }
                    $settled = true;
                    $resolved = true;
                    $value = $r;
                    $suspension->resume();
                },
                static function (Throwable $e) use (&$lastError, &$remaining, &$settled, $suspension): void {
                    $lastError = $e;
                    if (--$remaining === 0 && !$settled) {
                        $settled = true;
                        $suspension->resume();
                    }
                }
            );
        }

        $suspension->suspend();

        foreach ($tasks as $task) {
            if ($task->isPending()) {
                $task->cancel();
            }
        }

        if ($resolved) {
            return $value;
        }
        // @codeCoverageIgnoreStart
        assert($lastError !== null);
        throw $lastError;
        // @codeCoverageIgnoreEnd
    }

    private function settle(string $state, mixed $result, ?Throwable $error): void
    {
        if ($this->state !== self::STATE_PENDING) {
            return;
        }
        $this->state = $state;
        $this->result = $result;
        $this->error = $error;

        if ($this->timeoutWatcher !== null) {
            EventLoop::cancel($this->timeoutWatcher);
            $this->timeoutWatcher = null;
        }

        if ($state === self::STATE_FULFILLED) {
            $this->emit('resolve', $result);
        } else {
            $this->emit('reject', $error);
        }
        $this->emit('finally', null);

        $waiters = $this->waiters;
        $this->waiters = [];
        foreach ($waiters as $suspension) {
            $suspension->resume();
        }
    }

    private function emit(string $event, mixed $data): void
    {
        if (empty($this->listeners[$event])) {
            return;
        }
        foreach ($this->listeners[$event] as $listener) {
            try {
                $listener($data);
            } catch (Throwable $e) {
                // Listener exceptions must not derail other listeners or the
                // task itself. Surface them via the event loop's error handler.
                EventLoop::queue(static function () use ($e): void {
                    throw $e;
                });
            }
        }
    }
}
