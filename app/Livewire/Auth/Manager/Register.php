<?php

namespace App\Livewire\Auth\Manager;

use App\Models\Manager;
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
            'email' => ['required', 'string', 'email', 'max:255', 'unique:managers'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = Manager::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);

        event(new Registered($user));

        Auth::guard('manager')->login($user);

        return redirect()->route('manager.dashboard');
    }

    public function render()
    {
        return view('livewire.auth.manager.register')
            ->layout('layouts.auth', ['title' => 'إنشاء حساب - مدير']);
    }
}
