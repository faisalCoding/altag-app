<?php

namespace App\Http\Middleware;

use App\Models\FormAssignment;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds the app shut for anyone who owes a survey marked as blocking, until
 * they answer it.
 *
 * A gate like this can lock people out of their own academy, so it is built
 * narrow on purpose:
 *
 *  - Only forms explicitly marked `is_blocking` count. Publishing an ordinary
 *    survey never shuts anything.
 *  - A form past its due date stops blocking. A survey nobody remembered to
 *    close cannot hold the academy shut forever.
 *  - Managers are asked and notified like everyone else but are never blocked.
 *    They are who fixes a survey that turns out to be unanswerable, and a gate
 *    that can trap its own keyholder is a gate nobody can open.
 *  - Logging out, the survey itself, and the approval waiting room are always
 *    reachable, or answering would be impossible from behind the gate.
 */
class RequirePendingSurveys
{
    /**
     * Routes that must stay open, or the gate would have no way through it.
     *
     * @var list<string>
     */
    private const ALWAYS_OPEN = [
        'logout',
        'login',
        'pending-approval',
        'forms.submit',
        'forms.report',
        'surveys.required',
        'switch-role',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        if ($routeName !== null && in_array($routeName, self::ALWAYS_OPEN, true)) {
            return $next($request);
        }

        // Livewire's own endpoint carries the survey's own updates; closing it
        // would freeze the form the gate is demanding.
        if ($request->is('livewire/*')) {
            return $next($request);
        }

        [$user, $guard] = self::currentUser($request);

        if (! $user || $guard === 'manager') {
            return $next($request);
        }

        $owed = FormAssignment::owedBy($user->id)
            ->with('form')
            ->get()
            ->first(fn (FormAssignment $a) => $a->form?->blocksTheApp());

        if (! $owed) {
            return $next($request);
        }

        return redirect()->route('surveys.required');
    }

    /**
     * @return array{0: User|null, 1: string|null}
     */
    private static function currentUser(Request $request): array
    {
        foreach (['manager', 'supervisor', 'teacher', 'student', 'guardian', 'staff'] as $guard) {
            if ($user = $request->user($guard)) {
                return [$user, $guard];
            }
        }

        return [null, null];
    }
}
