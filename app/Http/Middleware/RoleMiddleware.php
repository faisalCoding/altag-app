<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! empty($roles)) {
            $hasRole = false;
            foreach ($roles as $role) {
                $guard = $role === 'parent' ? 'guardian' : $role;
                if ($user instanceof ("App\\Models\\" . ucfirst($guard))) {
                    $hasRole = true;
                    break;
                }
            }

            if (!$hasRole) {
                return redirect()->route('dashboard');
            }
        }

        return $next($request);
    }
}
