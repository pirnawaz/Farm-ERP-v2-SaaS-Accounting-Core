<?php

namespace App\Exceptions\Machinery;

/**
 * Thrown when charges have already been generated for the specified scope/date range.
 */
class AlreadyChargedException extends DomainValidationException
{
    public function __construct(string $message = 'Charges already generated for selected scope/date range.')
    {
        $errors = [
            'already_charged' => [$message]
        ];
        
        parent::__construct($message, $errors);
    }
}
