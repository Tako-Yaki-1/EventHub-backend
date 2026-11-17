<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function upcomingEvent(int $capacity = 10, int $days = 10): Event
    {
        return Event::factory()->create([
            'capacity' => $capacity,
            'status' => EventStatus::Published,
            'starts_at' => now()->addDays($days),
            'ends_at' => now()->addDays($days)->addHours(2),
        ]);
    }

    private function pastEvent(int $capacity = 10): Event
    {
        return Event::factory()->create([
            'capacity' => $capacity,
            'status' => EventStatus::Published,
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDays(10)->addHours(2),
        ]);
    }

    public function test_the_summary_kpis_match_a_hand_counted_dataset(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $first = $this->upcomingEvent();
        Registration::factory()->count(2)->create(['event_id' => $first->id]);
        Registration::factory()->cancelled()->create(['event_id' => $first->id]);

        $second = $this->upcomingEvent();
        Registration::factory()->count(8)->create(['event_id' => $second->id]);

        $third = $this->upcomingEvent(days: 3);
        Registration::factory()->create(['event_id' => $third->id]);

        $past = $this->pastEvent();
        Registration::factory()->count(4)->create(['event_id' => $past->id]);
        Registration::factory()->count(2)->cancelled()->create(['event_id' => $past->id]);

        Event::factory()->cancelled()->create([
            'capacity' => 10,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHours(2),
        ]);

        Event::factory()->create([
            'capacity' => 20,
            'status' => EventStatus::Published,
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->subDays(30)->addHours(2),
        ]);

        $this->getJson('/api/v1/admin/analytics/summary')
            ->assertOk()
            ->assertJsonPath('total_events', 6)
            ->assertJsonPath('upcoming_events', 3)
            ->assertJsonPath('active_registrations', 15)
            ->assertJsonPath('cancellation_rate', 0.1667)
            ->assertJsonPath('average_fill_rate', 0.3667);
    }

    public function test_registrations_over_time_returns_one_point_per_day_including_zero_days(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $event = $this->upcomingEvent();

        Registration::factory()->count(3)->create([
            'event_id' => $event->id,
            'registered_at' => now(),
        ]);
        Registration::factory()->cancelled()->create([
            'event_id' => $event->id,
            'registered_at' => now()->subDays(20),
            'cancelled_at' => now(),
        ]);
        Registration::factory()->create([
            'event_id' => $event->id,
            'registered_at' => now()->subDays(10),
        ]);

        $response = $this->getJson('/api/v1/admin/analytics/registrations-over-time?days=7')
            ->assertOk()
            ->assertJsonPath('days', 7)
            ->assertJsonCount(7, 'data');

        $points = $response->json('data');

        $this->assertSame(now()->subDays(6)->toDateString(), $points[0]['date']);
        $this->assertSame(0, $points[0]['registrations']);
        $this->assertSame(now()->toDateString(), $points[6]['date']);
        $this->assertSame(3, $points[6]['registrations']);
        $this->assertSame(1, $points[6]['cancellations']);
    }

    public function test_events_performance_only_includes_published_upcoming_events(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $upcoming = $this->upcomingEvent();
        Registration::factory()->count(4)->create(['event_id' => $upcoming->id]);
        $this->pastEvent();
        Event::factory()->cancelled()->create([
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(2),
        ]);

        $this->getJson('/api/v1/admin/analytics/events-performance')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event_id', $upcoming->id)
            ->assertJsonPath('data.0.capacity', 10)
            ->assertJsonPath('data.0.registered', 4)
            ->assertJsonPath('data.0.fill_rate', 0.4);
    }

    public function test_top_events_ranks_by_registered_count_and_respects_the_limit(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $quiet = $this->upcomingEvent();
        Registration::factory()->count(3)->create(['event_id' => $quiet->id]);
        Registration::factory()->count(2)->cancelled()->create(['event_id' => $quiet->id]);

        $busy = $this->upcomingEvent();
        Registration::factory()->count(5)->create(['event_id' => $busy->id]);

        $this->getJson('/api/v1/admin/analytics/top-events?limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event_id', $busy->id)
            ->assertJsonPath('data.0.registrations', 5);
    }

    public function test_registrations_by_year_section_counts_only_active_registrations(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $event = $this->upcomingEvent();

        Registration::factory()->count(2)->create([
            'event_id' => $event->id,
            'year_section' => 'BSIT 3B',
            'status' => 'registered',
        ]);
        Registration::factory()->create([
            'event_id' => $event->id,
            'year_section' => '',
            'status' => 'registered',
        ]);
        Registration::factory()->cancelled()->create([
            'event_id' => $event->id,
            'year_section' => 'BSIT 3B',
        ]);

        $this->getJson('/api/v1/admin/analytics/by-year-section')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.year_section', 'BSIT 3B')
            ->assertJsonPath('data.0.registrations', 2)
            ->assertJsonPath('data.1.year_section', 'Unspecified')
            ->assertJsonPath('data.1.registrations', 1);
    }

    public function test_insights_flag_full_promotion_and_cancellation_problems(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $almostFull = $this->upcomingEvent(days: 10);
        Registration::factory()->count(8)->create(['event_id' => $almostFull->id]);

        $quiet = $this->upcomingEvent(days: 3);
        Registration::factory()->create(['event_id' => $quiet->id]);

        $cancelled = $this->pastEvent();
        Registration::factory()->count(4)->create(['event_id' => $cancelled->id]);
        Registration::factory()->count(2)->cancelled()->create(['event_id' => $cancelled->id]);

        $this->getJson('/api/v1/admin/analytics/insights')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.type', 'needs_promotion')
            ->assertJsonPath('data.1.type', 'high_cancellations')
            ->assertJsonPath('data.2.type', 'almost_full')
            ->assertJsonPath('data.2.title', "{$almostFull->title} is 80% full");
    }

    public function test_students_cannot_read_analytics(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach ([
            'summary',
            'registrations-over-time',
            'events-performance',
            'top-events',
            'by-year-section',
            'insights',
        ] as $endpoint) {
            $this->getJson("/api/v1/admin/analytics/{$endpoint}")->assertForbidden();
        }
    }

    public function test_guests_cannot_read_analytics(): void
    {
        $this->getJson('/api/v1/admin/analytics/summary')->assertUnauthorized();
    }

    public function test_the_time_range_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/analytics/registrations-over-time?days=3')
            ->assertStatus(422)
            ->assertJsonValidationErrors('days');
    }
}
