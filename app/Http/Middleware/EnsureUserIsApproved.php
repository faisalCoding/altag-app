<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsApproved
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Try to get the user from any of our specific guards
        $user = $request->user('manager')
             ?? $request->user('supervisor')
             ?? $request->user('teacher')
             ?? $request->user('student')
             ?? $request->user('guardian');

        if (! $user) {
            return $next($request);
        }

        if (! $user->is_approved) {
            return redirect()->route('pending-approval');
        }

        return $next($request);
    }
}
