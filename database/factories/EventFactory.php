<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => Str::title(fake()->words(4, true)),
            'description' => fake()->paragraph(),
            'organizer' => fake()->company(),
            'location' => fake()->randomElement(['Main Hall', 'Student Center', 'Computer Lab 1', 'Gymnasium', 'Auditorium']),
            'starts_at' => now()->addDays(fake()->numberBetween(1, 20))->setTime(fake()->numberBetween(8, 16), 0),
            'ends_at' => fn (array $attributes) => Carbon::parse($attributes['starts_at'])->addHours(2),
            'capacity' => fake()->randomElement([30, 40, 50, 100, 150]),
            'status' => EventStatus::Published,
        ];
    }

    public function past(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subDays(fake()->numberBetween(5, 40))->setTime(10, 0),
            'ends_at' => now()->subDays(fake()->numberBetween(1, 4))->setTime(12, 0),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => EventStatus::Cancelled]);
    }
}
