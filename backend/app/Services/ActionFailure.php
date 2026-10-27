<?php

namespace App\Services;

class ActionFailure extends \RuntimeException
{
    public function __construct(public string $errorCode, public bool $retryable = false, public bool $uncertain = false)
    {
        parent::__construct($errorCode);
    }
}
