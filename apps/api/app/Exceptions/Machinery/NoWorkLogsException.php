<?php

namespace App\Exceptions\Machinery;

/**
 * Thrown when no uncharged posted work logs are found for the specified criteria.
 */
class NoWorkLogsException extends DomainValidationException
{
    public function __construct(string $message = 'No uncharged posted work logs found for the specified criteria.')
    {
        $errors = [
            'work_logs' => [$message]
        ];
        
        parent::__construct($message, $errors);
    }
}
