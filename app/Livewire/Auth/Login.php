<?php

namespace App\Livewire\Auth;

use App\Models\Guardian;
use App\Models\Manager;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    /**
     * The model each guard's credentials live in — checked in order to find
     * which one owns the submitted email, so we only ever call attempt()
     * once instead of once per guard. Laravel pads every attempt() call to
     * a fixed 200ms floor to defeat login timing attacks; trying all 5
     * guards in sequence used to multiply that into up to a full second.
     */
    protected const GUARD_MODELS = [
        'student' => Student::class,
        'teacher' => Teacher::class,
        'guardian' => Guardian::class,
        'supervisor' => Supervisor::class,
        'manager' => Manager::class,
        'staff' => Staff::class,
    ];

    /**
     * URL prefix each guard's protected pages live under — used to decide
     * whether a stored "intended" URL actually belongs to the role that just
     * logged in, before honoring it instead of the plain dashboard redirect.
     */
    protected const GUARD_PREFIXES = [
        'student' => '/student',
        'teacher' => '/teacher',
        'guardian' => '/parent',
        'supervisor' => '/supervisor',
        'manager' => '/manager',
        'staff' => '/staff',
    ];

    public function login()
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Five wrong passwords a minute for one email from one address, then
        // a wait — enough for a forgetful user, too few to guess with.
        $throttleKey = Str::transliterate(Str::lower($this->email).'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => __('محاولات دخول كثيرة. أعد المحاولة بعد :seconds ثانية.', [
                    'seconds' => RateLimiter::availableIn($throttleKey),
                ]),
            ]);
        }

        // Wrapped in our own timebox so an email that matches no guard at
        // all still takes the same ~200ms as a real account with a wrong
        // password — otherwise the response time itself would leak whether
        // an email is registered.
        $guard = (new Timebox)->call(function ($timebox) {
            $guard = $this->resolveGuardForEmail($this->email);

            if ($guard && Auth::guard($guard)->attempt([
                'email' => $this->email,
                'password' => $this->password,
            ], $this->remember)) {
                $timebox->returnEarly();

                return $guard;
            }

            return null;
        }, 200_000);

        if ($guard) {
            RateLimiter::clear($throttleKey);
            session()->regenerate();

            if ($intended = $this->intendedUrlFor($guard)) {
                return redirect()->to($intended);
            }

            return redirect()->route("{$guard}.dashboard");
        }

        RateLimiter::hit($throttleKey);

        throw ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
    }

    /**
     * Cheap indexed lookup (no password hashing involved) to find which
     * guard's table the email belongs to, if any.
     */
    protected function resolveGuardForEmail(string $email): ?string
    {
        foreach (self::GUARD_MODELS as $guard => $model) {
            if ($model::where('email', $email)->exists()) {
                return $guard;
            }
        }

        return null;
    }

    /**
     * Only honor a stored intended URL when it points somewhere under the
     * authenticated guard's own section and isn't a login page itself —
     * otherwise a stale or cross-role intended URL could bounce the user
     * straight back to a login screen or into a page they can't access.
     */
    protected function intendedUrlFor(string $guard): ?string
    {
        $intended = session()->pull('url.intended');

        if (! $intended) {
            return null;
        }

        $path = parse_url($intended, PHP_URL_PATH) ?? '';
        $prefix = self::GUARD_PREFIXES[$guard];

        if (! str_starts_with($path, $prefix.'/') || str_ends_with($path, '/login')) {
            return null;
        }

        return $intended;
    }

    public function render()
    {
        return view('livewire.auth.login-unified');
    }
}
