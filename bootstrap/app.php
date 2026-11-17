<?php

use App\Http\Middleware\EnsureRole;
use App\Services\AuditLogger;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);

        // Laravel Cloud terminates HTTPS in front of the app: trust its proxy headers
        // so request()->ip() and generated URLs reflect the real client and scheme.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An audit trail matters most when someone is refused: record every 403.
        $exceptions->respond(function (Response $response, Throwable $exception) {
            if ($response->getStatusCode() === 403 && request()->user() !== null) {
                try {
                    app(AuditLogger::class)->record(
                        'access.denied',
                        'denied',
                        user: request()->user(),
                        metadata: [
                            'method' => request()->method(),
                            'path' => request()->path(),
                        ],
                        request: request(),
                    );
                } catch (Throwable) {
                    // Auditing must never replace the original response.
                }
            }

            return $response;
        });
    })->create();
