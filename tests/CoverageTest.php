<?php

declare(strict_types=1);

namespace GlobusStudio\Async\Tests;

use GlobusStudio\Async\Async;
use GlobusStudio\Async\AsyncManager;
use GlobusStudio\Async\Exception\AsyncException;
use GlobusStudio\Async\Exception\CancelledException;
use GlobusStudio\Async\Exception\TimeoutException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

use function GlobusStudio\Async\delay;

/**
 * Targeted tests that exercise edge cases and error paths so that line
 * coverage of the library reaches 100%.
 */
final class CoverageTest extends TestCase
{
    protected function tearDown(): void
    {
        AsyncManager::clear();
    }

    public function testCatchRegisteredAfterRejectionStillFires(): void
    {
        $task = new Async(static function (): void {
            throw new RuntimeException('late-catch');
        });
        try {
            $task->await();
        } catch (RuntimeException) {
            // expected
        }

        $seen = null;
        $task->catch(static function (\Throwable $e) use (&$seen): void {
            $seen = $e->getMessage();
        });
        delay(0.0);
        self::assertSame('late-catch', $seen);
    }

    public function testFinallyRegisteredAfterSettleStillFires(): void
    {
        $task = Async::run(static fn (): int => 5);
        $task->await();

        $ran = false;
        $task->finally(static function () use (&$ran): void {
            $ran = true;
        });
        delay(0.0);
        self::assertTrue($ran);
    }

    public function testTimeoutCalledTwiceCancelsPreviousWatcher(): void
    {
        $task = new Async(static function (): string {
            delay(0.1);
            return 'value';
        });
        $task->timeout(0.001);   // would fire almost instantly
        $task->timeout(1.0);     // replaces the first one
        self::assertSame('value', $task->await());
    }

    public function testCancelOnSettledTaskIsNoOp(): void
    {
        $task = Async::run(static fn (): int => 1);
        $task->await();

        // Cancellation after fulfillment must not change state nor throw.
        $task->cancel();
        $task->cancel();
        self::assertSame(Async::STATE_FULFILLED, $task->getState());
        self::assertSame(1, $task->await());
    }

    public function testCancelBeforeFiberStartsPreventsExecution(): void
    {
        $executed = false;
        $task = new Async(static function () use (&$executed): string {
            $executed = true;
            return 'should not run';
        });
        $task->start();
        $task->cancel();          // settles before the queued fiber starts

        try {
            $task->await();
            self::fail('Expected CancelledException');
        } catch (CancelledException) {
            // expected
        }

        // Drain a tick to let any queued startup execute (it must self-skip).
        delay(0.0);
        self::assertFalse($executed);
    }

    public function testRaceRequiresAtLeastOneTask(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Async::race([]);
    }

    public function testAnyRequiresAtLeastOneTask(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Async::any([]);
    }

    public function testListenerExceptionsAreIsolated(): void
    {
        $loop = EventLoop::getDriver();
        $captured = [];
        $previous = $loop->getErrorHandler();
        $loop->setErrorHandler(static function (\Throwable $e) use (&$captured): void {
            $captured[] = $e->getMessage();
        });

        try {
            $secondCalled = false;
            $task = new Async(static fn (): int => 7);
            $task->then(static function (): void {
                throw new RuntimeException('listener-boom');
            });
            $task->then(static function () use (&$secondCalled): void {
                $secondCalled = true;
            });
            self::assertSame(7, $task->await());
            self::assertTrue($secondCalled, 'second listener must still run');
            // Allow the queued error to surface through the loop.
            delay(0.0);
            self::assertContains('listener-boom', $captured);
        } finally {
            $loop->setErrorHandler($previous);
        }
    }

    public function testManagerRunTasksAliasDrainsQueue(): void
    {
        $ran = 0;
        AsyncManager::addTask(new Async(static function () use (&$ran): int {
            return ++$ran;
        }), 0);
        AsyncManager::addTask(new Async(static function () use (&$ran): int {
            return ++$ran;
        }), 0);
        AsyncManager::runTasks();
        self::assertSame(2, $ran);
        self::assertSame(0, AsyncManager::count());
    }

    public function testManagerCancelAllOtherTasks(): void
    {
        $keep = new Async(static function (): string {
            delay(0.05);
            return 'keep';
        });
        $drop1 = new Async(static function (): string {
            delay(1.0);
            return 'drop1';
        });
        $drop2 = new Async(static function (): string {
            delay(1.0);
            return 'drop2';
        });

        AsyncManager::addTask($keep, 0);
        AsyncManager::addTask($drop1, 0);
        AsyncManager::addTask($drop2, 0);

        // Start them manually (the manager would do this in run()).
        $keep->start();
        $drop1->start();
        $drop2->start();

        AsyncManager::cancelAllOtherTasks($keep);

        self::assertSame('keep', $keep->await());
        $this->expectException(CancelledException::class);
        $drop1->await();
    }

    public function testExceptionHierarchy(): void
    {
        self::assertTrue(\is_subclass_of(TimeoutException::class, AsyncException::class));
        self::assertTrue(\is_subclass_of(CancelledException::class, AsyncException::class));
        self::assertInstanceOf(\RuntimeException::class, new AsyncException());
    }

    public function testAnyIgnoresLateAdditionalFulfillments(): void
    {
        // Both tasks fulfill before any() is called, so their then-callbacks
        // are queued via EventLoop::queue and run in order on the next tick.
        // The second one must hit the duplicate-resolution guard.
        $a = Async::run(static fn (): string => 'first');
        $b = Async::run(static fn (): string => 'second');
        // Drain the loop so both fibers complete and settle.
        delay(0.0);
        self::assertTrue($a->isSettled());
        self::assertTrue($b->isSettled());

        $winner = Async::any([$a, $b]);
        self::assertContains($winner, ['first', 'second']);
    }

    public function testRaceIgnoresLateAdditionalSettlements(): void
    {
        $a = Async::run(static fn (): string => 'a');
        $b = Async::run(static fn (): string => 'b');
        delay(0.0);

        $winner = Async::race([$a, $b]);
        self::assertContains($winner, ['a', 'b']);
    }

    public function testAnyCancelsStillPendingTasksAfterFulfillment(): void
    {
        $fast = new Async(static function (): string {
            delay(0.02);
            return 'fast';
        });
        $slow = new Async(static function (): string {
            delay(1.0);
            return 'slow';
        });

        self::assertSame('fast', Async::any([$fast, $slow]));
        self::assertSame(Async::STATE_REJECTED, $slow->getState());
    }
}
