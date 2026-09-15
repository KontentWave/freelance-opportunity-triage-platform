<?php

use App\Domain\Triage\Enums\TriageErrorCode;
use App\Domain\Triage\Exceptions\TriageException;
use App\Http\Middleware\EnsureReviewWorkspace;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'review.workspace' => EnsureReviewWorkspace::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request): string => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
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
