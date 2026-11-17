<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListAttendeesRequest;
use App\Http\Requests\ListEventsRequest;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Http\Resources\RegistrationResource;
use App\Models\Event;
use App\Models\Notification;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    public function index(ListEventsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $isAdmin = $request->user()->role === Role::Admin;

        $when = $filters['when'] ?? 'upcoming';
        $status = $filters['status'] ?? 'published';

        if (! $isAdmin && ($when === 'all' || $status !== 'published')) {
            abort(403, 'This action is unauthorized.');
        }

        $events = Event::query()
            ->withRegisteredCount()
            ->search($filters['q'] ?? null);

        if ($status === 'all') {
            if (! $isAdmin) {
                abort(403, 'This action is unauthorized.');
            }
        } else {
            $events->where('status', $status);
        }

        if ($when === 'upcoming') {
            $events->where('starts_at', '>', now());
        } elseif ($when === 'past') {
            $events->where('ends_at', '<', now());
        }

        if (isset($filters['from'])) {
            $events->whereDate('starts_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $events->whereDate('starts_at', '<=', $filters['to']);
        }

        $events->orderBy('starts_at', $when === 'past' ? 'desc' : 'asc');

        return EventResource::collection($events->paginate($filters['per_page'] ?? 12));
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $isAdmin = $request->user()->role === Role::Admin;

        if (! $isAdmin && $event->status === EventStatus::Cancelled) {
            abort(404);
        }

        $event->loadCount([
            'registrations as registered_count' => fn ($query) => $query
                ->where('status', RegistrationStatus::Registered->value),
        ]);

        $registration = $event->registrations()
            ->where('user_id', $request->user()->id)
            ->first();

        return response()->json([
            'data' => array_merge(
                (new EventResource($event))->resolve(),
                [
                    'my_registration' => $registration
                        ? (new RegistrationResource($registration))->resolve()
                        : null,
                ],
            ),
        ]);
    }

    public function store(StoreEventRequest $request, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', Event::class);

        $event = Event::create([
            ...$request->validated(),
            'status' => EventStatus::Published,
        ])->loadCount('registrations as registered_count');

        $audit->record(
            'event.created',
            user: $request->user(),
            entityType: Event::class,
            entityId: $event->id,
            metadata: ['status' => $event->status->value],
            request: $request,
        );

        return response()->json([
            'message' => 'Event created.',
            'data' => (new EventResource($event))->resolve(),
        ], 201);
    }

    public function update(UpdateEventRequest $request, Event $event, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $event);

        $event->update($request->validated());
        $event->loadCount([
            'registrations as registered_count' => fn ($query) => $query
                ->where('status', RegistrationStatus::Registered->value),
        ]);

        $audit->record(
            'event.updated',
            user: $request->user(),
            entityType: Event::class,
            entityId: $event->id,
            metadata: [
                'changed' => array_values(array_diff(array_keys($event->getChanges()), ['updated_at'])),
            ],
            request: $request,
        );

        return response()->json([
            'message' => 'Event updated.',
            'data' => (new EventResource($event))->resolve(),
        ]);
    }

    public function destroy(Request $request, Event $event, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('delete', $event);

        if ($event->status === EventStatus::Cancelled) {
            return response()->json(['message' => 'Event cancelled.']);
        }

        if ($event->starts_at->isPast()) {
            throw ValidationException::withMessages([
                'event' => 'Only upcoming events can be cancelled.',
            ]);
        }

        $event->update(['status' => EventStatus::Cancelled]);
        $this->notifyAttendees($event);

        $audit->record(
            'event.cancelled',
            user: $request->user(),
            entityType: Event::class,
            entityId: $event->id,
            metadata: ['status' => EventStatus::Cancelled->value],
            request: $request,
        );

        return response()->json(['message' => 'Event cancelled.']);
    }

    private function notifyAttendees(Event $event): void
    {
        $now = now();

        $rows = $event->registrations()
            ->where('status', RegistrationStatus::Registered->value)
            ->pluck('user_id')
            ->unique()
            ->map(fn (int $userId) => [
                'user_id' => $userId,
                'title' => 'Event cancelled',
                'body' => "{$event->title} has been cancelled.",
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            Notification::insert($rows);
        }
    }

    public function attendees(ListAttendeesRequest $request, Event $event, AuditLogger $audit): AnonymousResourceCollection
    {
        Gate::authorize('viewAttendees', $event);

        $filters = $request->validated();
        $status = $filters['status'] ?? 'registered';

        $registrations = $event->registrations()
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->orderBy('full_name')
            ->paginate($filters['per_page'] ?? 20);

        $audit->record(
            'event.attendees_viewed',
            user: $request->user(),
            entityType: Event::class,
            entityId: $event->id,
            metadata: ['status' => $status],
            request: $request,
        );

        return RegistrationResource::collection($registrations);
    }
}
