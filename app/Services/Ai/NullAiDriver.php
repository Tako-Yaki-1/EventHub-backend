<?php

namespace App\Services\Ai;

use App\Contracts\AiClient;
use RuntimeException;

class NullAiDriver implements AiClient
{
    public function complete(string $system, array $messages, int $maxOutputTokens): array
    {
        throw new RuntimeException('No AI driver is configured.');
    }
}
