<?php

namespace App\Services\Ai;

/**
 * Test-only driver. Bound when AI_DRIVER=fake; never selected in a real environment.
 */
class FakeAiDriver extends HttpAiClient
{
    /** @var list<array{system: string, messages: array, max_output_tokens: int}> */
    public array $requests = [];

    /**
     * Replies are returned in order; the last one repeats.
     *
     * @var list<string>
     */
    public array $replies = [];

    public ?\Throwable $failWith = null;

    public int $inputTokens = 120;

    public int $outputTokens = 30;

    public function complete(string $system, array $messages, int $maxOutputTokens): array
    {
        $this->requests[] = [
            'system' => $system,
            'messages' => $messages,
            'max_output_tokens' => $maxOutputTokens,
        ];

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $text = $this->replies === []
            ? '{"answer":"Nothing matches right now.","event_ids":[]}'
            : (count($this->replies) === 1 ? $this->replies[0] : array_shift($this->replies));

        return [
            'text' => $text,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'latency_ms' => 1,
            'tokens_estimated' => false,
        ];
    }

    public function lastPrompt(): string
    {
        $request = end($this->requests);

        return $request === false
            ? ''
            : $request['system'].json_encode($request['messages']);
    }
}
