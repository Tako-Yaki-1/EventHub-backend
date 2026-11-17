<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegistrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'full_name' => $this->full_name,
            'student_id' => $this->student_id,
            'year_section' => $this->year_section,
            'email' => $this->email,
            'confirmed_attendance' => (bool) $this->confirmed_attendance,
            'registered_at' => $this->registered_at?->toJSON(),
            'cancelled_at' => $this->cancelled_at?->toJSON(),
            'event' => $this->whenLoaded(
                'event',
                fn () => EventResource::make($this->event)
            ),
        ];
    }
}
