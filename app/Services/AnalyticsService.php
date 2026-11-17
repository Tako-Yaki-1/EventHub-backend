<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\RegistrationStatus;
use App\Models\Event;
use App\Models\Registration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    /**
     * @return array<string, int|float>
     */
    public function summary(): array
    {
        $eventCounts = Event::query()
            ->selectRaw('count(*) as total_events')
            ->selectRaw(
                'sum(case when status = ? and starts_at > ? then 1 else 0 end) as upcoming_events',
                [EventStatus::Published->value, now()],
            )
            ->first();

        $registrationCounts = Registration::query()
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as active', [RegistrationStatus::Registered->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as cancelled', [RegistrationStatus::Cancelled->value])
            ->first();

        $active = (int) ($registrationCounts->active ?? 0);
        $cancelled = (int) ($registrationCounts->cancelled ?? 0);
        $total = $active + $cancelled;

        $fill = Event::query()
            ->upcoming()
            ->leftJoinSub(
                Registration::query()
                    ->selectRaw('event_id, count(*) as registered')
                    ->where('status', RegistrationStatus::Registered->value)
                    ->groupBy('event_id'),
                'registration_counts',
                'events.id',
                '=',
                'registration_counts.event_id',
            )
            ->selectRaw(
                'coalesce(sum(case when coalesce(registration_counts.registered, 0) >= events.capacity'
                .' then 1.0 else coalesce(registration_counts.registered, 0) * 1.0 / events.capacity end)'
                .' / nullif(count(*), 0), 0) as average_fill_rate',
            )
            ->first();

        return [
            'total_events' => (int) ($eventCounts->total_events ?? 0),
            'upcoming_events' => (int) ($eventCounts->upcoming_events ?? 0),
            'active_registrations' => $active,
            'cancellation_rate' => $total > 0 ? round($cancelled / $total, 4) : 0.0,
            'average_fill_rate' => round((float) ($fill->average_fill_rate ?? 0), 4),
        ];
    }

    /**
     * @return list<array{date: string, registrations: int, cancellations: int}>
     */
    public function registrationsOverTime(int $days): array
    {
        $from = Carbon::today()->subDays($days - 1);

        $registrations = Registration::query()
            ->where('registered_at', '>=', $from)
            ->selectRaw('DATE(registered_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $cancellations = Registration::query()
            ->whereNotNull('cancelled_at')
            ->where('cancelled_at', '>=', $from)
            ->selectRaw('DATE(cancelled_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $points = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $from->copy()->addDays($offset)->toDateString();

            $points[] = [
                'date' => $date,
                'registrations' => (int) ($registrations[$date] ?? 0),
                'cancellations' => (int) ($cancellations[$date] ?? 0),
            ];
        }

        return $points;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eventsPerformance(): array
    {
        return Event::query()
            ->upcoming()
            ->withRegisteredCount()
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Event $event) => [
                'event_id' => $event->id,
                'title' => $event->title,
                'capacity' => $event->capacity,
                'registered' => (int) $event->registered_count,
                'fill_rate' => $event->capacity > 0
                    ? round(min(1, $event->registered_count / $event->capacity), 4)
                    : 0.0,
                'starts_at' => $event->starts_at?->toJSON(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{event_id: int, title: string, registrations: int}>
     */
    public function topEvents(int $limit): array
    {
        return Event::query()
            ->select(['id', 'title'])
            ->withCount([
                'registrations as registrations_count' => fn ($query) => $query
                    ->where('status', RegistrationStatus::Registered->value),
            ])
            ->orderByDesc('registrations_count')
            ->limit($limit)
            ->get()
            ->map(fn (Event $event) => [
                'event_id' => $event->id,
                'title' => $event->title,
                'registrations' => (int) $event->registrations_count,
            ])
            ->all();
    }

    /**
     * @return list<array{year_section: string, registrations: int}>
     */
    public function byYearSection(): array
    {
        // Normalise first, then group: MySQL rejects grouping by an expression
        // when the selected expression references the ungrouped column.
        return DB::query()
            ->fromSub(
                Registration::query()
                    ->registered()
                    ->selectRaw("coalesce(nullif(year_section, ''), 'Unspecified') as year_section"),
                'sections',
            )
            ->selectRaw('year_section, count(*) as registrations')
            ->groupBy('year_section')
            ->orderByDesc('registrations')
            ->get()
            ->map(fn ($row) => [
                'year_section' => $row->year_section,
                'registrations' => (int) $row->registrations,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function insights(): array
    {
        // Only events are loaded here; every count is aggregated in SQL.
        $events = Event::query()
            ->withCount([
                'registrations as registered_count' => fn ($query) => $query
                    ->where('status', RegistrationStatus::Registered->value),
                'registrations as cancelled_count' => fn ($query) => $query
                    ->where('status', RegistrationStatus::Cancelled->value),
            ])
            ->get();

        $insights = [];

        foreach ($events as $event) {
            $registered = (int) $event->registered_count;
            $cancelled = (int) $event->cancelled_count;
            $capacity = (int) $event->capacity;
            $fill = $capacity > 0 ? $registered / $capacity : 0.0;
            $upcoming = $event->status === EventStatus::Published && $event->starts_at?->isFuture();

            if ($upcoming && $fill >= 0.8) {
                $insights[] = [
                    'type' => 'almost_full',
                    'severity' => 'info',
                    'event_id' => $event->id,
                    'title' => "{$event->title} is ".round($fill * 100).'% full',
                    'message' => "{$registered} of {$capacity} slots are taken. Consider opening a second session or raising capacity.",
                    'fill_rate' => $fill,
                ];
            }

            if ($upcoming && $event->starts_at->lessThanOrEqualTo(now()->addDays(7)) && $fill < 0.3) {
                $days = max(0, (int) now()->startOfDay()->diffInDays($event->starts_at->copy()->startOfDay()));
                $starts = $days === 0 ? 'starts today' : "starts in {$days} days";

                $insights[] = [
                    'type' => 'needs_promotion',
                    'severity' => 'warning',
                    'event_id' => $event->id,
                    'title' => "{$event->title} {$starts} at ".round($fill * 100).'%',
                    'message' => "Only {$registered} of {$capacity} slots are taken. Promote it in class group chats this week.",
                    'fill_rate' => $fill,
                ];
            }

            $total = $registered + $cancelled;

            if ($total > 0 && $cancelled / $total > 0.2) {
                $rate = round($cancelled / $total * 100);

                $insights[] = [
                    'type' => 'high_cancellations',
                    'severity' => 'warning',
                    'event_id' => $event->id,
                    'title' => "{$event->title} has {$rate}% cancellations",
                    'message' => "{$rate}% of registrations were cancelled. Check the schedule or send a reminder.",
                    'fill_rate' => $fill,
                ];
            }
        }

        usort($insights, function (array $a, array $b): int {
            $severity = ($a['severity'] === 'warning' ? 0 : 1) <=> ($b['severity'] === 'warning' ? 0 : 1);

            return $severity !== 0 ? $severity : $a['fill_rate'] <=> $b['fill_rate'];
        });

        return $insights;
    }
}
