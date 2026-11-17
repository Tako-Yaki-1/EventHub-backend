<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_entries_cannot_be_updated(): void
    {
        $log = app(AuditLogger::class)->record('test.action');

        $this->expectException(LogicException::class);

        $log->update(['action' => 'tampered']);
    }

    public function test_audit_entries_cannot_be_deleted(): void
    {
        $log = app(AuditLogger::class)->record('test.action');

        $this->expectException(LogicException::class);

        $log->delete();
    }

    public function test_failed_logins_are_logged_with_a_masked_identifier_and_no_password(): void
    {
        User::factory()->create(['email' => 'jan@school.edu', 'password' => 'password']);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'jan@school.edu',
            'password' => 'not-the-password',
        ])->assertStatus(422);

        $log = AuditLog::query()->where('action', 'auth.login')->firstOrFail();

        $this->assertSame('failed', $log->result);
        $this->assertSame('j***@school.edu', $log->metadata['identifier']);

        $raw = json_encode($log->getAttributes());
        $this->assertStringNotContainsString('not-the-password', $raw);
        $this->assertStringNotContainsString('jan@school.edu', $raw);
    }

    public function test_admins_can_filter_the_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();
        $logger = app(AuditLogger::class);

        $logger->record('event.created', user: $admin);
        $logger->record('registration.created', user: $other, result: 'success');
        $logger->record('access.denied', user: $other, result: 'denied');

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/v1/admin/audit-logs?action=event.created')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'event.created');

        $this->getJson("/api/v1/admin/audit-logs?user_id={$other->id}&result=denied")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'access.denied');
    }

    public function test_students_cannot_read_the_audit_log(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/audit-logs')->assertForbidden();
    }

    public function test_the_hash_chain_is_intact_and_tampering_is_detected(): void
    {
        $admin = User::factory()->admin()->create();
        $logger = app(AuditLogger::class);

        $logger->record('one', user: $admin);
        $logger->record('two', user: $admin);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/audit-logs/verify')
            ->assertOk()
            ->assertJsonPath('intact', true)
            ->assertJsonPath('checked', 2);

        // Bypass the model to simulate an attacker editing the database directly.
        DB::table('audit_logs')->where('action', 'one')->update(['action' => 'tampered']);

        $this->postJson('/api/v1/admin/audit-logs/verify')
            ->assertOk()
            ->assertJsonPath('intact', false)
            ->assertJsonPath('broken_at', 1);
    }

    public function test_the_audit_log_never_exposes_token_values_or_password_hashes(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        app(AuditLogger::class)->record('auth.login', user: $admin);

        $response = $this->getJson('/api/v1/admin/audit-logs')->assertOk();

        $response->assertJsonMissingPath('data.0.password');
        $this->assertStringNotContainsString($admin->password, $response->getContent());
    }

    public function test_event_actions_and_attendee_views_are_audited(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/v1/events', [
            'title' => 'Audited Workshop',
            'description' => 'A workshop.',
            'organizer' => 'CS Society',
            'location' => 'Lab 1',
            'starts_at' => now()->addDays(5)->toJSON(),
            'ends_at' => now()->addDays(5)->addHours(2)->toJSON(),
            'capacity' => 30,
        ])->assertCreated();

        $eventId = $created->json('data.id');

        $this->putJson("/api/v1/events/{$eventId}", [
            'title' => 'Renamed Workshop',
            'description' => 'A workshop.',
            'organizer' => 'CS Society',
            'location' => 'Lab 1',
            'starts_at' => now()->addDays(5)->toJSON(),
            'ends_at' => now()->addDays(5)->addHours(2)->toJSON(),
            'capacity' => 30,
        ])->assertOk();

        $this->getJson("/api/v1/events/{$eventId}/registrations")->assertOk();

        $this->deleteJson("/api/v1/events/{$eventId}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'event.created',
            'entity_id' => $eventId,
            'result' => 'success',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'event.updated',
            'entity_id' => $eventId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'event.attendees_viewed',
            'entity_id' => $eventId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'event.cancelled',
            'entity_id' => $eventId,
        ]);

        $updated = AuditLog::query()->where('action', 'event.updated')->firstOrFail();
        $this->assertContains('title', $updated->metadata['changed']);
    }

    public function test_registration_creation_and_cancellation_are_audited(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs($student);

        $event = Event::factory()->create([
            'capacity' => 10,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
        ]);

        $created = $this->postJson("/api/v1/events/{$event->id}/registrations", [
            'full_name' => $student->name,
            'student_id' => $student->student_id,
            'year_section' => $student->year_section,
            'email' => $student->email,
            'confirmed_attendance' => true,
        ])->assertCreated();

        $registrationId = $created->json('data.id');

        $this->deleteJson("/api/v1/registrations/{$registrationId}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'registration.created',
            'user_id' => $student->id,
            'entity_id' => $registrationId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'registration.cancelled',
            'user_id' => $student->id,
            'entity_id' => $registrationId,
        ]);
    }

    public function test_profile_updates_store_field_names_not_values(): void
    {
        $student = User::factory()->create(['name' => 'Old Name', 'email' => 'old@school.edu']);
        Sanctum::actingAs($student);

        $this->putJson('/api/v1/profile', ['name' => 'New Name'])->assertOk();

        $log = AuditLog::query()->where('action', 'profile.updated')->firstOrFail();

        $this->assertSame(['name'], $log->metadata['changed']);
        $this->assertStringNotContainsString('Old Name', json_encode($log->metadata));
        $this->assertStringNotContainsString('New Name', json_encode($log->metadata));
    }

    public function test_denied_requests_are_audited(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs($student);

        $this->getJson('/api/v1/admin/audit-logs')->assertForbidden();

        $log = AuditLog::query()->where('action', 'access.denied')->firstOrFail();

        $this->assertSame('denied', $log->result);
        $this->assertSame($student->id, $log->user_id);
        $this->assertSame('api/v1/admin/audit-logs', $log->metadata['path']);
    }

    public function test_signup_login_logout_and_password_resets_are_audited(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Audited Student',
            'email' => 'audited@school.edu',
            'student_id' => '2023-77777',
            'year_section' => 'BSIT 1A',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.signup']);

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => 'audited@school.edu',
            'password' => 'password',
        ])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$login->json('token'))
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'result' => 'success']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'audited@school.edu'])
            ->assertOk();

        $requested = AuditLog::query()->where('action', 'auth.password_reset_requested')->firstOrFail();
        $this->assertSame('a***@school.edu', $requested->metadata['identifier']);

        $user = User::query()->where('email', 'audited@school.edu')->firstOrFail();
        $resetToken = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $resetToken,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset_completed']);
    }
}
