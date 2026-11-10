<?php

use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->validateCsrfTokens(except: ['api/v1/webhooks/*/receive']);
        $middleware->alias(['active' => EnsureActiveUser::class, 'permission' => PermissionMiddleware::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['token', 'current_password', 'password', 'password_confirmation']);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->respond(function (Response $response): Response {
            if (request()->is('api/*') && $response->getStatusCode() >= 400) {
                $body = json_decode($response->getContent(), true) ?? [];
                $status = $response->getStatusCode();
                $message = match ($status) {
                    401 => 'Unauthenticated.', 403 => 'You do not have permission to perform this action.',
                    419 => 'Session expired. Refresh and try again.', 422 => 'Validation failed',
                    429 => 'Too many attempts. Please try again later.',
                    default => $status >= 500 ? 'An unexpected server error occurred.' : ($body['message'] ?? 'Request failed'),
                };
                $response->setContent(json_encode(['success' => false, 'message' => $message, 'errors' => (object) ($body['errors'] ?? [])]));
                $response->headers->set('Content-Type', 'application/json');
            }

            return $response;
        });
    })->create();
