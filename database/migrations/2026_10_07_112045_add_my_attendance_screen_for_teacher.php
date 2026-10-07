<?php

use App\Models\Role;
use App\Models\Screen;
use Illuminate\Database\Migrations\Migration;

/**
 * The teacher's own roll-call record — what their supervisor marked for them.
 */
return new class extends Migration
{
    private const ROUTE = 'teacher.my-attendance';

    public function up(): void
    {
        $role = Role::where('key', 'teacher')->first();

        if (! $role || Screen::where('route_name', self::ROUTE)->exists()) {
            return;
        }

        Screen::create([
            'owner_role_id' => $role->id,
            'group_label' => 'التحضير',
            'route_name' => self::ROUTE,
            'label' => 'سجل حضوري',
            'sort_order' => Screen::where('owner_role_id', $role->id)->max('sort_order') + 1,
            'is_protected' => false,
        ])->permissions()->create(['role_id' => $role->id]);
    }

    public function down(): void
    {
        Screen::where('route_name', self::ROUTE)->delete();
    }
};
