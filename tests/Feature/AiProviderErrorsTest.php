<?php

namespace Tests\Feature;

use App\Models\AiRequest;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiProviderErrorsTest extends TestCase
{
    use RefreshDatabase;

    private function configureOpenAi(string $baseUrl = 'https://openrouter.ai/api/v1', ?string $key = 'test-key-value'): void
    {
        config([
            'ai.driver' => 'openai',
            'ai.api_key' => $key,
            'ai.model' => 'test/model',
            'ai.base_url' => $baseUrl,
            'ai.timeout_seconds' => 5,
            'app.frontend_url' => 'http://localhost:5173',
        ]);
    }

    private function ask(): TestResponse
    {
        Event::factory()->create();

        return $this->postJson('/api/v1/ai/assistant', ['question' => 'What is on?']);
    }

    /**
     * @return array<int, array{int, string}>
     */
    public static function providerStatuses(): array
    {
        return [
            [401, 'auth_failed'],
            [403, 'auth_failed'],
            [404, 'model_not_found'],
            [429, 'rate_limited_upstream'],
            [400, 'bad_request'],
            [500, 'provider_error'],
            [503, 'provider_error'],
        ];
    }

    #[DataProvider('providerStatuses')]
    public function test_provider_statuses_map_to_error_codes_and_keep_the_http_status(int $status, string $code): void
    {
        $this->configureOpenAi();
        Http::fake(['*' => Http::response(['error' => ['message' => 'provider said no']], $status)]);

        Sanctum::actingAs(User::factory()->create());

        $this->ask()->assertOk()->assertJsonPath('source', 'fallback');

        $this->assertDatabaseHas('ai_requests', [
            'feature' => 'assistant',
            'error_code' => $code,
            'http_status' => $status,
        ]);
    }

    public function test_the_openai_driver_sends_bearer_json_and_openrouter_headers(): void
    {
        $this->configureOpenAi();
        Http::fake(['*' => Http::response($this->successBody(), 200)]);

        Sanctum::actingAs(User::factory()->create());
        $this->ask()->assertOk()->assertJsonPath('source', 'ai');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-key-value')
            && $request->hasHeader('HTTP-Referer', 'http://localhost:5173')
            && $request->hasHeader('X-Title', 'EventHub')
            && $request->data()['model'] === 'test/model'
            && $request->data()['messages'][0]['role'] === 'system'
            && $request->data()['max_tokens'] > 0);
    }

    public function test_the_base_url_tolerates_a_trailing_slash_and_an_existing_completions_path(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach ([
            'https://openrouter.ai/api/v1/',
            'https://openrouter.ai/api/v1/chat/completions',
        ] as $baseUrl) {
            $this->configureOpenAi($baseUrl);
            Http::fake(['*' => Http::response($this->successBody(), 200)]);

            $this->ask()->assertOk()->assertJsonPath('source', 'ai');

            Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/chat/completions');
        }
    }

    public function test_a_rejected_system_role_is_retried_once_with_it_merged_into_the_user_message(): void
    {
        $this->configureOpenAi();

        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['message' => 'developer instruction is not supported for this model']], 400)
                ->push($this->successBody('OK'), 200),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->ask()->assertOk()->assertJsonPath('source', 'ai')->assertJsonPath('answer', 'OK');

        Http::assertSentCount(2);

        $second = Http::recorded()->last()[0];

        $this->assertSame('user', $second->data()['messages'][0]['role']);
        $this->assertStringContainsString('EventHub', $second->data()['messages'][0]['content']);
        $this->assertStringContainsString('What is on?', $second->data()['messages'][0]['content']);
    }

    public function test_an_empty_answer_is_an_error_not_a_success(): void
    {
        $this->configureOpenAi();
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => '   ']]],
        ], 200)]);

        Sanctum::actingAs(User::factory()->create());

        $this->ask()->assertOk()->assertJsonPath('source', 'fallback');

        $this->assertDatabaseHas('ai_requests', [
            'feature' => 'assistant',
            'status' => 'fallback',
            'error_code' => 'empty_response',
            'http_status' => 200,
        ]);
    }

    public function test_a_non_json_success_response_is_an_invalid_response(): void
    {
        $this->configureOpenAi();
        Http::fake(['*' => Http::response('not json', 200)]);

        Sanctum::actingAs(User::factory()->create());

        $this->ask()->assertOk()->assertJsonPath('source', 'fallback');

        $this->assertDatabaseHas('ai_requests', [
            'feature' => 'assistant',
            'error_code' => 'invalid_response',
        ]);
    }

    public function test_a_timeout_is_classified_as_a_timeout(): void
    {
        $this->configureOpenAi();
        Sanctum::actingAs(User::factory()->create());

        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5000 milliseconds'));

        $this->ask()->assertOk()->assertJsonPath('source', 'fallback');
        $this->assertDatabaseHas('ai_requests', ['error_code' => 'timeout']);
    }

    public function test_an_unreachable_provider_is_a_provider_error(): void
    {
        $this->configureOpenAi();
        Sanctum::actingAs(User::factory()->create());

        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to openrouter.ai'));

        $this->ask()->assertOk()->assertJsonPath('source', 'fallback');
        $this->assertDatabaseHas('ai_requests', ['error_code' => 'provider_error']);
    }

    public function test_the_key_never_reaches_the_response_the_usage_row_or_the_audit_log(): void
    {
        $this->configureOpenAi();
        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Incorrect API key provided: test-key-value'],
        ], 401)]);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->ask()->assertOk()->assertJsonPath('source', 'fallback');

        $this->assertStringNotContainsString('test-key-value', $response->getContent());

        $row = AiRequest::query()->firstOrFail();
        $this->assertStringNotContainsString('test-key-value', (string) $row->error_message);
        $this->assertStringContainsString('[redacted]', (string) $row->error_message);

        $log = AuditLog::query()->where('action', 'ai.request')->firstOrFail();
        $this->assertStringNotContainsString('test-key-value', json_encode($log->metadata));
    }

    /**
     * @return array<string, mixed>
     */
    private function successBody(string $answer = '{"answer":"Join us.","event_ids":[]}'): array
    {
        return [
            'choices' => [['message' => ['content' => $answer]]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
        ];
    }
}
