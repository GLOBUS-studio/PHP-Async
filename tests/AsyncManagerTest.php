<?php

declare(strict_types=1);

namespace GlobusStudio\Async\Tests;

use GlobusStudio\Async\Async;
use GlobusStudio\Async\AsyncManager;
use PHPUnit\Framework\TestCase;

final class AsyncManagerTest extends TestCase
{
    protected function setUp(): void
    {
        AsyncManager::clear();
    }

    protected function tearDown(): void
    {
        AsyncManager::clear();
    }

    public function testRunReturnsResultsInPriorityOrder(): void
    {
        $log = [];
        $a = new Async(static function () use (&$log): string { $log[] = 'a'; return 'a'; });
        $b = new Async(static function () use (&$log): string { $log[] = 'b'; return 'b'; });
        $c = new Async(static function () use (&$log): string { $log[] = 'c'; return 'c'; });

        AsyncManager::addTask($a, 1);
        AsyncManager::addTask($b, 5);
        AsyncManager::addTask($c, 3);

        self::assertSame(3, AsyncManager::count());

        $results = AsyncManager::run();
        self::assertSame(['b', 'c', 'a'], $results);
        self::assertSame(['b', 'c', 'a'], $log);
        self::assertSame(0, AsyncManager::count());
    }

    public function testRunCapturesRejectionsAsResultEntries(): void
    {
        $ok = new Async(static fn (): int => 1);
        $bad = new Async(static function (): void {
            throw new \RuntimeException('boom');
        });

        AsyncManager::addTask($ok, 0);
        AsyncManager::addTask($bad, 0);

        $results = AsyncManager::run();
        self::assertSame(1, $results[0]);
        self::assertInstanceOf(\RuntimeException::class, $results[1]);
    }

    public function testSetPriorityRegistersWithManager(): void
    {
        $task = new Async(static fn (): int => 1);
        $task->setPriority(10);
        self::assertSame(1, AsyncManager::count());
    }

    public function testEqualPriorityPreservesInsertionOrder(): void
    {
        $first = new Async(static fn (): string => 'first');
        $second = new Async(static fn (): string => 'second');
        AsyncManager::addTask($first, 1);
        AsyncManager::addTask($second, 1);

        self::assertSame(['first', 'second'], AsyncManager::run());
    }
}
