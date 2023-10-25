# Async PHP Library

Bring modern asynchronous programming to PHP with the Async PHP Library, leveraging the capabilities of PHP fibers.

## Table of Contents

- [Features](#features)
- [Usage](#usage)
- [API Reference](#api-reference)
  - [Async Class](#async-class)
  - [AsyncManager Class](#asyncmanager-class)
- [Contribution](#contribution)
- [License](#license)

## Features

- **Async/Await Paradigm**: Intuitive async/await patterns.
- **Task Prioritization**: Prioritize tasks with ease.
- **Error Handling**: Powerful mechanisms with `then`, `catch`, and `finally`.
- **Event System**: Incorporate custom events with `emit` and `on`.
- **Timeouts**: Set timeouts to prevent overly lengthy operations.
- **Cancellation**: Cancel tasks and operations dynamically.
- **Progress Monitoring**: Stay updated during extended operations.
- **Task Management**: Organize and execute tasks in batches.

## Usage

```php
require 'path/to/library.php'; // Replace with the actual path to library

// Sample tasks for demonstration
function task1(Async $async): string {
    sleep(1); // Simulating some delay
    $async->progress(50); // Reporting task progress at 50%
    sleep(1);
    return "Task 1 completed";
}

function task2(Async $async): string {
    sleep(3); // Simulating a longer delay
    return "Task 2 completed";
}

function task3(Async $async): string {
    sleep(2); // Simulating delay
    $async->emit('customEvent', 'Data for custom event');
    return "Task 3 completed with custom event";
}

function failingTask(Async $async): void {
    throw new Exception("Error in task");
}

// Creating an asynchronous task
$asyncTask1 = new Async('task1');

// Using await method
$result = $asyncTask1->await();
echo $result . PHP_EOL; // Outputs "Task 1 completed"

// Using the then method
$asyncTask1->then(function($result) {
    echo "Then: " . $result . PHP_EOL;
});

// Using the onProgress method to track progress
$asyncTask1->onProgress(function($progress) {
    echo "Task 1 progress: $progress%" . PHP_EOL;
});

// Using on method for custom events
$asyncTask3 = new Async('task3');
$asyncTask3->on('customEvent', function($data) {
    echo "Received from custom event: $data" . PHP_EOL; // Outputs "Data for custom event"
});

// Using the catch method to handle errors
$asyncTask2 = new Async('failingTask');
$asyncTask2->catch(function($error) {
    echo "An error occurred: " . $error->getMessage() . PHP_EOL; // Outputs "Error in task"
});

// Using the finally method
$asyncTask2->finally(function() {
    echo "Task 2 has finished (regardless of outcome)" . PHP_EOL;
});

// Using the timeout method
$asyncTask4 = new Async('task2');
$asyncTask4->timeout(1)->then(function($result) {
    echo $result . PHP_EOL;
})->catch(function($error) {
    echo "An error occurred: " . $error->getMessage() . PHP_EOL; // Might output "Operation timed out."
});

// Using the start method
$asyncTask5 = new Async(function($async) {
    sleep(4);
    return "Task 5 after manual start";
});
$asyncTask5->start();

// Using the all and race methods
$results = Async::all([$asyncTask1, $asyncTask3, $asyncTask4]);
var_dump($results); // Outputs results of completed tasks

$firstFinished = Async::race([$asyncTask1, $asyncTask3, $asyncTask4]);
echo "First task to finish: $firstFinished" . PHP_EOL;

// Using the setPriority method and task manager
$asyncTask1->setPriority(1);
$asyncTask3->setPriority(2);
$asyncTask4->setPriority(3);
AsyncManager::runTasks(); // Runs tasks based on their priority

// Using the cancel method to cancel a task
$asyncTask5->cancel();
```

## API Reference

### Async Class

Represents an individual asynchronous task, offering mechanisms to manage states, capture results or exceptions, and apply an event-driven approach.

#### Methods:

- `__construct(callable $callback)`: Initialize an asynchronous task.
- `await()`: Wait and retrieve the operation's result.
- `then(callable $onFulfilled, callable $onRejected = null)`: Handle operations that are either resolved or rejected.
- `catch(callable $onRejected)`: Handle task errors.
- `finally(callable $callback)`: Execute a callback post-task completion, irrespective of the outcome.
- `on(string $event, callable $listener)`: Set event listeners.
- `timeout(int $seconds)`: Define a timeout duration.
- `cancel()`: Terminate the task.
- `onProgress(callable $callback)`: Monitor progress.
- `progress($data)`: Update progress data.
- `start()`: Commence the async task.
- `setPriority(int $priority)`: Assign priority.
- `all(array $tasks)`: Awaits results from all specified async tasks.
- `race(array $tasks)`: Awaits the result of the first completed task.

### AsyncManager Class

Manages multiple asynchronous tasks, offering prioritization among them.

#### Methods:

- `addTask(Async $task, int $priority = 0)`: Include a task and its optional priority.
- `runTasks()`: Run all tasks in the queue.
- `cancelAllOtherTasks(Async $completedTask)`: Cancel every task except the one specified.

## Contribution

Contributions are welcomed! Please open an issue or send in a pull request to enhance the library.

## License

This library falls under the MIT License. 
