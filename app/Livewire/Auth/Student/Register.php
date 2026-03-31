<?php

namespace App\Livewire\Auth\Student;

use App\Models\Student;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

class Register extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register()
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:students'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = Student::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);

        event(new Registered($user));

        Auth::guard('student')->login($user);

        return redirect()->route('student.dashboard');
    }

    public function render()
    {
        return view('livewire.auth.student.register')
            ->layout('layouts.auth', ['title' => 'إنشاء حساب - طالب']);
    }
}
