<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;

        $tokens = $request->user()->tokens()
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $tokens->map(fn (PersonalAccessToken $token) => [
                'id' => $token->getKey(),
                'name' => $token->name,
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'is_current' => $token->getKey() === $currentId,
            ])->values(),
        ]);
    }

    public function destroy(Request $request, int $id, AuditLogger $logger): Response
    {
        $token = $request->user()->tokens()->whereKey($id)->first();

        abort_if($token === null, 404);

        $logger->record('auth.session_revoked', user: $request->user(), metadata: [
            'token_id' => $token->getKey(),
            'name' => $token->name,
        ]);

        $token->delete();

        return response()->noContent();
    }
}
