<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Jan Niño',
            'student_id' => '2023-00123',
            'year_section' => 'BSIT 3B',
            'email' => 'jan.nino@school.edu',
            'confirmed_attendance' => true,
        ], $overrides);
    }

    private function upcomingEvent(array $overrides = []): Event
    {
        return Event::factory()->create(array_merge([
            'capacity' => 10,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
        ], $overrides));
    }

    public function test_a_student_can_register_for_an_upcoming_event(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs($student);
        $event = $this->upcomingEvent();

        $this->postJson("/api/v1/events/{$event->id}/registrations", $this->payload())
            ->assertCreated()
            ->assertJsonPath('message', 'You are registered.')
            ->assertJsonPath('data.status', 'registered')
            ->assertJsonPath('data.event.id', $event->id);

        $this->assertDatabaseHas('registrations', [
            'user_id' => $student->id,
            'event_id' => $event->id,
            'status' => 'registered',
            'confirmed_attendance' => true,
        ]);

        $this->getJson("/api/v1/events/{$event->id}")
            ->assertJsonPath('data.slots_left', 9);
    }

    public function test_the_registration_form_snapshot_is_stored(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $event = $this->upcomingEvent();

        $this->postJson("/api/v1/events/{$event->id}/registrations", $this->payload([
            'full_name' => 'Name From The Form',
            'year_section' => 'BSCS 4A',
        ]))->assertCreated();

        $this->assertDatabaseHas('registrations', [
            'full_name' => 'Name From The Form',
            'year_section' => 'BSCS 4A',
        ]);
    }

    public function test_the_confirmation_checkbox_is_required(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $event = $this->upcomingEvent();

        $this->postJson("/api/v1/events/{$event->id}/registrations", $this->payload([
            'confirmed_attendance' => false,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmed_attendance');
    }

    public function test_a_student_cannot_register_twice(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs($student);
        $event = $this->upcomingEvent();

        Registration::factory()->create([
            'user_id' => $student->id,
            'event_id' => $event->id,
        ]);

        $this->postJson("/api/v1/events/{$event->id}/registrations", $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('errors.event.0', 'You are already registered for this event.');
    }

    public function test_a_full_event_rejects_new_registrations(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $event = $this->upcomingEvent(['capacity' => 1]);

        Registration::factory()->create(['event_id' => $event->id]);

        $this->postJson("/api/v1/events/{$event->id}/registrations", $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('errors.event.0', 'This event is already full.');
    }

    public function test_only_one_student_can_take_the_last_slot(): void
    {
        $event = $this->upcomingEvent(['capacity' => 1]);

        $first = User::factory()->create();
        Sanctum::actingAs($first);
        $this->postJson("/api/v1/events/{$event->id}/registrations", $this->payload())
            ->assertCreated();

        $second = User::factory()->create();
        Sanctum::actingAs($second);
        $this->postJson("/api/v1/events/{$event->id}/registrations", $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('errors.event.0', 'This event is already full.');

        $this->assertDatabaseCount('registrations', 1);
    }

    public function test_an_event_that_has_started_rejects_registrations(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $event = $this->upcomingEvent([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->postJson("/api/v1/events/{$event->id}/registrations", $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('errors.event.0', 'This event has already started.');
    }

    public function test_a_cancelled_event_rejects_registrations(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $event = Event::factory()->cancelled()->create([
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
        ]);

        $this->postJson("/api/v1/events/{$event->id}/registrations", $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('errors.event.0', 'This event has been cancelled.');
    }

    public function test_an_admin_cannot_register_for_an_event(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $event = $this->upcomingEvent();

        $this->postJson("/api/v1/events/{$event->id}/registrations", $this->payload())
            ->assertForbidden();
    }

    public function test_a_cancelled_student_can_register_again_on_the_same_row(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs($student);
        $event = $this->upcomingEvent();

        $registration = Registration::factory()->cancelled()->create([
            'user_id' => $student->id,
            'event_id' => $event->id,
            'cancelled_at' => now()->subDay(),
        ]);

        $this->postJson("/api/v1/events/{$event->id}/registrations", $this->payload([
            'full_name' => 'Jan Niño Updated',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.id', $registration->id)
            ->assertJsonPath('data.status', 'registered')
            ->assertJsonPath('data.cancelled_at', null);

        $this->assertDatabaseCount('registrations', 1);
        $this->assertDatabaseHas('registrations', [
            'id' => $registration->id,
            'status' => 'registered',
            'full_name' => 'Jan Niño Updated',
        ]);
    }

    public function test_a_student_can_cancel_their_own_registration(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs($student);
        $registration = Registration::factory()->create(['user_id' => $student->id]);

        $this->deleteJson("/api/v1/registrations/{$registration->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Registration cancelled.')
            ->assertJsonPath('data.status', 'cancelled');

        $registration->refresh();
        $this->assertSame(RegistrationStatus::Cancelled, $registration->status);
        $this->assertNotNull($registration->cancelled_at);
        $this->assertDatabaseCount('registrations', 1);
    }

    public function test_a_student_cannot_cancel_someone_elses_registration(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $registration = Registration::factory()->create(['user_id' => $owner->id]);

        Sanctum::actingAs($intruder);

        $this->deleteJson("/api/v1/registrations/{$registration->id}")->assertForbidden();

        $this->assertSame(RegistrationStatus::Registered, $registration->fresh()->status);
    }

    public function test_cancelling_twice_is_idempotent(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs($student);
        $registration = Registration::factory()->create(['user_id' => $student->id]);

        $this->deleteJson("/api/v1/registrations/{$registration->id}")->assertOk();
        $cancelledAt = $registration->fresh()->cancelled_at;

        $this->deleteJson("/api/v1/registrations/{$registration->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertEquals($cancelledAt, $registration->fresh()->cancelled_at);
    }

    public function test_cancelled_registrations_do_not_consume_slots(): void
    {
        $event = $this->upcomingEvent(['capacity' => 1]);
        $firstStudent = User::factory()->create();
        $secondStudent = User::factory()->create();

        $registration = Registration::factory()->create([
            'user_id' => $firstStudent->id,
            'event_id' => $event->id,
        ]);

        Sanctum::actingAs($firstStudent);
        $this->deleteJson("/api/v1/registrations/{$registration->id}")->assertOk();

        Sanctum::actingAs($secondStudent);
        $this->postJson("/api/v1/events/{$event->id}/registrations", $this->payload())
            ->assertCreated();

        $this->getJson("/api/v1/events/{$event->id}")
            ->assertJsonPath('data.registered_count', 1)
            ->assertJsonPath('data.slots_left', 0);

        $this->getJson('/api/v1/my/registrations')
            ->assertJsonPath('upcoming.data.0.event.registered_count', 1)
            ->assertJsonPath('upcoming.data.0.event.slots_left', 0);
    }

    public function test_an_admin_cannot_use_student_registration_routes(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $registration = Registration::factory()->create();

        $this->getJson('/api/v1/my/registrations')->assertForbidden();
        $this->deleteJson("/api/v1/registrations/{$registration->id}")->assertForbidden();
    }

    public function test_my_registrations_returns_upcoming_past_and_cancelled_sections(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs($student);

        $upcoming = Registration::factory()->create([
            'user_id' => $student->id,
            'event_id' => Event::factory()->create([
                'starts_at' => now()->addDays(3),
                'ends_at' => now()->addDays(3)->addHours(2),
            ])->id,
        ]);
        $past = Registration::factory()->create([
            'user_id' => $student->id,
            'event_id' => Event::factory()->create([
                'starts_at' => now()->subDays(3),
                'ends_at' => now()->subDays(3)->addHours(2),
            ])->id,
        ]);
        $cancelled = Registration::factory()->cancelled()->create([
            'user_id' => $student->id,
            'cancelled_at' => now()->subHour(),
        ]);
        Registration::factory()->create();

        $this->getJson('/api/v1/my/registrations')
            ->assertOk()
            ->assertJsonCount(1, 'upcoming.data')
            ->assertJsonCount(1, 'past.data')
            ->assertJsonCount(1, 'cancelled.data')
            ->assertJsonPath('upcoming.data.0.id', $upcoming->id)
            ->assertJsonPath('past.data.0.id', $past->id)
            ->assertJsonPath('cancelled.data.0.id', $cancelled->id)
            ->assertJsonPath('upcoming.meta.total', 1)
            ->assertJsonPath('past.meta.total', 1)
            ->assertJsonPath('cancelled.meta.total', 1);
    }

    public function test_my_registrations_are_ordered_per_section(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs($student);

        $upcomingLater = $this->registerFor($student, now()->addDays(10), now()->subDay());
        $upcomingSooner = $this->registerFor($student, now()->addDays(2), now()->subDays(5));
        $pastOlder = $this->registerFor($student, now()->subDays(10), now()->subDays(20));
        $pastNewer = $this->registerFor($student, now()->subDays(2), now()->subDays(3));
        $cancelledOlder = $this->cancelFor($student, now()->subDays(4));
        $cancelledNewer = $this->cancelFor($student, now()->subHour());

        $this->getJson('/api/v1/my/registrations')
            ->assertOk()
            ->assertJsonPath('upcoming.data.0.id', $upcomingSooner->id)
            ->assertJsonPath('upcoming.data.1.id', $upcomingLater->id)
            ->assertJsonPath('past.data.0.id', $pastNewer->id)
            ->assertJsonPath('past.data.1.id', $pastOlder->id)
            ->assertJsonPath('cancelled.data.0.id', $cancelledNewer->id)
            ->assertJsonPath('cancelled.data.1.id', $cancelledOlder->id);
    }

    public function test_each_registration_section_paginates_on_its_own(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs($student);

        foreach (range(1, 13) as $day) {
            $this->registerFor($student, now()->addDays($day), now()->subDay());
        }

        $this->getJson('/api/v1/my/registrations?upcoming_page=2')
            ->assertOk()
            ->assertJsonCount(1, 'upcoming.data')
            ->assertJsonPath('upcoming.meta.current_page', 2)
            ->assertJsonPath('upcoming.meta.last_page', 2)
            ->assertJsonPath('upcoming.meta.total', 13)
            ->assertJsonCount(0, 'past.data')
            ->assertJsonCount(0, 'cancelled.data');
    }

    private function registerFor(User $student, Carbon $startsAt, Carbon $registeredAt): Registration
    {
        $event = Event::factory()->create([
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(2),
        ]);

        return Registration::factory()->create([
            'user_id' => $student->id,
            'event_id' => $event->id,
            'registered_at' => $registeredAt,
        ]);
    }

    private function cancelFor(User $student, Carbon $cancelledAt): Registration
    {
        return Registration::factory()->cancelled()->create([
            'user_id' => $student->id,
            'cancelled_at' => $cancelledAt,
        ]);
    }
}
