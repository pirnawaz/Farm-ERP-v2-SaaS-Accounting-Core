<?php

namespace App\Exceptions\Machinery;

/**
 * Thrown when rate cards are missing for work logs during charge generation.
 */
class MissingRateCardException extends DomainValidationException
{
    public function __construct(string $message, array $workLogContexts = [])
    {
        $errors = [
            'rate_card' => [$message]
        ];
        
        parent::__construct($message, $errors);
    }
}
