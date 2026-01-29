<?php

namespace App\Exceptions\Machinery;

use Exception;

/**
 * Base exception for domain validation errors in machinery charge generation.
 * Provides structured error format matching Laravel validation errors.
 */
class DomainValidationException extends Exception
{
    protected array $errors;

    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    /**
     * Get the structured errors array.
     * Format: ['field_key' => ['Error message']]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
