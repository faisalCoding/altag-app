<?php

use App\Models\Role;
use App\Models\Screen;
use Illuminate\Database\Migrations\Migration;

/**
 * تقارير الحضور والغياب for the supervisor: the manager's report over the
 * stages they oversee, a screen of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = Role::where('key', 'supervisor')->first();

        if (! $role || Screen::where('route_name', 'supervisor.attendance-reports')->exists()) {
            return;
        }

        Screen::create([
            'owner_role_id' => $role->id,
            'group_label' => 'المتابعة والتقارير',
            'route_name' => 'supervisor.attendance-reports',
            'label' => 'تقارير الحضور والغياب',
            'sort_order' => Screen::where('owner_role_id', $role->id)->max('sort_order') + 1,
            'is_protected' => false,
        ])->permissions()->create(['role_id' => $role->id]);
    }

    public function down(): void
    {
        Screen::where('route_name', 'supervisor.attendance-reports')->delete();
    }
};
