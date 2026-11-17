<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CancelRegistration;
use App\Actions\RegisterForEvent;
use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRegistrationRequest;
use App\Http\Resources\RegistrationResource;
use App\Models\Event;
use App\Models\Registration;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RegistrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'upcoming_page' => ['sometimes', 'integer', 'min:1'],
            'past_page' => ['sometimes', 'integer', 'min:1'],
            'cancelled_page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $registered = RegistrationStatus::Registered->value;

        // The split and the ordering live here so each section paginates correctly on its own.
        $base = fn (): Builder => Registration::query()
            ->select('registrations.*')
            ->leftJoin('events', 'events.id', '=', 'registrations.event_id')
            ->with(['event' => fn ($query) => $query->withRegisteredCount()])
            ->where('registrations.user_id', $request->user()->id);

        $upcoming = $base()
            ->where('registrations.status', $registered)
            ->where('events.starts_at', '>', now())
            ->orderBy('events.starts_at')
            ->orderBy('registrations.id')
            ->paginate(12, ['*'], 'upcoming_page', (int) ($validated['upcoming_page'] ?? 1));

        $past = $base()
            ->where('registrations.status', $registered)
            ->where('events.starts_at', '<=', now())
            ->orderByDesc('events.starts_at')
            ->orderByDesc('registrations.id')
            ->paginate(12, ['*'], 'past_page', (int) ($validated['past_page'] ?? 1));

        $cancelled = $base()
            ->where('registrations.status', RegistrationStatus::Cancelled->value)
            ->orderByDesc('registrations.cancelled_at')
            ->orderByDesc('registrations.id')
            ->paginate(12, ['*'], 'cancelled_page', (int) ($validated['cancelled_page'] ?? 1));

        return response()->json([
            'upcoming' => RegistrationResource::collection($upcoming)->response()->getData(true),
            'past' => RegistrationResource::collection($past)->response()->getData(true),
            'cancelled' => RegistrationResource::collection($cancelled)->response()->getData(true),
        ]);
    }

    public function store(
        StoreRegistrationRequest $request,
        Event $event,
        RegisterForEvent $registerForEvent,
        AuditLogger $audit,
    ): JsonResponse {
        $registration = $registerForEvent->handle($event, $request->user(), $request->validated());

        $audit->record(
            'registration.created',
            user: $request->user(),
            entityType: Registration::class,
            entityId: $registration->id,
            metadata: ['event_id' => $event->id],
            request: $request,
        );

        return response()->json([
            'message' => 'You are registered.',
            'data' => (new RegistrationResource($registration))->resolve(),
        ], 201);
    }

    public function destroy(
        Request $request,
        Registration $registration,
        CancelRegistration $cancelRegistration,
        AuditLogger $audit,
    ): JsonResponse {
        Gate::authorize('cancel', $registration);

        $registration = $cancelRegistration->handle($registration);

        $audit->record(
            'registration.cancelled',
            user: $request->user(),
            entityType: Registration::class,
            entityId: $registration->id,
            metadata: ['event_id' => $registration->event_id],
            request: $request,
        );

        return response()->json([
            'message' => 'Registration cancelled.',
            'data' => (new RegistrationResource($registration->load([
                'event' => fn ($query) => $query->withRegisteredCount(),
            ])))->resolve(),
        ]);
    }
}
