<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'student_id' => $this->student_id,
            'year_section' => $this->year_section,
            'role' => $this->role->value,
            'active_sessions_count' => (int) ($this->active_sessions_count ?? 0),
            'last_activity_at' => $this->last_activity_at
                ? Carbon::parse($this->last_activity_at)->toIso8601String()
                : null,
        ];
    }
}
