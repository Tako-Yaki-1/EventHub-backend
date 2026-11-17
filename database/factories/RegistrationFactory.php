<?php

namespace Database\Factories;

use App\Enums\RegistrationStatus;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Registration>
 */
class RegistrationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => Event::factory(),
            'status' => RegistrationStatus::Registered,
            'full_name' => fake()->name(),
            'student_id' => fake()->unique()->numerify('2023-#####'),
            'year_section' => fake()->randomElement(['BSIT 1A', 'BSIT 2B', 'BSIT 3B', 'BSIT 4A', 'BSCS 2A']),
            'email' => fake()->unique()->safeEmail(),
            'confirmed_attendance' => true,
            'registered_at' => now()->subDays(fake()->numberBetween(0, 30)),
            'cancelled_at' => null,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => RegistrationStatus::Cancelled,
            'cancelled_at' => now()->subHours(fake()->numberBetween(1, 48)),
        ]);
    }
}
