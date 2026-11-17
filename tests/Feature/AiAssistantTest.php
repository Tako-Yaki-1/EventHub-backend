<?php

namespace Tests\Feature;

use App\Models\AiRequest;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\FakeAiDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    private function fake(): FakeAiDriver
    {
        return app(FakeAiDriver::class);
    }

    public function test_the_assistant_answers_and_drops_unknown_event_ids(): void
    {
        $event = Event::factory()->create(['capacity' => 10]);
        Event::factory()->create();

        $this->fake()->replies = [
            '{"answer":"Join the workshop.","event_ids":['.$event->id.',999999]}',
        ];

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/ai/assistant', ['question' => 'What should I join?'])
            ->assertOk()
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('answer', 'Join the workshop.')
            ->assertJsonPath('event_ids', [$event->id])
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', $event->id)
            ->assertJsonPath('events.0.title', $event->title);
    }

    public function test_malformed_model_output_becomes_plain_text_with_no_events(): void
    {
        Event::factory()->create();
        $this->fake()->replies = ['Not JSON at all.'];

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/ai/assistant', ['question' => 'Anything on?'])
            ->assertOk()
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('answer', 'Not JSON at all.')
            ->assertJsonPath('event_ids', [])
            ->assertJsonCount(0, 'events');
    }

    public function test_json_inside_code_fences_or_a_short_preamble_is_accepted(): void
    {
        $event = Event::factory()->create();

        $this->fake()->replies = [
            "```json\n{\"answer\":\"From a fence.\",\"event_ids\":[{$event->id}]}\n```",
            'Here you go: {"answer":"From a preamble.","event_ids":[]}',
        ];

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/ai/assistant', ['question' => 'Fenced?'])
            ->assertOk()
            ->assertJsonPath('answer', 'From a fence.')
            ->assertJsonPath('event_ids', [$event->id]);

        $this->postJson('/api/v1/ai/assistant', ['question' => 'Preamble?'])
            ->assertOk()
            ->assertJsonPath('answer', 'From a preamble.')
            ->assertJsonPath('event_ids', []);
    }

    public function test_html_and_script_in_model_output_stays_plain_text(): void
    {
        $this->fake()->replies = [
            json_encode(['answer' => '<script>alert("x")</script>', 'event_ids' => []]),
        ];

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/ai/assistant', ['question' => 'Hi'])
            ->assertOk()
            ->assertJsonPath('answer', '<script>alert("x")</script>');
    }

    public function test_the_outbound_payload_never_contains_user_or_event_personal_data(): void
    {
        $student = User::factory()->create([
            'name' => 'Private Student',
            'email' => 'private.student@school.edu',
            'student_id' => '2023-SECRET',
        ]);

        Event::factory()->create([
            'title' => 'Public Fair',
            'description' => str_repeat('A', 400).' tail',
        ]);

        $this->fake()->replies = ['{"answer":"ok","event_ids":[]}'];

        Sanctum::actingAs($student);

        $this->postJson('/api/v1/ai/assistant', ['question' => 'What is on?'])->assertOk();

        $sent = $this->fake()->lastPrompt();

        $this->assertStringNotContainsString('Private Student', $sent);
        $this->assertStringNotContainsString('private.student@school.edu', $sent);
        $this->assertStringNotContainsString('2023-SECRET', $sent);
        $this->assertStringContainsString('Public Fair', $sent);
        // Descriptions are truncated so a single event cannot flood the prompt.
        $this->assertStringNotContainsString(' tail', $sent);
    }

    public function test_disabled_ai_uses_the_event_search_fallback(): void
    {
        app(AiSettings::class)->update(['ai.assistant_enabled' => false]);

        $event = Event::factory()->create(['title' => 'Robotics Meetup']);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/ai/assistant', ['question' => 'Any robotics events?'])
            ->assertOk()
            ->assertJsonPath('source', 'fallback')
            ->assertJsonPath('message', 'AI is unavailable right now, here are matching events.')
            ->assertJsonPath('event_ids', [$event->id]);

        $this->assertSame([], $this->fake()->requests);
    }

    public function test_unconfigured_ai_uses_the_fallback_and_records_why(): void
    {
        config(['ai.driver' => 'openai', 'ai.api_key' => null, 'ai.model' => null]);

        Event::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/ai/assistant', ['question' => 'What is on?'])
            ->assertOk()
            ->assertJsonPath('source', 'fallback');

        $this->assertDatabaseHas('ai_requests', [
            'feature' => 'assistant',
            'status' => 'fallback',
            'error_code' => 'not_configured',
        ]);
    }

    public function test_a_provider_error_uses_the_fallback(): void
    {
        Event::factory()->create();
        $this->fake()->failWith = new RuntimeException('timed out');

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/ai/assistant', ['question' => 'What is on?'])
            ->assertOk()
            ->assertJsonPath('source', 'fallback');

        $this->assertDatabaseHas('ai_requests', [
            'feature' => 'assistant',
            'status' => 'fallback',
            'error_code' => 'provider_error',
        ]);
    }

    public function test_the_per_minute_rate_limit_returns_429_and_is_recorded(): void
    {
        Event::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/ai/assistant', ['question' => 'What is on?'])->assertOk();
        }

        $this->postJson('/api/v1/ai/assistant', ['question' => 'What is on?'])
            ->assertStatus(429);

        $this->assertDatabaseHas('ai_requests', [
            'feature' => 'assistant',
            'status' => 'rate_limited',
        ]);
    }

    public function test_the_daily_token_limit_returns_429_and_is_recorded_as_blocked(): void
    {
        app(AiSettings::class)->update(['ai.daily_token_limit' => 1]);

        Event::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/ai/assistant', ['question' => 'What is on?'])
            ->assertStatus(429);

        $this->assertDatabaseHas('ai_requests', [
            'feature' => 'assistant',
            'status' => 'blocked',
            'error_code' => 'daily_token_limit',
        ]);
        $this->assertSame([], $this->fake()->requests);
    }

    public function test_every_ai_call_creates_a_usage_row_and_an_audit_entry(): void
    {
        Event::factory()->create();
        $student = User::factory()->create();
        $this->fake()->replies = ['{"answer":"ok","event_ids":[]}'];

        Sanctum::actingAs($student);

        $this->postJson('/api/v1/ai/assistant', ['question' => 'What is on?'])->assertOk();

        $usage = AiRequest::query()->firstOrFail();
        $this->assertSame($student->id, $usage->user_id);
        $this->assertSame('success', $usage->status);
        $this->assertSame(150, $usage->total_tokens);
        $this->assertGreaterThan(0, $usage->prompt_chars);
        $this->assertSame(64, strlen($usage->prompt_hash));
        $this->assertNull($usage->prompt);

        $log = AuditLog::query()->where('action', 'ai.request')->firstOrFail();
        $this->assertSame($student->id, $log->user_id);
        $this->assertSame('assistant', $log->metadata['feature']);
        $this->assertSame('success', $log->metadata['status']);
    }

    public function test_prompts_are_only_stored_when_ai_log_prompts_is_on(): void
    {
        config(['ai.log_prompts' => true]);

        Event::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/ai/assistant', ['question' => 'What is on?'])->assertOk();

        $this->assertNotNull(AiRequest::query()->firstOrFail()->prompt);
    }

    public function test_the_question_is_trimmed_and_control_characters_are_removed(): void
    {
        Event::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/ai/assistant', [
            'question' => "  What\ttoday?\n ",
        ])->assertOk();

        $this->assertStringContainsString('Question: What today?', $this->fake()->lastPrompt());
    }

    public function test_the_question_length_comes_from_the_settings(): void
    {
        app(AiSettings::class)->update(['ai.question_max_length' => 50]);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/ai/assistant', ['question' => str_repeat('a', 51)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('question');
    }
}
