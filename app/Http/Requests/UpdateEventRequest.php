<?php

namespace App\Http\Requests;

use App\Enums\RegistrationStatus;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string'],
            'organizer' => ['required', 'string', 'max:100'],
            'location' => ['required', 'string', 'max:120'],
            // An already-started event can still be edited (venue change, notes).
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['required', 'integer', 'min:1', 'max:10000', $this->capacityRule()],
        ];
    }

    private function capacityRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            /** @var Event $event */
            $event = $this->route('event');

            $registered = $event->registrations()
                ->where('status', RegistrationStatus::Registered->value)
                ->count();

            if ((int) $value < $registered) {
                $fail("Capacity cannot be lower than the {$registered} students already registered.");
            }
        };
    }
}
