<?php

use App\Models\Role;
use App\Models\Screen;
use Illuminate\Database\Migrations\Migration;

/**
 * الإقرارات for the manager: written for some stages or all of them, followed
 * up, and reported on — one screen, its follow-up under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = Role::where('key', 'manager')->first();

        if (! $role || Screen::where('route_name', 'manager.agreements')->exists()) {
            return;
        }

        Screen::create([
            'owner_role_id' => $role->id,
            'group_label' => 'إدارة النظام',
            'route_name' => 'manager.agreements',
            'label' => 'الإقرارات',
            'sort_order' => Screen::where('owner_role_id', $role->id)->max('sort_order') + 1,
            'is_protected' => false,
        ])->permissions()->create(['role_id' => $role->id]);
    }

    public function down(): void
    {
        Screen::where('route_name', 'manager.agreements')->delete();
    }
};
