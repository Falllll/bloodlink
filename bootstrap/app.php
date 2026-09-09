<?php

use App\Shared\Errors\ErrorCode;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AssignTraceId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException as SymfonyNotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            Route::middleware([])->group(base_path('routes/health.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [AssignTraceId::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                ErrorCode::VALIDATION_FAILED,
                'Validation failed.',
                $e->errors(),
                422,
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                ErrorCode::UNAUTHENTICATED,
                'Unauthenticated.',
                [],
                401,
            );
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                ErrorCode::FORBIDDEN,
                'Forbidden.',
                [],
                403,
            );
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                ErrorCode::NOT_FOUND,
                'Resource not found.',
                [],
                404,
            );
        });

        $exceptions->render(function (SymfonyNotFoundHttpException $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                ErrorCode::NOT_FOUND,
                'Resource not found.',
                [],
                404,
            );
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof \App\Shared\Exceptions\DomainException) {
                return ApiResponse::error(
                    $e->errorCode(),
                    $e->getMessage() ?: 'Request failed.',
                    [],
                    $e->httpStatus(),
                );
            }

            if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
                return ApiResponse::error(
                    ErrorCode::FORBIDDEN,
                    'Forbidden.',
                    [],
                    403,
                );
            }

            if ($e instanceof ModelNotFoundException || $e instanceof SymfonyNotFoundHttpException) {
                return ApiResponse::error(
                    ErrorCode::NOT_FOUND,
                    'Resource not found.',
                    [],
                    404,
                );
            }

            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500){
                $status = $e->getStatusCode();

                return ApiResponse::error(
                    match ($status) {
                        405 => ErrorCode::METHOD_NOT_ALLOWED,
                        429 => ErrorCode::TOO_MANY_REQUESTS,
                        default => ErrorCode::HTTP_ERROR,
                    },
                    $e->getMessage() ?: 'Request failed.',
                    [],
                    $status,
                );
            }

            if (config('app.debug')) {
                return null;
            }

            return ApiResponse::error(
                ErrorCode::INTERNAL_ERROR,
                'Internal server error.',
                [],
                500,
            );
        });
    })->create();
