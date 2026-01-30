<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when close/reopen preconditions fail (e.g. no posted settlement).
 * Rendered as 422 with message.
 */
class CropCycleCloseException extends Exception
{
}
