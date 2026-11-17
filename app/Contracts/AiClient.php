<?php

namespace App\Contracts;

interface AiClient
{
    /**
     * Send one completion request and report what it cost.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{text: string, input_tokens: int, output_tokens: int, latency_ms: int, tokens_estimated: bool}
     *
     * @throws \RuntimeException when the provider is unreachable or answers with an error
     */
    public function complete(string $system, array $messages, int $maxOutputTokens): array;
}
