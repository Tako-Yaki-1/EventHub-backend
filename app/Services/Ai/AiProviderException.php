<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * A classified provider failure. The message is already sanitized for display.
 */
final class AiProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?int $httpStatus = null,
        ?string $message = null,
    ) {
        parent::__construct($message ?? $errorCode);
    }

    public static function codeForStatus(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'auth_failed',
            $status === 404 => 'model_not_found',
            $status === 429 => 'rate_limited_upstream',
            $status >= 500 => 'provider_error',
            $status === 400 => 'bad_request',
            default => 'provider_error',
        };
    }
}
