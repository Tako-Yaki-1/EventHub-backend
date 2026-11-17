<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $registeredCount = (int) ($this->registered_count ?? 0);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'organizer' => $this->organizer,
            'location' => $this->location,
            'starts_at' => $this->starts_at?->toJSON(),
            'ends_at' => $this->ends_at?->toJSON(),
            'capacity' => (int) $this->capacity,
            'registered_count' => $registeredCount,
            'slots_left' => max(0, (int) $this->capacity - $registeredCount),
            'status' => $this->status->value,
        ];
    }
}
