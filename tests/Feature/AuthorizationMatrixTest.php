<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function adminRoutes(Event $event, User $target): array
    {
        return [
            ['post', 'events'],
            ['put', "events/{$event->id}"],
            ['delete', "events/{$event->id}"],
            ['get', "events/{$event->id}/registrations"],
            ['get', 'admin/analytics/summary'],
            ['get', 'admin/analytics/registrations-over-time'],
            ['get', 'admin/analytics/events-performance'],
            ['get', 'admin/analytics/top-events'],
            ['get', 'admin/analytics/by-year-section'],
            ['get', 'admin/analytics/insights'],
            ['get', 'admin/audit-logs'],
            ['post', 'admin/audit-logs/verify'],
            ['get', 'admin/users'],
            ['delete', "admin/users/{$target->id}/tokens"],
            ['get', 'admin/ai/settings'],
            ['put', 'admin/ai/settings'],
            ['get', 'admin/ai/usage'],
            ['post', 'admin/ai/analytics-summary'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function studentRoutes(Event $event, Registration $registration): array
    {
        return [
            ['get', 'my/registrations'],
            ['post', "events/{$event->id}/registrations"],
            ['delete', "registrations/{$registration->id}"],
        ];
    }

    public function test_every_admin_route_rejects_students_and_guests(): void
    {
        $event = Event::factory()->create();
        $target = User::factory()->create();
        $routes = $this->adminRoutes($event, $target);

        foreach ($routes as [$method, $uri]) {
            $this->json($method, "/api/v1/{$uri}")
                ->assertStatus(401, "Guest was not rejected from {$method} {$uri}");
        }

        Sanctum::actingAs(User::factory()->create());

        foreach ($routes as [$method, $uri]) {
            $this->json($method, "/api/v1/{$uri}")
                ->assertStatus(403, "Student was not rejected from {$method} {$uri}");
        }
    }

    public function test_every_student_route_rejects_admins_and_guests(): void
    {
        $event = Event::factory()->create();
        $registration = Registration::factory()->create();
        $routes = $this->studentRoutes($event, $registration);

        foreach ($routes as [$method, $uri]) {
            $this->json($method, "/api/v1/{$uri}")
                ->assertStatus(401, "Guest was not rejected from {$method} {$uri}");
        }

        Sanctum::actingAs(User::factory()->admin()->create());

        foreach ($routes as [$method, $uri]) {
            $this->json($method, "/api/v1/{$uri}")
                ->assertStatus(403, "Admin was not rejected from {$method} {$uri}");
        }
    }

    public function test_students_cannot_ask_for_all_events_or_other_statuses(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/events?when=all')->assertForbidden();
        $this->getJson('/api/v1/events?status=cancelled')->assertForbidden();
    }
}
