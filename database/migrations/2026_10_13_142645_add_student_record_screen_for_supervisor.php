<?php

use App\Models\Role;
use App\Models\Screen;
use Illuminate\Database\Migrations\Migration;

/**
 * سجل الطلاب: every student of the supervisor's circles day by day, a screen
 * of the supervisor's own.
 */
return new class extends Migration
{
    private const ROUTE = 'supervisor.student-record';

    public function up(): void
    {
        $role = Role::where('key', 'supervisor')->first();

        if (! $role || Screen::where('route_name', self::ROUTE)->exists()) {
            return;
        }

        Screen::create([
            'owner_role_id' => $role->id,
            'group_label' => 'المتابعة والتقارير',
            'route_name' => self::ROUTE,
            'label' => 'سجل الطلاب',
            'sort_order' => Screen::where('owner_role_id', $role->id)->max('sort_order') + 1,
            'is_protected' => false,
        ])->permissions()->create(['role_id' => $role->id]);
    }

    public function down(): void
    {
        Screen::where('route_name', self::ROUTE)->delete();
    }
};
