<?php

namespace App\Exceptions;

use RuntimeException;

class ProviderUnavailable extends RuntimeException
{
    public function __construct(public readonly string $errorCode = 'provider_timeout')
    {
        parent::__construct('The payment provider is temporarily unavailable.');
    }
}
