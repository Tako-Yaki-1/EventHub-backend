<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TokenSessionTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(string $token): static
    {
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_login_issues_a_token_that_expires_and_is_named_after_the_device(): void
    {
        config(['security.token_ttl_minutes' => 90]);

        $user = User::factory()->create([
            'email' => 'jan@school.edu',
            'password' => 'password',
        ]);

        $this->withHeader(
            'User-Agent',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        )->postJson('/api/v1/auth/login', [
            'login' => 'jan@school.edu',
            'password' => 'password',
        ])->assertOk();

        $token = PersonalAccessToken::query()->firstOrFail();

        $this->assertSame('web (Chrome on Windows)', $token->name);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue(
            $token->expires_at->between(now()->addMinutes(89), now()->addMinutes(91)),
        );
    }

    public function test_login_with_firefox_and_safari_produces_short_device_names(): void
    {
        config(['security.token_ttl_minutes' => 90]);

        $firefox = User::factory()->create(['password' => 'password']);
        $this->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0')
            ->postJson('/api/v1/auth/login', ['login' => $firefox->email, 'password' => 'password'])
            ->assertOk();

        $safari = User::factory()->create(['password' => 'password']);
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15')
            ->postJson('/api/v1/auth/login', ['login' => $safari->email, 'password' => 'password'])
            ->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'web (Firefox on Linux)']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'web (Safari on macOS)']);
    }

    public function test_an_expired_token_gets_a_401(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('old', ['*'], now()->subMinute())->plainTextToken;

        $this->bearer($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_sessions_lists_active_tokens_without_ever_returning_token_values(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('web (Chrome on Windows)');
        $user->createToken('web (Safari on macOS)');
        $user->createToken('expired', ['*'], now()->subMinute());

        $response = $this->bearer($current->plainTextToken)
            ->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'created_at', 'last_used_at', 'expires_at', 'is_current']],
            ]);

        $rows = collect($response->json('data'));
        $this->assertTrue($rows->firstWhere('name', 'web (Chrome on Windows)')['is_current']);
        $this->assertFalse($rows->firstWhere('name', 'web (Safari on macOS)')['is_current']);
        $this->assertArrayNotHasKey('token', $rows->first());
        $this->assertStringNotContainsString($current->plainTextToken, $response->getContent());
    }

    public function test_a_user_can_revoke_their_own_session(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('web (Chrome on Windows)');
        $other = $user->createToken('web (Safari on macOS)');
        $otherId = $other->accessToken->getKey();

        $this->bearer($current->plainTextToken)
            ->deleteJson("/api/v1/auth/sessions/{$otherId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherId]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.session_revoked',
            'user_id' => $user->id,
            'result' => 'success',
        ]);
    }

    public function test_a_user_cannot_revoke_someone_elses_session(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();
        $victimToken = $victim->createToken('web');
        $current = $user->createToken('web');

        $this->bearer($current->plainTextToken)
            ->deleteJson("/api/v1/auth/sessions/{$victimToken->accessToken->getKey()}")
            ->assertNotFound();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $victimToken->accessToken->getKey()]);
    }

    public function test_an_admin_can_force_logout_a_user_and_the_action_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        $target->createToken('web (Chrome on Windows)');
        $target->createToken('web (Safari on macOS)');

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/admin/users/{$target->id}/tokens")
            ->assertOk()
            ->assertJsonPath('revoked', 2);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.force_logout',
            'user_id' => $admin->id,
            'entity_id' => $target->id,
            'result' => 'success',
        ]);
    }

    public function test_students_cannot_force_logout_a_user(): void
    {
        $student = User::factory()->create();
        $target = User::factory()->create();
        $target->createToken('web');

        Sanctum::actingAs($student);

        $this->deleteJson("/api/v1/admin/users/{$target->id}/tokens")->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_admins_can_list_users_with_session_counts_and_no_password_hashes(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->create();
        $student->createToken('web (Chrome on Windows)');
        $student->createToken('old', ['*'], now()->subMinute());

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $student->id,
                'email' => $student->email,
                'active_sessions_count' => 1,
            ]);

        $response->assertJsonMissingPath('data.0.password');
        $this->assertStringNotContainsString($student->password, $response->getContent());
    }

    public function test_students_cannot_list_all_users(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_every_session_revocation_is_audited(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('web');

        $this->bearer($current->plainTextToken)
            ->deleteJson("/api/v1/auth/sessions/{$current->accessToken->getKey()}")
            ->assertNoContent();

        $log = AuditLog::query()->where('action', 'auth.session_revoked')->firstOrFail();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('web', $log->metadata['name']);
    }

    public function test_the_backfill_migration_gives_old_tokens_an_expiry_from_created_at(): void
    {
        config(['security.token_ttl_minutes' => 60]);

        $user = User::factory()->create();
        $old = $user->createToken('old', ['*'], null)->accessToken;
        $old->forceFill(['created_at' => now()->subHours(2)])->save();

        $kept = $user->createToken('kept', ['*'], now()->addDay())->accessToken;
        $keptExpiry = $kept->expires_at;

        $migration = require database_path('migrations/2026_11_16_000002_backfill_personal_access_token_expiry.php');
        $migration->up();

        $old->refresh();
        $kept->refresh();

        $this->assertNotNull($old->expires_at);
        $this->assertTrue($old->expires_at->between(
            Carbon::parse($old->created_at)->addMinutes(59),
            Carbon::parse($old->created_at)->addMinutes(61),
        ));
        $this->assertTrue($kept->expires_at->equalTo($keptExpiry));
    }

    public function test_the_sessions_endpoint_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('web');

        for ($i = 0; $i < 30; $i++) {
            $this->bearer($current->plainTextToken)
                ->getJson('/api/v1/auth/sessions')
                ->assertOk();
        }

        $this->bearer($current->plainTextToken)
            ->getJson('/api/v1/auth/sessions')
            ->assertStatus(429);
    }
}
