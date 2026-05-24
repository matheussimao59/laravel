<?php

namespace App\Support;

use RuntimeException;

final class ExternalServiceException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 400,
        private readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function details(): mixed
    {
        return $this->details;
    }
}
