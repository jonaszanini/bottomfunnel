<?php

namespace App\Services\Google;

use RuntimeException;

class GoogleRequestException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $transient = false)
    {
        parent::__construct($message);
    }
}
