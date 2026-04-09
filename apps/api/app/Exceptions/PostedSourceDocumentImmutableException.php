<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PostedSourceDocumentImmutableException extends UnprocessableEntityHttpException
{
    public function __construct(string $message = 'Posted records cannot be modified or deleted.')
    {
        parent::__construct($message);
    }
}
