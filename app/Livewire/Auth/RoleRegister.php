<?php

namespace App\Livewire\Auth;

use App\Models\Guardian;
use App\Models\Manager;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

class RoleRegister extends Component
{
    public string $role = 'student';
    public string $roleName = '';
    
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $role, string $roleName)
    {
        $this->role = $role;
        $this->roleName = $roleName;
    }

    public function register()
    {
        $guard = $this->role === 'parent' ? 'guardian' : $this->role;
        $modelClass = match ($guard) {
            'manager' => Manager::class,
            'supervisor' => Supervisor::class,
            'teacher' => Teacher::class,
            'guardian' => Guardian::class,
            'student' => Student::class,
            default => throw new \Exception('Invalid role'),
        };

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:' . $modelClass],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = $modelClass::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);

        event(new Registered($user));

        Auth::guard($guard)->login($user);

        return redirect()->route($this->role . '.dashboard');
    }

    public function render()
    {
        return view('livewire.auth.role-register')
            ->layout('layouts.auth', ['title' => 'تسجيل حساب - ' . $this->roleName]);
    }
}
