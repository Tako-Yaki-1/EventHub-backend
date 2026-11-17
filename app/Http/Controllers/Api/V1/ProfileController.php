<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->validated())->save();

        $changed = array_values(array_diff(array_keys($user->getChanges()), ['updated_at']));
        $passwordChanged = in_array('password', $changed, true);

        if ($passwordChanged) {
            // A new password should end every session, including this one.
            $user->tokens()->delete();
        }

        $audit->record(
            'profile.updated',
            user: $user,
            entityType: $user::class,
            entityId: $user->id,
            metadata: ['changed' => $changed, 'password_changed' => $passwordChanged],
            request: $request,
        );

        return response()->json([
            'message' => 'Profile updated.',
            'data' => (new UserResource($user))->resolve(),
        ]);
    }
}
