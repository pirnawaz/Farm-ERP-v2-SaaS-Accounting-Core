<?php

namespace App\Exceptions\Machinery;

/**
 * Thrown when a machine has an unsupported meter unit.
 */
class UnsupportedMeterUnitException extends DomainValidationException
{
    public function __construct(string $meterUnit)
    {
        $message = "Unsupported meter unit: {$meterUnit}";
        $errors = [
            'meter_unit' => [$message]
        ];
        
        parent::__construct($message, $errors);
    }
}
