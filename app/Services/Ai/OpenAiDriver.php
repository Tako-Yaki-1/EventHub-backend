<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OpenAiDriver extends HttpAiClient
{
    public function complete(string $system, array $messages, int $maxOutputTokens): array
    {
        $startedAt = $this->start();

        $response = $this->send([
            'model' => (string) config('ai.model'),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ...$messages,
            ],
            'max_tokens' => $maxOutputTokens,
        ]);

        // Some free OpenRouter models reject the system role; fold it into the first user message once.
        if ($response->status() === 400 && $this->rejectsSystemRole($response)) {
            $response = $this->send([
                'model' => (string) config('ai.model'),
                'messages' => $this->mergeSystem($system, $messages),
                'max_tokens' => $maxOutputTokens,
            ]);
        }

        if ($response->failed()) {
            throw $this->failureFrom($response);
        }

        $json = $this->decoded($response);
        $text = $this->textOrFail(data_get($json, 'choices.0.message.content'), $response->status());
        $prompt = $system.collect($messages)->pluck('content')->implode('');

        return $this->normalise(
            $text,
            (int) data_get($json, 'usage.prompt_tokens', 0),
            (int) data_get($json, 'usage.completion_tokens', 0),
            $prompt,
            $this->elapsedMs($startedAt),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload): Response
    {
        try {
            return Http::acceptJson()
                ->withToken((string) config('ai.api_key'))
                ->withHeaders($this->headers())
                ->timeout((int) config('ai.timeout_seconds', 15))
                ->retry(2, 200, fn ($exception) => $this->shouldRetry($exception), throw: false)
                ->post($this->endpoint(), $payload);
        } catch (ConnectionException $exception) {
            throw $this->connectionFailure($exception);
        }
    }

    private function endpoint(): string
    {
        $base = rtrim((string) (config('ai.base_url') ?: 'https://api.openai.com/v1'), '/');

        return str_ends_with($base, '/chat/completions') ? $base : $base.'/chat/completions';
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        if (! str_contains($this->endpoint(), 'openrouter.ai')) {
            return [];
        }

        return [
            'HTTP-Referer' => (string) (config('app.frontend_url') ?: 'http://localhost:5173'),
            'X-Title' => 'EventHub',
        ];
    }

    private function rejectsSystemRole(Response $response): bool
    {
        $body = mb_strtolower($response->body());

        return str_contains($body, 'system') || str_contains($body, 'developer');
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: string, content: string}>
     */
    private function mergeSystem(string $system, array $messages): array
    {
        $messages = array_values($messages);

        if ($messages === []) {
            return [['role' => 'user', 'content' => $system]];
        }

        if (($messages[0]['role'] ?? null) === 'user') {
            $messages[0]['content'] = $system."\n\n".(string) ($messages[0]['content'] ?? '');

            return $messages;
        }

        return [['role' => 'user', 'content' => $system], ...$messages];
    }
}
