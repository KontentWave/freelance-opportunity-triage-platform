<?php

namespace App\Http\Middleware;

use App\Application\Review\DemoReviewConfiguration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureDemoMode
{
    public function __construct(private readonly DemoReviewConfiguration $demo) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('demo', 'demo-session')) {
            $this->demo->ensureEnabled();
        }

        return $next($request);
    }
}
