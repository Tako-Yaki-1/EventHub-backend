<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Models\AiRequest;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\FakeAiDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AiAnalyticsSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function fake(): FakeAiDriver
    {
        return app(FakeAiDriver::class);
    }

    private function validReply(): string
    {
        return json_encode([
            'findings' => ['Finding one.', 'Finding two.', 'Finding three.'],
            'actions' => ['Action one.', 'Action two.', 'Action three.'],
        ]);
    }

    private function seedDashboard(): void
    {
        $event = Event::factory()->create(['capacity' => 10, 'title' => 'Cloud Workshop']);
        Registration::factory()->count(2)->create(['event_id' => $event->id]);
    }

    public function test_admins_get_a_validated_ai_summary_and_the_call_is_recorded(): void
    {
        $admin = User::factory()->admin()->create();
        $this->seedDashboard();
        $this->fake()->replies = [$this->validReply()];

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/ai/analytics-summary')
            ->assertOk()
            ->assertJsonPath('source', 'ai')
            ->assertJsonCount(3, 'findings')
            ->assertJsonCount(3, 'actions')
            ->assertJsonPath('cached', false);

        $this->assertDatabaseHas('ai_requests', [
            'feature' => 'analytics_summary',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai.request']);
    }

    public function test_the_outbound_payload_contains_no_names_emails_or_student_ids(): void
    {
        $admin = User::factory()->admin()->create();

        $student = User::factory()->create([
            'name' => 'Private Person',
            'email' => 'private.person@school.edu',
            'student_id' => '2023-HIDDEN',
        ]);

        $event = Event::factory()->create(['capacity' => 10, 'title' => 'Cloud Workshop']);
        Registration::factory()->create([
            'user_id' => $student->id,
            'event_id' => $event->id,
            'full_name' => 'Private Person',
            'email' => 'private.person@school.edu',
            'student_id' => '2023-HIDDEN',
        ]);

        $this->fake()->replies = [$this->validReply()];

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/ai/analytics-summary')->assertOk();

        $sent = $this->fake()->lastPrompt();

        $this->assertStringNotContainsString('Private Person', $sent);
        $this->assertStringNotContainsString('private.person@school.edu', $sent);
        $this->assertStringNotContainsString('2023-HIDDEN', $sent);
        $this->assertStringContainsString('Cloud Workshop', $sent);
    }

    public function test_malformed_model_output_falls_back_to_the_rules(): void
    {
        $this->seedDashboard();
        $this->fake()->replies = ['I think things are fine.'];

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/ai/analytics-summary')
            ->assertOk()
            ->assertJsonPath('source', 'rules')
            ->assertJsonPath('message', 'Rule-based summary, because AI is unavailable right now.')
            ->assertJsonCount(3, 'actions');
    }

    public function test_a_fenced_json_summary_is_accepted(): void
    {
        $this->seedDashboard();
        $this->fake()->replies = ["```json\n".$this->validReply()."\n```"];

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/ai/analytics-summary')
            ->assertOk()
            ->assertJsonPath('source', 'ai')
            ->assertJsonCount(3, 'findings');
    }

    public function test_a_summary_with_too_few_points_falls_back_to_the_rules(): void
    {
        $this->seedDashboard();
        $this->fake()->replies = [json_encode([
            'findings' => ['Only one finding.'],
            'actions' => ['One action.'],
        ])];

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/ai/analytics-summary')
            ->assertOk()
            ->assertJsonPath('source', 'rules');
    }

    public function test_disabled_ai_returns_the_rules_without_calling_the_provider(): void
    {
        app(AiSettings::class)->update(['ai.summary_enabled' => false]);
        $this->seedDashboard();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/ai/analytics-summary')
            ->assertOk()
            ->assertJsonPath('source', 'rules');

        $this->assertSame([], $this->fake()->requests);
    }

    public function test_a_provider_error_falls_back_to_the_rules(): void
    {
        $this->seedDashboard();
        $this->fake()->failWith = new RuntimeException('Read timed out');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/ai/analytics-summary')
            ->assertOk()
            ->assertJsonPath('source', 'rules');

        $this->assertDatabaseHas('ai_requests', [
            'feature' => 'analytics_summary',
            'status' => 'fallback',
            'error_code' => 'provider_error',
        ]);
    }

    public function test_repeated_requests_reuse_the_cached_summary_and_spend_no_tokens(): void
    {
        $this->seedDashboard();
        $this->fake()->replies = [$this->validReply()];

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/ai/analytics-summary')
            ->assertOk()
            ->assertJsonPath('cached', false);

        $this->postJson('/api/v1/admin/ai/analytics-summary')
            ->assertOk()
            ->assertJsonPath('cached', true);

        $this->assertCount(1, $this->fake()->requests);
        $this->assertSame(1, AiRequest::query()->count());
    }

    public function test_students_cannot_generate_the_ai_summary(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/admin/ai/analytics-summary')->assertForbidden();
    }

    public function test_the_payload_includes_per_event_cancellation_rates(): void
    {
        $event = Event::factory()->create(['capacity' => 10]);
        Registration::factory()->create(['event_id' => $event->id]);
        Registration::factory()->create([
            'event_id' => $event->id,
            'status' => RegistrationStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        $this->fake()->replies = [$this->validReply()];

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/v1/admin/ai/analytics-summary')->assertOk();

        $sent = $this->fake()->requests[0]['messages'][0]['content'];

        $this->assertStringContainsString('"cancellation_rate":0.5', $sent);
    }

    public function test_rule_based_fallbacks_are_not_cached_so_ai_resumes_immediately(): void
    {
        $this->seedDashboard();
        $this->fake()->failWith = new RuntimeException('Provider down');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/ai/analytics-summary')
            ->assertOk()
            ->assertJsonPath('source', 'rules')
            ->assertJsonPath('cached', false);

        $this->postJson('/api/v1/admin/ai/analytics-summary')
            ->assertOk()
            ->assertJsonPath('source', 'rules')
            ->assertJsonPath('cached', false);

        $this->assertSame(2, AiRequest::query()->count());
    }
}
