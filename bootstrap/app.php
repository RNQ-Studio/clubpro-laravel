<?php

use App\Http\Middleware\CheckMaintenance;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\TrackUserActivity;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);
        $middleware->alias([
            'check.maintenance' => CheckMaintenance::class,
            'track.activity' => TrackUserActivity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error(
                    message: $e->getMessage(),
                    status: 422,
                    errors: $e->errors(),
                    code: 'VALIDATION_ERROR',
                ),
                $e instanceof AuthenticationException => ApiResponse::error(
                    message: 'Unauthenticated.',
                    status: 401,
                    code: 'UNAUTHENTICATED',
                ),
                $e instanceof AuthorizationException => ApiResponse::error(
                    message: $e->getMessage() ?: 'This action is unauthorized.',
                    status: 403,
                    code: 'FORBIDDEN',
                ),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error(
                    message: 'Resource not found.',
                    status: 404,
                    code: 'NOT_FOUND',
                ),
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    message: $e->getMessage() ?: 'HTTP error.',
                    status: $e->getStatusCode(),
                    code: 'HTTP_ERROR',
                ),
                default => ApiResponse::error(
                    message: config('app.debug') ? $e->getMessage() : 'Server error.',
                    status: 500,
                    code: 'SERVER_ERROR',
                ),
            };
        });
    })->create();
