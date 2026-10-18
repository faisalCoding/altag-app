<?php

use App\Models\Role;
use App\Models\Screen;
use Illuminate\Database\Migrations\Migration;

/**
 * الإقرارات: the supervisor writes and follows them up; the teacher sends the
 * links of their circle's students — each a screen of its own.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{route: string, group: string, label: string}>
     */
    private array $screens = [
        'supervisor' => ['route' => 'supervisor.agreements', 'group' => 'العملية التعليمية', 'label' => 'الإقرارات'],
        'teacher' => ['route' => 'teacher.agreements', 'group' => 'عام', 'label' => 'إقرارات الطلاب'],
    ];

    public function up(): void
    {
        foreach ($this->screens as $roleKey => $screen) {
            $role = Role::where('key', $roleKey)->first();

            if (! $role || Screen::where('route_name', $screen['route'])->exists()) {
                continue;
            }

            Screen::create([
                'owner_role_id' => $role->id,
                'group_label' => $screen['group'],
                'route_name' => $screen['route'],
                'label' => $screen['label'],
                'sort_order' => Screen::where('owner_role_id', $role->id)->max('sort_order') + 1,
                'is_protected' => false,
            ])->permissions()->create(['role_id' => $role->id]);
        }
    }

    public function down(): void
    {
        Screen::whereIn('route_name', array_column($this->screens, 'route'))->delete();
    }
};
