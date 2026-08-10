<?php

use App\Models\Role;
use App\Models\Screen;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $supervisor = Role::where('key', 'supervisor')->first();

        if (! $supervisor) {
            return;
        }

        $screen = Screen::create([
            'owner_role_id' => $supervisor->id,
            'group_label' => 'المتابعة والتقارير',
            'route_name' => 'supervisor.custom-reports',
            'label' => 'تقارير مخصصة',
            'sort_order' => Screen::where('owner_role_id', $supervisor->id)->max('sort_order') + 1,
            'is_protected' => false,
        ]);

        $screen->permissions()->create(['role_id' => $supervisor->id]);
    }

    public function down(): void
    {
        Screen::where('route_name', 'supervisor.custom-reports')->delete();
    }
};
