<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Notification;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'full_name' => 'Jan Niño',
            'student_id' => '2023-00123',
            'year_section' => 'BSIT 3B',
            'email' => 'jan.nino@school.edu',
            'confirmed_attendance' => true,
        ];
    }

    public function test_registering_for_an_event_creates_a_notification(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs($student);
        $event = Event::factory()->create([
            'capacity' => 10,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
        ]);

        $this->postJson("/api/v1/events/{$event->id}/registrations", $this->payload())
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'title' => 'Registration confirmed',
        ]);
    }

    public function test_cancelling_an_event_notifies_only_students_holding_a_slot(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $event = Event::factory()->create();

        $registered = User::factory()->create();
        $cancelled = User::factory()->create();
        $secondRegistered = User::factory()->create();

        Registration::factory()->create(['event_id' => $event->id, 'user_id' => $registered->id]);
        Registration::factory()->cancelled()->create(['event_id' => $event->id, 'user_id' => $cancelled->id]);
        Registration::factory()->create(['event_id' => $event->id, 'user_id' => $secondRegistered->id]);

        $this->deleteJson("/api/v1/events/{$event->id}")->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $registered->id,
            'title' => 'Event cancelled',
            'body' => "{$event->title} has been cancelled.",
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $secondRegistered->id,
            'title' => 'Event cancelled',
        ]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $cancelled->id]);

        // Cancelling twice does not duplicate the notification batch.
        $this->deleteJson("/api/v1/events/{$event->id}")->assertOk();
        $this->assertSame(2, Notification::query()->count());
    }

    public function test_a_student_sees_only_their_own_notifications_with_an_unread_count(): void
    {
        $student = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($student);

        $first = Notification::create([
            'user_id' => $student->id,
            'title' => 'First',
            'body' => 'One',
            'read_at' => now(),
        ]);
        // Same-second timestamps would make latest() ordering non-deterministic.
        $first->forceFill(['created_at' => now()->subMinute()])->save();
        Notification::create([
            'user_id' => $student->id,
            'title' => 'Second',
            'body' => 'Two',
        ]);
        Notification::create([
            'user_id' => $other->id,
            'title' => 'Not yours',
            'body' => 'Three',
        ]);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Second')
            ->assertJsonPath('data.1.title', 'First');
    }

    public function test_marking_a_notification_read_is_owner_only_and_idempotent(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs($student);

        $notification = Notification::create([
            'user_id' => $student->id,
            'title' => 'Read me',
            'body' => 'Body',
        ]);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id);

        $this->assertNotNull($notification->fresh()->read_at);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();
    }

    public function test_a_student_cannot_mark_someone_elses_notification(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $notification = Notification::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Private',
            'body' => 'Body',
        ]);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertForbidden();
    }

    public function test_guests_cannot_read_notifications(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }
}
