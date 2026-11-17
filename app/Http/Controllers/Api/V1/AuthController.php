<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function register(
        RegisterRequest $request,
        TokenService $tokens,
        AuditLogger $audit,
    ): JsonResponse {
        $user = User::create([
            ...$request->validated(),
            'role' => Role::Student,
        ]);

        $audit->record(
            'auth.signup',
            user: $user,
            entityType: User::class,
            entityId: $user->id,
            metadata: ['role' => $user->role->value],
            request: $request,
        );

        return response()->json([
            'token' => $tokens->issue($user, $request->userAgent()),
            'user' => (new UserResource($user))->resolve(),
        ], 201);
    }

    public function login(
        LoginRequest $request,
        TokenService $tokens,
        AuditLogger $audit,
    ): JsonResponse {
        $credentials = $request->validated();

        $user = User::query()
            ->where('email', $credentials['login'])
            ->orWhere('student_id', $credentials['login'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $identifier = str_contains($credentials['login'], '@')
                ? $audit->maskEmail($credentials['login'])
                : $audit->maskIdentifier($credentials['login']);

            $audit->record(
                'auth.login',
                'failed',
                metadata: ['identifier' => $identifier],
                request: $request,
            );

            throw ValidationException::withMessages([
                'login' => 'These credentials do not match our records.',
            ]);
        }

        $audit->record('auth.login', user: $user, request: $request);

        return response()->json([
            'token' => $tokens->issue($user, $request->userAgent()),
            'user' => (new UserResource($user))->resolve(),
        ]);
    }

    public function logout(Request $request, AuditLogger $audit): Response
    {
        $audit->record('auth.logout', user: $request->user(), request: $request);

        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function forgotPassword(ForgotPasswordRequest $request, AuditLogger $audit): JsonResponse
    {
        $email = $request->validated('email');
        $user = User::query()->where('email', $email)->first();

        // Always answer the same way so the endpoint cannot be used to probe emails.
        Password::sendResetLink($request->only('email'));

        $audit->record(
            'auth.password_reset_requested',
            user: $user,
            entityType: $user ? User::class : null,
            entityId: $user?->id,
            metadata: ['identifier' => $audit->maskEmail($email)],
            request: $request,
        );

        return response()->json([
            'message' => 'If that email is registered, a reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request, AuditLogger $audit): JsonResponse
    {
        $resetUser = null;

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use (&$resetUser) {
                $resetUser = $user;
                $user->forceFill(['password' => $password])->save();
                // A reset means the old sessions may be compromised.
                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PasswordReset) {
            $audit->record(
                'auth.password_reset_completed',
                'failed',
                metadata: ['identifier' => $audit->maskEmail($request->string('email')->toString())],
                request: $request,
            );

            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        $audit->record(
            'auth.password_reset_completed',
            user: $resetUser,
            entityType: User::class,
            entityId: $resetUser?->id,
            request: $request,
        );

        return response()->json(['message' => 'Password reset.']);
    }
}
