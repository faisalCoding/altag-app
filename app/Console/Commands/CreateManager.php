<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Models\Manager;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The first manager of a new academy.
 *
 * Self-registration creates a student awaiting approval, and approving one is
 * itself a manager's job — so a freshly migrated database has nobody who can
 * sign in and no way to make somebody. This is that way.
 */
class CreateManager extends Command
{
    use PasswordValidationRules;

    protected $signature = 'manager:create
                            {name : اسم المدير}
                            {email : البريد الإلكتروني، وبه يسجّل الدخول}
                            {--password= : كلمة المرور. تُولَّد وتُعرض إن لم تُذكر (تُحفظ في سجل الأوامر، فتجنّبها ما أمكن)}';

    protected $description = 'إنشاء حساب مدير — لتهيئة مجمع جديد';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $email = Str::lower(trim((string) $this->argument('email')));

        // Across every role, not just managers: the column is unique on users, so
        // an address already held by a teacher would fail on insert rather than here.
        if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
            $this->components->error("البريد {$email} مستعمل بالفعل.");

            return self::FAILURE;
        }

        $generated = $this->option('password') === null;
        $password = $this->option('password') ?? Str::password(14, symbols: false);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $password],
            ['name' => 'required|string|max:255', 'email' => 'required|email', 'password' => $this->passwordRules()],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        // `password` is cast to `hashed`, so the plain value is hashed on save.
        $manager = Manager::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_approved' => true,
        ]);

        $this->components->info("أُنشئ المدير «{$manager->name}» ({$manager->email}).");

        if ($generated) {
            $this->newLine();
            $this->components->warn('كلمة المرور المولَّدة — تظهر مرة واحدة فقط:');
            $this->line('  '.$password);
            $this->newLine();
            $this->components->warn('غيّرها بعد أول دخول: php artisan manager:password');
        }

        return self::SUCCESS;
    }
}
