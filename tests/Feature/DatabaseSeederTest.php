<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_events_and_registrations_keep_the_demo_dates_consistent(): void
    {
        $this->seed();

        $upcoming = Event::query()->where('starts_at', '>', now())->get();

        $this->assertCount(8, $upcoming);

        foreach ($upcoming as $event) {
            $local = $event->starts_at->copy()->setTimezone('Asia/Manila');

            $this->assertSame('2026-11', $local->format('Y-m'), $event->title);
            $this->assertGreaterThanOrEqual(23, (int) $local->format('j'), $event->title);
            $this->assertLessThanOrEqual(30, (int) $local->format('j'), $event->title);
        }

        $this->assertSame(3, Event::query()->where('starts_at', '<', now())->count());

        foreach (Registration::query()->with('event')->get() as $registration) {
            $this->assertTrue($registration->registered_at->lt($registration->event->starts_at));

            if ($registration->cancelled_at) {
                $this->assertTrue($registration->cancelled_at->gte($registration->registered_at));
                $this->assertFalse($registration->cancelled_at->isFuture());
            }
        }
    }

    public function test_seeding_twice_does_not_duplicate_rows(): void
    {
        $this->seed();

        $users = User::query()->count();
        $events = Event::query()->count();
        $registrations = Registration::query()->count();

        $this->seed();

        $this->assertSame($users, User::query()->count());
        $this->assertSame($events, Event::query()->count());
        $this->assertSame($registrations, Registration::query()->count());
    }

    public function test_seed_passwords_come_from_config_and_fake_students_get_random_ones(): void
    {
        config([
            'app.seed_admin_password' => 'admin-demo-pass',
            'app.seed_student_password' => 'student-demo-pass',
        ]);

        $this->seed();

        $admin = User::query()->where('email', 'admin@eventhub.test')->firstOrFail();
        $student = User::query()->where('email', 'jan.nino@school.edu')->firstOrFail();

        $this->assertTrue(Hash::check('admin-demo-pass', $admin->password));
        $this->assertTrue(Hash::check('student-demo-pass', $student->password));

        $fake = User::query()
            ->where('role', Role::Student->value)
            ->where('email', '!=', 'jan.nino@school.edu')
            ->firstOrFail();

        $this->assertFalse(Hash::check('password', $fake->password));
    }
}
