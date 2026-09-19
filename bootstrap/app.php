<?php

use App\Domain\Triage\Enums\TriageErrorCode;
use App\Domain\Triage\Exceptions\TriageException;
use App\Http\Middleware\EnsureDemoMode;
use App\Http\Middleware\EnsureReviewWorkspace;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [EnsureDemoMode::class]);

        $middleware->alias([
            'review.workspace' => EnsureReviewWorkspace::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request): string => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->expectsJson() || ! $request->is('review/v1/*')) {
                return null;
            }

            return response()->json([
                'error_code' => 'review.validation_failed',
                'message' => 'The submitted data is invalid.',
                'errors' => $exception->errors(),
            ], 422);
        });
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->expectsJson() || ! $request->is('review/v1/*')) {
                return null;
            }

            $error = match ($exception->getStatusCode()) {
                409 => ['review.stale_context', 'The displayed review context changed. Reload before saving.'],
                413 => ['review.request_too_large', 'The request is too large.'],
                default => null,
            };

            return $error === null
                ? null
                : response()->json(['error_code' => $error[0], 'message' => $error[1]], $exception->getStatusCode());
        });
        $exceptions->render(function (TriageException $exception, Request $request) {
            $status = $exception->errorCode === TriageErrorCode::ProfileInvalid ? 503 : 404;
            $message = $status === 503
                ? 'The review profile is currently unavailable.'
                : 'The requested opportunity was not found.';

            return $request->expectsJson()
                ? response()->json(['error_code' => $exception->errorCode->value, 'message' => $message], $status)
                : response($message, $status);
        });
    })->create();
