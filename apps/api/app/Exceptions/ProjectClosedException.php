<?php

namespace App\Exceptions;

use Exception;

class ProjectClosedException extends Exception
{
    public const MESSAGE = 'Project is CLOSED. No new posting groups may be created for this project.';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? self::MESSAGE);
    }
}
