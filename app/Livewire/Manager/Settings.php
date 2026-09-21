<?php

namespace App\Livewire\Manager;

use App\Models\Setting;
use App\Support\Branding;
use Flux\Flux;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithFileUploads;

class Settings extends Component
{
    use WithFileUploads;

    public $absenceLimit;

    public $latenessLimit;

    public $calculationPeriodDays;

    public $uploadedBackup;

    // ── هوية المجمع ─────────────────────────────────────────────────────────
    public string $primaryColor = Branding::DEFAULT_COLOR;

    public $uploadedLogo;

    public function mount()
    {
        $this->absenceLimit = Setting::getVal('absence_limit', 3);
        $this->latenessLimit = Setting::getVal('lateness_limit', 5);
        $this->calculationPeriodDays = Setting::getVal('calculation_period_days', 30);
        $this->primaryColor = Branding::color();
    }

    /**
     * The colour every «maroon» in the interface resolves to.
     */
    public function saveColor(): void
    {
        $this->validate([
            'primaryColor' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/',
        ], [
            'primaryColor.regex' => 'اللون يكون بصيغة #RRGGBB، مثل #7a2727.',
        ]);

        Branding::setColor($this->primaryColor);
        $this->primaryColor = Branding::color();

        // Reloaded rather than re-rendered: the colour lives in a style tag in
        // the document head, which a Livewire update never touches.
        Flux::toast('حُفظ اللون.', variant: 'success');
        $this->js('setTimeout(() => window.location.reload(), 600)');
    }

    public function saveLogo(): void
    {
        $this->validate([
            'uploadedLogo' => 'required|image|mimes:png,jpg,jpeg,webp,svg|max:1024',
        ], [
            'uploadedLogo.required' => 'اختر صورة الشعار.',
            'uploadedLogo.image' => 'الملف ليس صورة.',
            'uploadedLogo.max' => 'حجم الشعار لا يتجاوز ميغابايت واحداً.',
        ]);

        $path = $this->uploadedLogo->store('branding', 'public');

        Branding::setLogoPath($path);
        $this->uploadedLogo = null;

        Flux::toast('حُفظ الشعار.', variant: 'success');
        $this->js('setTimeout(() => window.location.reload(), 600)');
    }

    public function resetBranding(): void
    {
        Branding::setColor(Branding::DEFAULT_COLOR);
        Branding::setLogoPath(null);
        $this->primaryColor = Branding::DEFAULT_COLOR;
        $this->uploadedLogo = null;

        Flux::toast('أُعيدت الهوية إلى الأصل.', variant: 'success');
        $this->js('setTimeout(() => window.location.reload(), 600)');
    }

    public function save()
    {
        $this->validate([
            'absenceLimit' => 'required|integer|min:1',
            'latenessLimit' => 'required|integer|min:1',
            'calculationPeriodDays' => 'required|integer|min:1',
        ]);

        Setting::setVal('absence_limit', $this->absenceLimit);
        Setting::setVal('lateness_limit', $this->latenessLimit);
        Setting::setVal('calculation_period_days', $this->calculationPeriodDays);

        Flux::toast('تم حفظ الإعدادات بنجاح', variant: 'success');
    }

    public function saveBackupToServer()
    {
        $dbPath = config('database.connections.sqlite.database');
        if (! file_exists($dbPath)) {
            Flux::toast('ملف قاعدة البيانات غير موجود.', variant: 'danger');

            return;
        }

        $filename = 'manual_'.now()->format('Y-m-d_H-i-s').'.sqlite';
        $backupDir = storage_path('app/backups');

        if (! file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        copy($dbPath, $backupDir.'/'.$filename);
        Flux::toast('تم حفظ النسخة الاحتياطية على الخادم بنجاح.', variant: 'success');
    }

    public function uploadBackup()
    {
        if (! $this->uploadedBackup) {
            Flux::toast('لم يتم استلام أي ملف بعد. يرجى الانتظار قليلاً بعد اختيار الملف.', variant: 'danger');

            return;
        }
        $this->validate([
            'uploadedBackup' => 'required|file',
        ]);

        $filename = $this->uploadedBackup->getClientOriginalName();
        if (! str_ends_with($filename, '.sqlite') && ! str_ends_with($filename, '.db')) {

            Flux::toast('يجب أن يكون الملف بصيغة sqlite أو db.', variant: 'danger');

            return;
        }

        $newFilename = 'uploaded_'.now()->format('Y-m-d_H-i-s').'.sqlite';
        $backupDir = storage_path('app/backups');
        if (! file_exists($backupDir) && ! str_ends_with($backupDir, '/')) {
            $backupDir .= '/';
        }
        if (! file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        copy($this->uploadedBackup->getRealPath(), $backupDir.'/'.$newFilename);

        $this->uploadedBackup = null;
        Flux::toast('تم رفع النسخة الاحتياطية بنجاح.', variant: 'success');
    }

    public function deleteBackup($filename)
    {
        $path = storage_path('app/backups/'.$filename);
        if (file_exists($path)) {
            unlink($path);
            Flux::toast('تم حذف النسخة الاحتياطية.', variant: 'success');
        } else {
            Flux::toast('الملف غير موجود.', variant: 'danger');
        }
    }

    public function restoreFullBackup($filename)
    {
        $backupPath = storage_path('app/backups/'.$filename);
        if (! file_exists($backupPath)) {
            Flux::toast('ملف النسخة الاحتياطية غير موجود.', variant: 'danger');

            return;
        }

        $dbPath = config('database.connections.sqlite.database');

        // Create a safety backup of current state before replacing
        $safetyFilename = 'safety_pre_restore_'.now()->format('Y-m-d_H-i-s').'.sqlite';
        $backupDir = storage_path('app/backups');
        copy($dbPath, $backupDir.'/'.$safetyFilename);

        // Replace the current database with the selected backup
        copy($backupPath, $dbPath);

        // Re-run migrations one by one to safely apply any missing updates
        // If a migration fails (e.g., table already exists), we catch the error, mark it as completed, and continue
        $files = File::files(database_path('migrations'));
        $ran = DB::table('migrations')->pluck('migration')->toArray();
        $batch = DB::table('migrations')->max('batch') + 1;

        $pending = [];
        foreach ($files as $file) {
            $name = str_replace('.php', '', $file->getFilename());
            if (! in_array($name, $ran)) {
                $pending[] = $name;
            }
        }

        $conflictOccurred = false;
        foreach ($pending as $migration) {
            try {
                Artisan::call('migrate', [
                    '--path' => 'database/migrations/'.$migration.'.php',
                    '--force' => true,
                ]);
            } catch (\Exception $e) {
                $conflictOccurred = true;
                // Mark as run to skip it in the future
                DB::table('migrations')->insert([
                    'migration' => $migration,
                    'batch' => $batch,
                ]);
                Log::warning("Skipped migration due to error (assumed already applied): {$migration}. Error: ".$e->getMessage());
            }
        }

        if ($conflictOccurred) {
            Flux::toast('تم الاسترجاع وتم قفز بعض الجداول الموجودة مسبقاً بنجاح.', variant: 'success');
        } else {
            Flux::toast('تم استرجاع النسخة وتحديث هيكل البيانات بنجاح.', variant: 'success');
        }

        // Force a page reload to refresh all data connections
        $this->js('setTimeout(() => window.location.reload(), 1500)');
    }

    public function render()
    {
        $scheduledBackups = [];
        $manualBackups = [];
        $uploadedBackups = [];

        $backupDir = storage_path('app/backups');
        if (file_exists($backupDir)) {
            $files = File::files($backupDir);
            foreach ($files as $file) {
                if ($file->getExtension() === 'sqlite') {
                    $item = [
                        'name' => $file->getFilename(),
                        'size' => round($file->getSize() / 2048 / 2048, 2).' MB',
                        'time' => date('Y-m-d H:i:s', $file->getMTime()),
                    ];

                    if (str_starts_with($item['name'], 'scheduled_')) {
                        $scheduledBackups[] = $item;
                    } elseif (str_starts_with($item['name'], 'uploaded_')) {
                        $uploadedBackups[] = $item;
                    } else {
                        $manualBackups[] = $item;
                    }
                }
            }
        }

        $sortFn = function ($a, $b) {
            return $b['time'] <=> $a['time'];
        };

        usort($scheduledBackups, $sortFn);
        usort($manualBackups, $sortFn);
        usort($uploadedBackups, $sortFn);

        return view('livewire.manager.settings', [
            'scheduledBackups' => $scheduledBackups,
            'manualBackups' => $manualBackups,
            'uploadedBackups' => $uploadedBackups,
            'logoUrl' => Branding::logoUrl(),
            'hasCustomLogo' => Branding::hasCustomLogo(),
            'defaultColor' => Branding::DEFAULT_COLOR,
        ]);
    }
}
