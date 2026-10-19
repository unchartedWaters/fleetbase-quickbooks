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
     * A failure that can pass when the same request is sent again later: QuickBooks could
     * not be reached (status 0), timed out (408), asked Fleetbase to wait (429), or failed
     * on its side (5xx). A 4xx validation fault, such as a duplicate name, is not transient.
     */
    public function isTransient(): bool
    {
        return $this->status === 0 || $this->status === 408 || $this->status === 429 || $this->status >= 500;
    }

    /**
     * QuickBooks answers a read of a deleted or missing entity with 400 and fault code 610.
     */
    public function isNotFound(): bool
    {
        return $this->status === 404 || ($this->status === 400 && $this->faultCode === '610');
    }
}
