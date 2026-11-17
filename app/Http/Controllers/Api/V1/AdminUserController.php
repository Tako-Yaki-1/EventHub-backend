<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminUserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->select(['id', 'name', 'email', 'student_id', 'year_section', 'role'])
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query
                ->where(fn ($query) => $query
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")))
            ->withCount(['tokens as active_sessions_count' => fn ($query) => $query
                ->where(fn ($query) => $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now()))])
            ->withMax('tokens as last_activity_at', 'last_used_at')
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20);

        return AdminUserResource::collection($users);
    }

    public function revokeTokens(Request $request, User $user, AuditLogger $logger): JsonResponse
    {
        $revoked = $user->tokens()->count();
        $user->tokens()->delete();

        $logger->record(
            'admin.force_logout',
            user: $request->user(),
            entityType: User::class,
            entityId: $user->id,
            metadata: ['revoked' => $revoked],
        );

        return response()->json([
            'message' => 'Sessions revoked.',
            'revoked' => $revoked,
        ]);
    }
}
