<?php

declare(strict_types=1);

namespace GlobusStudio\Async\Tests;

use GlobusStudio\Async\Async;
use PHPUnit\Framework\TestCase;

use function GlobusStudio\Async\async;
use function GlobusStudio\Async\await;
use function GlobusStudio\Async\delay;

final class FunctionsTest extends TestCase
{
    public function testDelayDoesNotBlockEventLoop(): void
    {
        $start = \microtime(true);

        Async::all([
            Async::run(static function (): void { delay(0.1); }),
            Async::run(static function (): void { delay(0.1); }),
            Async::run(static function (): void { delay(0.1); }),
        ]);

        $elapsed = \microtime(true) - $start;
        self::assertLessThan(0.25, $elapsed, "delay() should run concurrently, took {$elapsed}s");
    }

    public function testAsyncHelperStartsTask(): void
    {
        $task = async(static fn (): int => 99);
        self::assertSame(99, await($task));
    }

    public function testDelayInsideMainContext(): void
    {
        $start = \microtime(true);
        delay(0.05);
        $elapsed = \microtime(true) - $start;
        self::assertGreaterThanOrEqual(0.04, $elapsed);
    }
}
