<?php

use App\Models\Role;
use App\Models\Screen;
use Illuminate\Database\Migrations\Migration;

/**
 * The manager's roll call for the teachers — the only one that reaches the
 * teachers of a stage no supervisor covers, and those with no circle.
 */
return new class extends Migration
{
    private const ROUTE = 'manager.teacher-attendance';

    public function up(): void
    {
        $role = Role::where('key', 'manager')->first();

        if (! $role || Screen::where('route_name', self::ROUTE)->exists()) {
            return;
        }

        Screen::create([
            'owner_role_id' => $role->id,
            'group_label' => 'المستخدمين',
            'route_name' => self::ROUTE,
            'label' => 'تحضير المعلمين',
            'sort_order' => Screen::where('owner_role_id', $role->id)->max('sort_order') + 1,
            'is_protected' => false,
        ])->permissions()->create(['role_id' => $role->id]);
    }

    public function down(): void
    {
        Screen::where('route_name', self::ROUTE)->delete();
    }
};
