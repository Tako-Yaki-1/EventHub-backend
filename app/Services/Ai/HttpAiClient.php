<?php

namespace App\Services\Ai;

use App\Contracts\AiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

abstract class HttpAiClient implements AiClient
{
    protected function start(): int
    {
        return hrtime(true);
    }

    protected function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * Providers sometimes omit usage numbers; a rough characters / 4 estimate keeps limits working.
     */
    protected function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * @return array{text: string, input_tokens: int, output_tokens: int, latency_ms: int, tokens_estimated: bool}
     */
    protected function normalise(string $text, int $inputTokens, int $outputTokens, string $prompt, int $latencyMs): array
    {
        $estimated = $inputTokens === 0 || $outputTokens === 0;

        return [
            'text' => $text,
            'input_tokens' => $inputTokens ?: $this->estimateTokens($prompt),
            'output_tokens' => $outputTokens ?: $this->estimateTokens($text),
            'latency_ms' => $latencyMs,
            'tokens_estimated' => $estimated,
        ];
    }

    /**
     * Retry connection problems and provider 5xx responses only. A 4xx is a
     * configuration or request problem that a second identical call cannot fix.
     */
    protected function shouldRetry(Throwable $exception): bool
    {
        return ! ($exception instanceof RequestException) || $exception->response->serverError();
    }

    protected function failureFrom(Response $response): AiProviderException
    {
        $message = data_get($response->json(), 'error.message');

        if (! is_string($message) || trim($message) === '') {
            $message = $response->body();
        }

        return new AiProviderException(
            AiProviderException::codeForStatus($response->status()),
            $response->status(),
            $this->sanitize($message) ?? 'The provider rejected the request.',
        );
    }

    protected function connectionFailure(ConnectionException $exception): AiProviderException
    {
        $message = mb_strtolower($exception->getMessage());
        $timedOut = str_contains($message, 'timed out') || str_contains($message, 'timeout');

        return new AiProviderException(
            $timedOut ? 'timeout' : 'provider_error',
            null,
            $timedOut ? 'The provider timed out.' : 'Could not reach the provider.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function decoded(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            throw new AiProviderException(
                'invalid_response',
                $response->status(),
                'The provider did not return JSON.',
            );
        }

        return $json;
    }

    protected function textOrFail(mixed $text, int $status): string
    {
        $text = is_string($text) ? trim($text) : '';

        if ($text === '') {
            throw new AiProviderException(
                'empty_response',
                $status,
                'The provider returned an empty answer.',
            );
        }

        return $text;
    }

    /**
     * Keep provider messages short and free of the API key before they are stored.
     */
    protected function sanitize(?string $message): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        $key = (string) config('ai.api_key');

        if ($key !== '') {
            $message = str_replace($key, '[redacted]', $message);
        }

        $message = preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', '[redacted]', $message) ?? $message;
        $message = preg_replace('/\s+/u', ' ', $message) ?? $message;

        return mb_substr(trim($message), 0, 200);
    }
}
