<?php

namespace Fleetbase\Quickbooks\Support;

class QuickBooksException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly ?string $retryAfter = null,
        public readonly ?string $faultCode = null,
    ) {
        parent::__construct($message);
    }

    public function isRateLimit(): bool
    {
        return $this->status === 429;
    }

    public function isUnauthorized(): bool
    {
        return $this->status === 401;
    }

    /**
     * QuickBooks answers a read of a deleted or missing entity with 400 and fault code 610.
     */
    public function isNotFound(): bool
    {
        return $this->status === 404 || ($this->status === 400 && $this->faultCode === '610');
    }
}
