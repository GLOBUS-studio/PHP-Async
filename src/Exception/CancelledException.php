<?php

declare(strict_types=1);

namespace GlobusStudio\Async\Exception;

/**
 * Thrown when an asynchronous operation has been cancelled by the caller.
 */
final class CancelledException extends AsyncException
{
}
