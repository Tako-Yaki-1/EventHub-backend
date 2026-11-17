<?php

return [
    // "fake" is bound only for tests; production uses anthropic or openai.
    'driver' => env('AI_DRIVER', 'openai'),
    'api_key' => env('AI_API_KEY'),
    // OpenAI-compatible endpoints: OpenRouter, Groq, Gemini compatibility, Ollama.
    'base_url' => env('AI_BASE_URL'),
    'model' => env('AI_MODEL'),
    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 15),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 500),
    // Prompts may contain personal data, so they are not stored by default.
    'log_prompts' => (bool) env('AI_LOG_PROMPTS', false),
    // Per-user ceiling; the daily token limit lives in the settings table.
    'requests_per_minute' => 10,
];
