<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\RegistrationStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
        'organizer',
        'location',
        'starts_at',
        'ends_at',
        'capacity',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'status' => EventStatus::class,
        ];
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', EventStatus::Published->value)
            ->where('starts_at', '>', now());
    }

    public function scopePast(Builder $query): Builder
    {
        return $query->where('ends_at', '<', now());
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', EventStatus::Published->value);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term) {
            $query->where('title', 'like', "%{$term}%")
                ->orWhere('organizer', 'like', "%{$term}%")
                ->orWhere('location', 'like', "%{$term}%");
        });
    }

    public function scopeWithRegisteredCount(Builder $query): Builder
    {
        return $query->withCount([
            'registrations as registered_count' => fn (Builder $query) => $query
                ->where('status', RegistrationStatus::Registered->value),
        ]);
    }

    public function slotsLeft(): int
    {
        return max(0, $this->capacity - (int) $this->registered_count);
    }
}
