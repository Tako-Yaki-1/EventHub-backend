<?php

namespace App\Services\Ai;

final class AiCallResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $text = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $message = null,
        public readonly ?int $httpStatus = null,
        public readonly int $latencyMs = 0,
    ) {}

    public function successful(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Rate and daily limits are answered with 429 by the controllers.
     */
    public function limited(): bool
    {
        return in_array($this->status, ['rate_limited', 'blocked'], true);
    }
}
