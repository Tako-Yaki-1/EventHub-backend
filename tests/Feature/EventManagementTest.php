<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Web Development Workshop',
            'description' => 'Hands-on workshop.',
            'organizer' => 'Computer Science Society',
            'location' => 'Computer Lab 1',
            'starts_at' => now()->addDays(5)->toJSON(),
            'ends_at' => now()->addDays(5)->addHours(3)->toJSON(),
            'capacity' => 40,
        ], $overrides);
    }

    public function test_an_admin_can_create_an_event(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/events', $this->payload())
            ->assertCreated()
            ->assertJsonPath('message', 'Event created.')
            ->assertJsonPath('data.title', 'Web Development Workshop')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.registered_count', 0)
            ->assertJsonPath('data.slots_left', 40);

        $this->assertDatabaseHas('events', [
            'title' => 'Web Development Workshop',
            'capacity' => 40,
            'status' => 'published',
        ]);
    }

    public function test_event_creation_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/events', $this->payload([
            'title' => '',
            'starts_at' => now()->subDay()->toJSON(),
            'ends_at' => now()->subDays(2)->toJSON(),
            'capacity' => 0,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'starts_at', 'ends_at', 'capacity']);
    }

    public function test_a_student_cannot_manage_events(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $event = Event::factory()->create();

        $this->postJson('/api/v1/events', $this->payload())->assertForbidden();
        $this->putJson("/api/v1/events/{$event->id}", $this->payload())->assertForbidden();
        $this->deleteJson("/api/v1/events/{$event->id}")->assertForbidden();
    }

    public function test_an_admin_can_update_an_event(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $event = Event::factory()->create(['capacity' => 100]);

        $this->putJson("/api/v1/events/{$event->id}", $this->payload([
            'title' => 'Updated Workshop',
            'capacity' => 60,
        ]))
            ->assertOk()
            ->assertJsonPath('message', 'Event updated.')
            ->assertJsonPath('data.title', 'Updated Workshop')
            ->assertJsonPath('data.capacity', 60);

        $this->assertDatabaseHas('events', ['id' => $event->id, 'title' => 'Updated Workshop']);
    }

    public function test_capacity_cannot_drop_below_the_registered_count(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $event = Event::factory()->create(['capacity' => 100]);
        Registration::factory()->count(3)->create(['event_id' => $event->id]);
        Registration::factory()->cancelled()->create(['event_id' => $event->id]);

        $this->putJson("/api/v1/events/{$event->id}", $this->payload(['capacity' => 2]))
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.capacity.0',
                'Capacity cannot be lower than the 3 students already registered.',
            );

        $this->putJson("/api/v1/events/{$event->id}", $this->payload(['capacity' => 3]))
            ->assertOk();
    }

    public function test_cancelling_an_event_keeps_registrations_and_is_idempotent(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $event = Event::factory()->create();
        $registration = Registration::factory()->create(['event_id' => $event->id]);

        $this->deleteJson("/api/v1/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Event cancelled.');

        $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('registrations', [
            'id' => $registration->id,
            'status' => 'registered',
        ]);

        $this->deleteJson("/api/v1/events/{$event->id}")->assertOk();
    }

    public function test_an_event_that_has_already_started_cannot_be_cancelled(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $event = Event::factory()->past()->create();

        $this->deleteJson("/api/v1/events/{$event->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.event.0', 'Only upcoming events can be cancelled.');

        $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => 'published']);
    }

    public function test_the_attendee_list_is_admin_only_and_uses_snapshots(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $event = Event::factory()->create();

        $registered = Registration::factory()->create([
            'event_id' => $event->id,
            'full_name' => 'Registered Student',
            'status' => 'registered',
        ]);
        Registration::factory()->cancelled()->create([
            'event_id' => $event->id,
            'full_name' => 'Cancelled Student',
        ]);

        $this->getJson("/api/v1/events/{$event->id}/registrations")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $registered->id)
            ->assertJsonPath('data.0.full_name', 'Registered Student');

        $this->getJson("/api/v1/events/{$event->id}/registrations?status=all")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson("/api/v1/events/{$event->id}/registrations?status=cancelled")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Cancelled Student');
    }

    public function test_a_student_cannot_view_the_attendee_list(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $event = Event::factory()->create();

        $this->getJson("/api/v1/events/{$event->id}/registrations")->assertForbidden();
    }
}
