<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AnthropicDriver extends HttpAiClient
{
    public function complete(string $system, array $messages, int $maxOutputTokens): array
    {
        $startedAt = $this->start();

        $response = $this->send($system, $messages, $maxOutputTokens);

        if ($response->failed()) {
            throw $this->failureFrom($response);
        }

        $json = $this->decoded($response);
        $text = $this->textOrFail(data_get($json, 'content.0.text'), $response->status());
        $prompt = $system.collect($messages)->pluck('content')->implode('');

        return $this->normalise(
            $text,
            (int) data_get($json, 'usage.input_tokens', 0),
            (int) data_get($json, 'usage.output_tokens', 0),
            $prompt,
            $this->elapsedMs($startedAt),
        );
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function send(string $system, array $messages, int $maxOutputTokens): Response
    {
        try {
            return Http::acceptJson()
                ->withHeaders([
                    'x-api-key' => (string) config('ai.api_key'),
                    'anthropic-version' => '2023-06-01',
                ])
                ->timeout((int) config('ai.timeout_seconds', 15))
                ->retry(2, 200, fn ($exception) => $this->shouldRetry($exception), throw: false)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => (string) config('ai.model'),
                    'max_tokens' => $maxOutputTokens,
                    'system' => $system,
                    'messages' => $messages,
                ]);
        } catch (ConnectionException $exception) {
            throw $this->connectionFailure($exception);
        }
    }
}
