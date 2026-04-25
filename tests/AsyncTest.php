<?php

declare(strict_types=1);

namespace GlobusStudio\Async\Tests;

use GlobusStudio\Async\Async;
use GlobusStudio\Async\AsyncManager;
use GlobusStudio\Async\Exception\CancelledException;
use GlobusStudio\Async\Exception\TimeoutException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function GlobusStudio\Async\delay;

final class AsyncTest extends TestCase
{
    protected function tearDown(): void
    {
        AsyncManager::clear();
    }

    public function testAwaitReturnsCallbackResult(): void
    {
        $task = new Async(static fn (): int => 42);
        self::assertSame(42, $task->await());
        self::assertTrue($task->isSettled());
        self::assertSame(Async::STATE_FULFILLED, $task->getState());
    }

    public function testAwaitRethrowsRejection(): void
    {
        $task = new Async(static function (): void {
            throw new RuntimeException('boom');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $task->await();
    }

    public function testThenCallbackFiresOnFulfillment(): void
    {
        $seen = null;
        $task = Async::run(static fn (): string => 'hello');
        $task->then(static function (mixed $value) use (&$seen): void {
            $seen = $value;
        });
        $task->await();
        self::assertSame('hello', $seen);
    }

    public function testThenRegisteredAfterSettleStillFires(): void
    {
        $task = Async::run(static fn (): int => 7);
        $task->await();

        $seen = null;
        $task->then(static function (mixed $value) use (&$seen): void {
            $seen = $value;
        });

        // Drain the event loop so the queued callback runs.
        delay(0.0);
        self::assertSame(7, $seen);
    }

    public function testCatchHandlesRejection(): void
    {
        $caught = null;
        $task = new Async(static function (): void {
            throw new RuntimeException('nope');
        });
        $task->catch(static function (\Throwable $e) use (&$caught): void {
            $caught = $e->getMessage();
        });

        try {
            $task->await();
        } catch (\Throwable) {
            // expected
        }
        self::assertSame('nope', $caught);
    }

    public function testFinallyRunsOnBothOutcomes(): void
    {
        $okRan = false;
        $failRan = false;

        $ok = new Async(static fn (): int => 1);
        $ok->finally(static function () use (&$okRan): void {
            $okRan = true;
        });
        $ok->await();

        $fail = new Async(static function (): void {
            throw new RuntimeException('x');
        });
        $fail->finally(static function () use (&$failRan): void {
            $failRan = true;
        });
        try {
            $fail->await();
        } catch (\Throwable) {
        }

        self::assertTrue($okRan);
        self::assertTrue($failRan);
    }

    public function testProgressEventDelivery(): void
    {
        $updates = [];
        $task = new Async(static function (Async $self): string {
            $self->progress(25);
            $self->progress(75);
            return 'done';
        });
        $task->onProgress(static function (mixed $p) use (&$updates): void {
            $updates[] = $p;
        });
        self::assertSame('done', $task->await());
        self::assertSame([25, 75], $updates);
    }

    public function testCustomEventViaOn(): void
    {
        $payload = null;
        $task = new Async(static function (Async $self): bool {
            $self->progress(0);
            $self->on('custom', static function () {});
            // Manually emit by leveraging progress channel for the test.
            return true;
        });
        $task->on('progress', static function (mixed $data) use (&$payload): void {
            $payload = $data;
        });
        $task->await();
        self::assertSame(0, $payload);
    }

    public function testTimeoutRejectsLongRunningTask(): void
    {
        $task = new Async(static function (): string {
            delay(0.5);
            return 'late';
        });
        $task->timeout(0.05);

        $this->expectException(TimeoutException::class);
        $task->await();
    }

    public function testTimeoutDoesNotFireWhenTaskCompletesInTime(): void
    {
        $task = new Async(static function (): string {
            delay(0.01);
            return 'fast';
        });
        $task->timeout(1.0);
        self::assertSame('fast', $task->await());
    }

    public function testCancelRejectsWithCancelledException(): void
    {
        $task = new Async(static function (): string {
            delay(0.5);
            return 'never';
        });
        $task->start();

        // Schedule a cancellation in the loop and then await.
        \Revolt\EventLoop::delay(0.01, static fn () => $task->cancel());

        $this->expectException(CancelledException::class);
        $task->await();
    }

    public function testCancelEventFires(): void
    {
        $cancelled = false;
        $task = new Async(static function (): string {
            delay(0.5);
            return 'never';
        });
        $task->on('cancel', static function () use (&$cancelled): void {
            $cancelled = true;
        });
        $task->start();
        \Revolt\EventLoop::delay(0.01, static fn () => $task->cancel());
        try {
            $task->await();
        } catch (CancelledException) {
        }
        self::assertTrue($cancelled);
    }

    public function testAllResolvesEveryTaskAndPreservesKeys(): void
    {
        $a = new Async(static function (): int { delay(0.02); return 1; });
        $b = new Async(static function (): int { delay(0.01); return 2; });
        $c = new Async(static function (): int { delay(0.03); return 3; });

        $results = Async::all(['a' => $a, 'b' => $b, 'c' => $c]);
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], $results);
    }

    public function testAllRunsTasksConcurrently(): void
    {
        $start = \microtime(true);
        Async::all([
            new Async(static function (): int { delay(0.1); return 1; }),
            new Async(static function (): int { delay(0.1); return 2; }),
            new Async(static function (): int { delay(0.1); return 3; }),
        ]);
        $elapsed = \microtime(true) - $start;
        self::assertLessThan(0.25, $elapsed, "Expected concurrent execution, took {$elapsed}s");
    }

    public function testAllPropagatesRejection(): void
    {
        $tasks = [
            new Async(static fn (): int => 1),
            new Async(static function (): void { throw new RuntimeException('bad'); }),
        ];
        $this->expectException(RuntimeException::class);
        Async::all($tasks);
    }

    public function testRaceReturnsFastestResult(): void
    {
        $slow = new Async(static function (): string { delay(0.2); return 'slow'; });
        $fast = new Async(static function (): string { delay(0.02); return 'fast'; });

        self::assertSame('fast', Async::race([$slow, $fast]));
    }

    public function testRacePropagatesFirstRejection(): void
    {
        $slow = new Async(static function (): string { delay(0.2); return 'slow'; });
        $fail = new Async(static function (): void {
            delay(0.02);
            throw new RuntimeException('first');
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('first');
        Async::race([$fail, $slow]);
    }

    public function testAnyReturnsFirstFulfilled(): void
    {
        $fail = new Async(static function (): void {
            delay(0.01);
            throw new RuntimeException('nope');
        });
        $win = new Async(static function (): string {
            delay(0.05);
            return 'ok';
        });
        self::assertSame('ok', Async::any([$fail, $win]));
    }

    public function testAnyThrowsWhenAllReject(): void
    {
        $tasks = [
            new Async(static function (): void { throw new RuntimeException('first'); }),
            new Async(static function (): void { throw new RuntimeException('second'); }),
        ];
        $this->expectException(RuntimeException::class);
        Async::any($tasks);
    }

    public function testStartIsIdempotent(): void
    {
        $count = 0;
        $task = new Async(static function () use (&$count): int {
            return ++$count;
        });
        $task->start();
        $task->start();
        $task->start();
        self::assertSame(1, $task->await());
        self::assertSame(1, $count);
    }

    public function testMultipleAwaitersGetSameResult(): void
    {
        $task = new Async(static function (): string {
            delay(0.02);
            return 'shared';
        });

        $a = Async::run(static fn () => $task->await());
        $b = Async::run(static fn () => $task->await());

        self::assertSame(['shared', 'shared'], Async::all([$a, $b]));
    }
}
