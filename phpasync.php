<?php

/**
 * Manages asynchronous tasks with priorities.
 *
 * @package AsyncManager
 * @author GLOBUS.studio <admin@globus.studio>
 */

use Fiber\Fiber;

class AsyncManager
{
    private static $tasks = [];

    public static function addTask(Async $task, int $priority = 0)
    {
        self::$tasks[] = [
            'task' => $task,
            'priority' => $priority
        ];

        usort(self::$tasks, function ($a, $b) {
            return $b['priority'] - $a['priority'];
        });
    }

    public static function runTasks()
    {
        while (count(self::$tasks) > 0) {
            $task = array_shift(self::$tasks)['task'];
            $task->start();
        }
    }

    public static function cancelAllOtherTasks(Async $completedTask) {
        foreach (self::$tasks as $taskInfo) {
            $task = $taskInfo['task'];
            if ($task !== $completedTask) {
                $task->cancel();
            }
        }
    }    
}


/**
 * Represents an asynchronous task.
 * 
 * Allows to execute operations asynchronously using fibers, manage their states,
 * and handle results or exceptions using event-driven approach.
 *
 * @package Async
 * @author GLOBUS.studio <admin@globus.studio>
 */

class Async {

    private $fiber;
    private $result;
    private $error;
    private $listeners = [];
    private $startTime;
    private $hasTimeout = false;
    private $isCancelled = false;

    /**
     * Initializes a new asynchronous task.
     *
     * @param callable $callback The operation to be executed asynchronously.
     */    

    public function __construct(callable $callback) {
        $this->startTime = microtime(true);
        $this->fiber = new Fiber(function () use ($callback) {
            try {
                while (!$this->isCancelled) {
                    $this->result = $callback($this);

                    if ($this->hasTimeout && (microtime(true) - $this->startTime) > $this->hasTimeout) {
                        throw new Exception('Operation timed out.');
                    }
                    
                    if (!$this->isCancelled) {
                        $this->emit('resolve', $this->result);
                    }
                }
            } catch (Throwable $e) {
                $this->error = $e;
                $this->emit('reject', $this->error);
            } finally {
                $this->emit('finally');
            }
        });
    }

    /**
     * Awaits the completion of the asynchronous task.
     *
     * @return mixed The result of the asynchronous operation.
     * @throws Exception If operation times out.
     */

    public function await(): mixed {
        if ($this->hasTimeout && (microtime(true) - $this->startTime) > $this->hasTimeout) {
            throw new Exception('Operation timed out.');
        }

        $this->fiber->start();
        if ($this->error) {
            throw $this->error;
        }
        return $this->result;
    }

    /**
     * Registers a callback to be called when the asynchronous task is fulfilled.
     *
     * @param callable $onFulfilled Callback to be executed on task completion.
     * @param callable|null $onRejected Optional callback to be executed on task failure.
     * @return self Returns the current Async instance.
     */

    public function then(callable $onFulfilled, callable $onRejected = null) {
        $this->on('resolve', $onFulfilled);
        if ($onRejected) {
            $this->on('reject', $onRejected);
        }
        return $this;
    }

    /**
     * Registers a callback to be called when the asynchronous task is rejected.
     *
     * @param callable $onRejected Callback to be executed on task failure.
     * @return self Returns the current Async instance.
     */

    public function catch(callable $onRejected) {
        $this->on('reject', $onRejected);
        return $this;
    }

    /**
     * Registers a callback to be called regardless of the asynchronous task's outcome.
     *
     * @param callable $callback Callback to be executed after task completion or failure.
     * @return self Returns the current Async instance.
     */

    public function finally(callable $callback) {
        $this->on('finally', $callback);
        return $this;
    }

    /**
     * Registers an event listener for a specific event.
     *
     * @param string $event The event name.
     * @param callable $listener The callback to be executed when the event is triggered.
     * @return self Returns the current Async instance.
     */

    public function on(string $event, callable $listener) {
        $this->listeners[$event][] = $listener;
        return $this;
    }

    /**
     * Emits/triggers a specified event with the provided data.
     *
     * @param string $event The event name to trigger.
     * @param mixed $data Optional data to be passed to the event listeners.
     * @return void
     */

    private function emit(string $event, $data = null) {
        if (!isset($this->listeners[$event])) {
            return;
        }
        foreach ($this->listeners[$event] as $listener) {
            $listener($data);
        }
    }

    /**
     * Sets a timeout for the asynchronous operation.
     *
     * @param int $seconds The number of seconds before the operation times out.
     * @return self Returns the current Async instance.
     */

    public function timeout(int $seconds) {
        $this->hasTimeout = $seconds;
        return $this;
    }

    /**
     * Awaits and returns results of all provided asynchronous tasks.
     *
     * @param array $tasks An array of Async tasks.
     * @return array The results of the asynchronous tasks.
     */

    public static function all(array $tasks): array {
        $results = [];
        foreach ($tasks as $key => $task) {
            if ($task instanceof self) {
                $results[$key] = $task->await();
            }
        }
        return $results;
    }

    /**
     * Awaits and returns the result of the first completed asynchronous task.
     *
     * @param array $tasks An array of Async tasks.
     * @return mixed The result of the first completed task.
     */

    public static function race(array $tasks) {
        foreach ($tasks as $task) {
            if ($task instanceof self) {
                $result = $task->await();
                AsyncManager::cancelAllOtherTasks($task);
                return $result;
            }
        }
        return null;
    }

    /**
     * Cancels the asynchronous task.
     *
     * @return void
     */

    public function cancel() {
        $this->isCancelled = true;
        $this->emit('cancel');
    }

    /**
     * Registers a callback to be called to report progress of the asynchronous task.
     *
     * @param callable $callback Callback to be executed to report progress.
     * @return self Returns the current Async instance.
     */

    public function onProgress(callable $callback) {
        return $this->on('progress', $callback);
    }

    /**
     * Reports progress of the asynchronous task.
     *
     * @param mixed $data Data about the progress.
     * @return void
     */

    public function progress($data) {
        $this->emit('progress', $data);
    }

    private $isStarted = false;

    /**
     * Starts the asynchronous task.
     *
     * @return void
     */

    public function start()
    {
        if (!$this->isStarted) {
            $this->isStarted = true;
            $this->fiber->start();
        }
    }

    /**
     * Sets the priority for the asynchronous task.
     *
     * @param int $priority The priority level.
     * @return self Returns the current Async instance.
     */

    public function setPriority(int $priority)
    {
        AsyncManager::addTask($this, $priority);
        return $this;
    }
}