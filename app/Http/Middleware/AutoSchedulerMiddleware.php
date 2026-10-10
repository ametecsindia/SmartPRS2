<?php

namespace App\Http\Middleware;

use App\Services\AutoScheduler;
use Closure;
use Illuminate\Http\Request;

/** Web traffic keeps the scheduler alive until an OS task takes over. See App\Services\AutoScheduler. */
class AutoSchedulerMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    /** Runs after the response is sent. */
    public function terminate(Request $request, $response): void
    {
        AutoScheduler::maybeTick();
    }
}
