<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_only_sees_upcoming_published_events(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs($student);

        $upcoming = Event::factory()->create([
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
        ]);
        Event::factory()->cancelled()->create([
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
        ]);
        Event::factory()->past()->create();

        $this->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $upcoming->id);
    }

    public function test_events_can_be_searched_by_title_organizer_or_location(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $match = Event::factory()->create([
            'title' => 'Robotics Meetup',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHours(2),
        ]);
        Event::factory()->create([
            'title' => 'Career Fair',
            'starts_at' => now()->addDays(4),
            'ends_at' => now()->addDays(4)->addHours(2),
        ]);

        $this->getJson('/api/v1/events?q=Robotics')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_events_can_be_filtered_to_past(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $past = Event::factory()->past()->create();
        Event::factory()->create([
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHours(2),
        ]);

        $this->getJson('/api/v1/events?when=past')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $past->id);
    }

    public function test_a_student_cannot_list_cancelled_or_all_events(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/events?when=all')->assertForbidden();
        $this->getJson('/api/v1/events?status=cancelled')->assertForbidden();
    }

    public function test_an_admin_can_list_cancelled_events(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $published = Event::factory()->create([
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHours(2),
        ]);
        $cancelled = Event::factory()->cancelled()->create([
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
        ]);

        $this->getJson('/api/v1/events?when=all&status=all')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $published->id])
            ->assertJsonFragment(['id' => $cancelled->id, 'status' => 'cancelled']);
    }

    public function test_event_details_include_slots_left_and_the_callers_registration(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs($student);

        $event = Event::factory()->create(['capacity' => 10]);
        Registration::factory()->count(2)->create(['event_id' => $event->id]);

        $this->getJson("/api/v1/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.capacity', 10)
            ->assertJsonPath('data.registered_count', 2)
            ->assertJsonPath('data.slots_left', 8)
            ->assertJsonPath('data.my_registration', null);

        $own = Registration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $student->id,
        ]);

        $this->getJson("/api/v1/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.my_registration.id', $own->id);
    }

    public function test_guests_cannot_view_event_details(): void
    {
        $event = Event::factory()->create();

        $this->getJson("/api/v1/events/{$event->id}")->assertUnauthorized();
    }

    public function test_events_can_be_filtered_by_a_date_range(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $inRange = Event::factory()->create([
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
        ]);
        Event::factory()->create([
            'starts_at' => now()->addDays(20),
            'ends_at' => now()->addDays(20)->addHours(2),
        ]);

        $from = now()->addDays(1)->toDateString();
        $to = now()->addDays(5)->toDateString();

        $this->getJson("/api/v1/events?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inRange->id);
    }

    public function test_per_page_is_capped(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/events?per_page=1000')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_a_student_cannot_view_a_cancelled_event(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $event = Event::factory()->cancelled()->create();

        $this->getJson("/api/v1/events/{$event->id}")->assertNotFound();
    }

    public function test_an_admin_can_view_a_cancelled_event(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $event = Event::factory()->cancelled()->create();

        $this->getJson("/api/v1/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }
}
