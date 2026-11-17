<?php

namespace App\Actions;

use App\Enums\EventStatus;
use App\Enums\RegistrationStatus;
use App\Models\Event;
use App\Models\Notification;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterForEvent
{
    /**
     * @param  array{full_name: string, student_id: string, year_section: string, email: string, confirmed_attendance: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(Event $event, User $user, array $data): Registration
    {
        return DB::transaction(function () use ($event, $user, $data) {
            // shortcut: lockForUpdate is a no-op on SQLite (tests), so true last-slot concurrency is only enforced on MySQL/MariaDB; tests cover the capacity guard.
            // Locking the event row serialises concurrent registrations for the last slot.
            $lockedEvent = Event::whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedEvent->status === EventStatus::Cancelled) {
                throw ValidationException::withMessages(['event' => 'This event has been cancelled.']);
            }

            if ($lockedEvent->starts_at->isPast()) {
                throw ValidationException::withMessages(['event' => 'This event has already started.']);
            }

            $registration = $lockedEvent->registrations()
                ->where('user_id', $user->id)
                ->first();

            if ($registration?->status === RegistrationStatus::Registered) {
                throw ValidationException::withMessages(['event' => 'You are already registered for this event.']);
            }

            if ($lockedEvent->registrations()->registered()->count() >= $lockedEvent->capacity) {
                throw ValidationException::withMessages(['event' => 'This event is already full.']);
            }

            $attributes = [
                'status' => RegistrationStatus::Registered,
                'full_name' => $data['full_name'],
                'student_id' => $data['student_id'],
                'year_section' => $data['year_section'],
                'email' => $data['email'],
                'confirmed_attendance' => $data['confirmed_attendance'],
                'registered_at' => now(),
                'cancelled_at' => null,
            ];

            if ($registration) {
                $registration->update($attributes);
            } else {
                $registration = $lockedEvent->registrations()->create(
                    $attributes + ['user_id' => $user->id]
                );
            }

            Notification::create([
                'user_id' => $user->id,
                'title' => 'Registration confirmed',
                'body' => "You are registered for {$lockedEvent->title}.",
            ]);

            return $registration->load([
                'event' => fn ($query) => $query->withRegisteredCount(),
            ]);
        });
    }
}
