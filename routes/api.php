<?php

use App\Http\Controllers\Api\V1\AdminAiController;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::get('auth/sessions', [SessionController::class, 'index'])->middleware('throttle:30,1');
        Route::delete('auth/sessions/{id}', [SessionController::class, 'destroy'])
            ->whereNumber('id')
            ->middleware('throttle:30,1');
        Route::put('profile', [ProfileController::class, 'update']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);

        Route::get('events', [EventController::class, 'index']);
        Route::get('events/{event}', [EventController::class, 'show']);

        // Both roles may ask the assistant; the per-user AI limiter lives in AiGateway.
        Route::post('ai/assistant', [AssistantController::class, 'ask']);

        Route::middleware('role:student')->group(function () {
            Route::get('my/registrations', [RegistrationController::class, 'index']);
            Route::post('events/{event}/registrations', [RegistrationController::class, 'store']);
            Route::delete('registrations/{registration}', [RegistrationController::class, 'destroy']);
        });

        Route::middleware('role:admin')->group(function () {
            Route::post('events', [EventController::class, 'store']);
            Route::put('events/{event}', [EventController::class, 'update']);
            Route::delete('events/{event}', [EventController::class, 'destroy']);
            Route::get('events/{event}/registrations', [EventController::class, 'attendees']);

            Route::get('admin/audit-logs', [AuditLogController::class, 'index'])
                ->middleware('throttle:60,1');
            Route::post('admin/audit-logs/verify', [AuditLogController::class, 'verify'])
                ->middleware('throttle:10,1');

            Route::get('admin/users', [AdminUserController::class, 'index'])
                ->middleware('throttle:60,1');
            Route::delete('admin/users/{user}/tokens', [AdminUserController::class, 'revokeTokens'])
                ->middleware('throttle:10,1');

            Route::get('admin/ai/settings', [AdminAiController::class, 'settings'])
                ->middleware('throttle:60,1');
            Route::put('admin/ai/settings', [AdminAiController::class, 'updateSettings'])
                ->middleware('throttle:10,1');
            Route::get('admin/ai/usage', [AdminAiController::class, 'usage'])
                ->middleware('throttle:60,1');
            Route::post('admin/ai/analytics-summary', [AdminAiController::class, 'summary']);
            Route::post('admin/ai/test', [AdminAiController::class, 'test'])
                ->middleware('throttle:10,1');

            Route::prefix('admin/analytics')->group(function () {
                Route::get('summary', [AnalyticsController::class, 'summary']);
                Route::get('registrations-over-time', [AnalyticsController::class, 'registrationsOverTime']);
                Route::get('events-performance', [AnalyticsController::class, 'eventsPerformance']);
                Route::get('top-events', [AnalyticsController::class, 'topEvents']);
                Route::get('by-year-section', [AnalyticsController::class, 'byYearSection']);
                Route::get('insights', [AnalyticsController::class, 'insights']);
            });
        });
    });
});
