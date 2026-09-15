<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureReviewWorkspace
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->workspace_id === null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error_code' => 'review.workspace_unavailable',
                    'message' => 'This account is not assigned to a review workspace.',
                ], 403);
            }

            abort(403, 'This account is not assigned to a review workspace.');
        }

        return $next($request);
    }
}
