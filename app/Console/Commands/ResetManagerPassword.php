<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Models\Manager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\select;

class ResetManagerPassword extends Command
{
    use PasswordValidationRules;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manager:password
                            {manager? : البريد الإلكتروني أو الجوال أو الاسم — يُسأل عنه إن لم يُذكر}
                            {--password= : كلمة المرور الجديدة، للتشغيل غير التفاعلي (تُحفظ في سجل الأوامر، فتجنّبها ما أمكن)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'تعيين كلمة مرور جديدة لحساب مدير';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $manager = $this->resolveManager();

        if (! $manager) {
            return self::FAILURE;
        }

        $password = $this->option('password') ?? $this->askForPassword();

        if ($password === null) {
            $this->components->error('لم تتطابق كلمتا المرور. لم يتغيّر شيء.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $password],
            ['password' => $this->passwordRules()],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        // `password` is cast to `hashed`, so assigning the plain value hashes it.
        $manager->forceFill(['password' => $password])->save();

        $this->components->info("تم تغيير كلمة مرور «{$manager->name}» ({$manager->email}).");

        return self::SUCCESS;
    }

    /**
     * Find the manager to act on: the one named on the command line, the only one
     * there is, or whichever the operator picks from the list.
     */
    private function resolveManager(): ?Manager
    {
        $needle = $this->argument('manager');

        if ($needle !== null) {
            $matches = Manager::where('email', $needle)
                ->orWhere('phone', $needle)
                ->orWhere('name', $needle)
                ->get();

            if ($matches->isEmpty()) {
                $this->components->error("لا يوجد مدير بهذا المعرّف: {$needle}");

                return null;
            }

            if ($matches->count() > 1) {
                $this->components->error("أكثر من مدير يطابق «{$needle}». استخدم البريد الإلكتروني لتحديده.");

                return null;
            }

            return $matches->first();
        }

        $managers = Manager::orderBy('name')->get();

        if ($managers->isEmpty()) {
            $this->components->error('لا يوجد أي حساب مدير في النظام.');

            return null;
        }

        if ($managers->count() === 1) {
            $only = $managers->first();
            $this->components->info("المدير الوحيد: {$only->name} ({$only->email})");

            return $only;
        }

        $id = select(
            label: 'أي مدير؟',
            options: $managers->mapWithKeys(fn (Manager $m) => [$m->id => "{$m->name} — {$m->email}"])->all(),
        );

        return $managers->firstWhere('id', $id);
    }

    /**
     * Ask twice, without echoing. Returns null when the two do not match.
     */
    private function askForPassword(): ?string
    {
        $password = $this->secret('كلمة المرور الجديدة');

        if ($password !== $this->secret('أعد كتابة كلمة المرور')) {
            return null;
        }

        return $password;
    }
}
