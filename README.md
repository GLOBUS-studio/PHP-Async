# PHP-Async

[![CI](https://github.com/GLOBUS-studio/PHP-Async/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/GLOBUS-studio/PHP-Async/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://www.php.net/)

Promise-style asynchronous programming for PHP, built on top of [Revolt][revolt]
fibers and the event loop. Concurrency without `pthreads`, without
`pcntl_fork`, and without blocking `sleep()`.

## Features

- **`async` / `await`** semantics on top of native PHP fibers.
- **Non-blocking** `delay()` powered by Revolt's event loop.
- **Promise combinators**: `Async::all()`, `Async::race()`, `Async::any()`.
- **Timeouts** via `timeout()` that schedules a real loop timer.
- **Cooperative cancellation** with a dedicated `CancelledException`.
- **Event hooks**: `then`, `catch`, `finally`, `progress`, plus arbitrary
  custom events through `on()`.
- **Priority queue** via `AsyncManager` for batched work.
- **PSR-4**, strict types, fully tested with PHPUnit.

## Requirements

- PHP **8.1** or newer (Fibers).
- [`revolt/event-loop`](https://packagist.org/packages/revolt/event-loop) `^1.0`.

## Installation

```bash
composer require globus-studio/async
```

## Quick start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use GlobusStudio\Async\Async;
use function GlobusStudio\Async\{async, await, delay};

$task = async(function (Async $self): string {
    $self->progress(50);
    delay(0.5);          // non-blocking sleep
    $self->progress(100);
    return 'done';
});

$task->onProgress(fn ($pct) => printf("progress: %d%%\n", $pct));

echo await($task), PHP_EOL;
```

## Concurrency

```php
use GlobusStudio\Async\Async;
use function GlobusStudio\Async\delay;

$results = Async::all([
    'a' => Async::run(function () { delay(0.5); return 'A'; }),
    'b' => Async::run(function () { delay(0.5); return 'B'; }),
    'c' => Async::run(function () { delay(0.5); return 'C'; }),
]);
// All three finish in ~0.5s, not 1.5s.
```

`race()` returns the first settled task and cancels the rest. `any()` returns
the first **fulfilled** task, throwing only if every task rejects.

## Timeouts and cancellation

```php
$slow = new Async(function () {
    delay(10);
    return 'never';
});

try {
    $slow->timeout(0.25)->await();
} catch (\GlobusStudio\Async\Exception\TimeoutException $e) {
    // Operation timed out after 0.250s.
}
```

Cancellation is cooperative. Calling `cancel()` rejects the task with
`CancelledException` and emits a `cancel` event, but does not forcibly
abort the underlying fiber. User code should observe cancellation at
suspension points.

## Error handling

```php
Async::run(function () {
    throw new RuntimeException('nope');
})
->catch(fn (\Throwable $e) => error_log($e->getMessage()))
->finally(fn () => print "cleanup\n");
```

Listeners attached *after* a task settles are still invoked (asynchronously,
on the next loop tick) so that ordering does not matter.

## Priority queue

```php
use GlobusStudio\Async\AsyncManager;

(new Async($high))->setPriority(10);
(new Async($low))->setPriority(1);

$results = AsyncManager::run(); // high-priority task starts first
```

## API reference

### `Async`

| Method | Description |
| --- | --- |
| `__construct(callable $cb)` | Create a task. `$cb` receives the `Async` instance. |
| `static run(callable $cb): self` | Create and immediately start. |
| `start(): self` | Schedule the task on the event loop. Idempotent. |
| `await(): mixed` | Suspend until settled; rethrows on rejection. |
| `then(callable $ok, ?callable $err = null): self` | |
| `catch(callable $err): self` | |
| `finally(callable $cb): self` | |
| `on(string $event, callable $l): self` | Generic event subscription. |
| `onProgress(callable $cb): self` / `progress(mixed $data)` | Progress channel. |
| `timeout(float $seconds): self` | Reject with `TimeoutException` if not settled in time. |
| `cancel(): void` | Reject with `CancelledException`. |
| `getState(): string` / `isPending(): bool` / `isSettled(): bool` | State inspection. |
| `setPriority(int $p): self` | Enqueue in `AsyncManager`. |
| `static all(array $tasks): array` | Concurrent join, preserves keys. |
| `static race(array $tasks): mixed` | First to settle wins. |
| `static any(array $tasks): mixed` | First to fulfill wins. |

### `AsyncManager`

| Method | Description |
| --- | --- |
| `static addTask(Async $t, int $priority = 0): void` | |
| `static run(): array` | Drain the queue, returning per-task results (or `Throwable` on failure). |
| `static runTasks(): void` | Alias of `run()` for backwards compatibility. |
| `static cancelAllOtherTasks(Async $keep): void` | |
| `static clear(): void` / `count(): int` | |

### Helpers (`GlobusStudio\Async` namespace)

- `async(callable $cb): Async`
- `await(Async $task): mixed`
- `delay(float $seconds): void`

## Running the test suite

```bash
composer install
composer test
```

The suite covers fulfillment, rejection, timeouts, cancellation, concurrency
guarantees of `all`/`race`/`any`, listener ordering, and `AsyncManager`
prioritisation.

## License

MIT. See [LICENSE](LICENSE).

[revolt]: https://revolt.run/
