<?php

namespace App\Exceptions;

use Exception;

class CropCycleClosedException extends Exception
{
    public const MESSAGE = 'Crop cycle is CLOSED. Reopen cycle to post transactions.';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? self::MESSAGE);
    }
}
