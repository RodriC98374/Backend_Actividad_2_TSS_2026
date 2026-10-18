<?php

namespace App\Support;

use RuntimeException;

class ApiProblem extends RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $status = 422, public array $errors = [])
    {
        parent::__construct($message);
    }
}
