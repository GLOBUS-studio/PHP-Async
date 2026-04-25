<?php

declare(strict_types=1);

namespace GlobusStudio\Async;

/**
 * Priority queue for Async tasks.
 *
 * Tasks added through addTask() (or via Async::setPriority()) are kept in a
 * static queue ordered by descending priority. Calling run() drains the
 * queue, starting each task and awaiting their results.
 */
final class AsyncManager
{
    /** @var list<array{task: Async, priority: int, seq: int}> */
    private static array $tasks = [];

    private static int $sequence = 0;

    /**
     * Append a task to the queue. Tasks with the same priority preserve
     * insertion order.
     */
    public static function addTask(Async $task, int $priority = 0): void
    {
        self::$tasks[] = [
            'task' => $task,
            'priority' => $priority,
            'seq' => self::$sequence++,
        ];

        \usort(self::$tasks, static function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority']
                ?: $a['seq'] <=> $b['seq'];
        });
    }

    /**
     * Start every queued task in priority order and await their completion.
     *
     * @return array<int, mixed> Resolved values, indexed by start order.
     *                           Rejected tasks contribute the thrown Throwable.
     */
    public static function run(): array
    {
        $tasks = self::$tasks;
        self::$tasks = [];

        foreach ($tasks as $entry) {
            $entry['task']->start();
        }

        $results = [];
        foreach ($tasks as $i => $entry) {
            try {
                $results[$i] = $entry['task']->await();
            } catch (\Throwable $e) {
                $results[$i] = $e;
            }
        }
        return $results;
    }

    /**
     * Backwards-compatible alias for run() that ignores the returned values.
     */
    public static function runTasks(): void
    {
        self::run();
    }

    /**
     * Cancel every queued task except the one given.
     */
    public static function cancelAllOtherTasks(Async $completedTask): void
    {
        foreach (self::$tasks as $entry) {
            if ($entry['task'] !== $completedTask) {
                $entry['task']->cancel();
            }
        }
    }

    /**
     * Discard the queue without running anything.
     */
    public static function clear(): void
    {
        self::$tasks = [];
    }

    public static function count(): int
    {
        return \count(self::$tasks);
    }
}
