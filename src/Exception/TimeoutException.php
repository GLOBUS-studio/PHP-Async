<?php

declare(strict_types=1);

namespace GlobusStudio\Async\Exception;

/**
 * Thrown when an asynchronous operation does not complete within the allotted time.
 */
final class TimeoutException extends AsyncException
{
}
