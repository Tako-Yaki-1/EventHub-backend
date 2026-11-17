<?php

namespace Tests\Feature;

use App\Models\AiRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Ai\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiAdminTest extends TestCase
{
    use RefreshDatabase;

    private function recordUsage(User $user, array $overrides = []): AiRequest
    {
        return AiRequest::create([
            'user_id' => $user->id,
            'feature' => 'assistant',
            'driver' => 'fake',
            'model' => 'fake-model',
            'input_tokens' => 10,
            'output_tokens' => 5,
            'total_tokens' => 15,
            'tokens_estimated' => false,
            'latency_ms' => 3,
            'status' => 'success',
            'prompt_chars' => 40,
            'prompt_hash' => str_repeat('a', 64),
            'created_at' => now(),
            ...$overrides,
        ]);
    }

    public function test_admins_can_read_the_defaults_and_the_api_key_is_never_returned(): void
    {
        config(['ai.driver' => 'openai', 'ai.api_key' => 'sk-secret-test-value', 'ai.model' => 'a-model']);

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/v1/admin/ai/settings')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.assistant_enabled', true)
            ->assertJsonPath('data.summary_enabled', true)
            ->assertJsonPath('data.daily_token_limit', 20000)
            ->assertJsonPath('data.configured', true)
            ->assertJsonMissingPath('data.api_key');

        $this->assertStringNotContainsString('sk-secret-test-value', $response->getContent());
    }

    public function test_updating_settings_persists_them_and_is_audited_with_old_and_new_values(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/admin/ai/settings', [
            'enabled' => false,
            'assistant_enabled' => false,
            'summary_enabled' => true,
            'daily_token_limit' => 1234,
        ])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.assistant_enabled', false)
            ->assertJsonPath('data.daily_token_limit', 1234)
            ->assertJsonPath('changed', ['ai.enabled', 'ai.assistant_enabled', 'ai.daily_token_limit']);

        $this->assertDatabaseHas('settings', ['key' => 'ai.daily_token_limit', 'value' => '1234']);

        $log = AuditLog::query()->where('action', 'ai.settings_changed')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertFalse($log->metadata['changed']['ai.enabled']['new']);
        $this->assertTrue($log->metadata['changed']['ai.enabled']['old']);
        $this->assertSame(20000, $log->metadata['changed']['ai.daily_token_limit']['old']);
    }

    public function test_invalid_settings_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/api/v1/admin/ai/settings', ['daily_token_limit' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('daily_token_limit');

        $this->putJson('/api/v1/admin/ai/settings', ['enabled' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enabled');
    }

    public function test_usage_totals_are_aggregated_per_day_feature_status_and_user(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->create(['name' => 'Jan Niño']);

        $this->recordUsage($student, ['total_tokens' => 40, 'input_tokens' => 30, 'output_tokens' => 10]);
        $this->recordUsage($student, ['feature' => 'analytics_summary', 'status' => 'fallback', 'total_tokens' => 10, 'created_at' => now()->subDay()]);
        $this->recordUsage($admin, ['total_tokens' => 5]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/ai/usage?days=30')
            ->assertOk()
            ->assertJsonPath('totals.requests', 3)
            ->assertJsonPath('totals.tokens', 55)
            ->assertJsonPath('by_feature.0.feature', 'assistant')
            ->assertJsonPath('top_users.0.name', 'Jan Niño')
            ->assertJsonPath('top_users.0.tokens', 50);

        $statuses = collect($response->json('by_status'))->pluck('requests', 'status');
        $this->assertSame(2, $statuses['success']);
        $this->assertSame(1, $statuses['fallback']);
    }

    public function test_settings_and_usage_are_admin_only(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/ai/settings')->assertForbidden();
        $this->putJson('/api/v1/admin/ai/settings', ['enabled' => true])->assertForbidden();
        $this->getJson('/api/v1/admin/ai/usage')->assertForbidden();
        $this->postJson('/api/v1/admin/ai/test')->assertForbidden();
    }

    public function test_admins_can_test_the_connection_and_it_is_counted_and_audited(): void
    {
        config([
            'ai.driver' => 'fake',
            'ai.model' => 'fake-model',
            'ai.api_key' => 'sk-secret-test-value',
        ]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/admin/ai/test')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('error_code', null)
            ->assertJsonPath('http_status', 200)
            ->assertJsonPath('model', 'fake-model')
            ->assertJsonStructure(['ok', 'error_code', 'http_status', 'latency_ms', 'model']);

        $this->assertStringNotContainsString('sk-secret-test-value', $response->getContent());
        $this->assertDatabaseHas('ai_requests', ['feature' => 'ai_test', 'status' => 'success']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ai.test',
            'user_id' => $admin->id,
            'result' => 'success',
        ]);
    }

    public function test_the_connection_test_reports_not_configured_without_leaking_anything(): void
    {
        config(['ai.driver' => 'openai', 'ai.api_key' => null, 'ai.model' => null]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->postJson('/api/v1/admin/ai/test')
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'not_configured')
            ->assertJsonPath('http_status', null);

        $this->assertStringNotContainsString('api_key', $response->getContent());
        $this->assertDatabaseHas('ai_requests', ['feature' => 'ai_test', 'error_code' => 'not_configured']);
    }

    public function test_the_connection_test_only_needs_the_master_switch(): void
    {
        app(AiSettings::class)->update([
            'ai.assistant_enabled' => false,
            'ai.summary_enabled' => false,
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/ai/test')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_the_settings_payload_includes_the_last_provider_error(): void
    {
        config(['ai.driver' => 'openai', 'ai.api_key' => 'sk-secret-test-value', 'ai.model' => 'a-model']);

        $admin = User::factory()->admin()->create();

        $this->recordUsage($admin, [
            'status' => 'fallback',
            'error_code' => 'provider_error',
            'http_status' => 500,
            'error_message' => 'Upstream exploded.',
        ]);
        $this->recordUsage($admin, [
            'status' => 'rate_limited',
            'error_code' => 'rate_limited',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/ai/settings')
            ->assertOk()
            ->assertJsonPath('data.last_error.code', 'provider_error')
            ->assertJsonPath('data.last_error.http_status', 500)
            ->assertJsonPath('data.last_error.message', 'Upstream exploded.')
            ->assertJsonPath('data.configured', true);
    }
}
