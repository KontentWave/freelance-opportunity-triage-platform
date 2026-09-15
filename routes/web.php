<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\OpportunityEvaluationController;
use App\Http\Controllers\OpportunityReviewController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

RateLimiter::for('login', function (Request $request): Limit {
    return Limit::perMinute(5)->by(Str::transliterate(
        Str::lower($request->string('email')->toString()).'|'.$request->ip(),
    ));
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login');
});

Route::middleware(['auth', 'review.workspace'])->group(function (): void {
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    Route::get('/opportunities', [OpportunityReviewController::class, 'index'])->name('opportunities.index');
    Route::get('/opportunities/{opportunity}', [OpportunityReviewController::class, 'show'])->name('opportunities.show');
    Route::get('/review/v1/opportunities', [OpportunityReviewController::class, 'list'])->name('review.opportunities.index');
    Route::get('/review/v1/opportunities/{opportunity}', [OpportunityReviewController::class, 'detail'])->name('review.opportunities.show');
    Route::post('/review/v1/opportunities/{opportunity}/evaluations', [OpportunityEvaluationController::class, 'store'])
        ->name('review.opportunities.evaluations.store');
});

Route::redirect('/', '/opportunities');
