<?php

use App\Models\Manager;
use App\Models\Role;
use App\Models\RoleScreenPermission;
use App\Models\Screen;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

it('has no «موظف» role left: no guard, no pages, no menu item', function () {
    expect(config('auth.guards.staff'))->toBeNull()
        ->and(config('auth.providers.staffs'))->toBeNull()
        ->and(Route::has('staff.dashboard'))->toBeFalse()
        ->and(Route::has('manager.staff-members'))->toBeFalse()
        ->and(class_exists('App\\Models\\Staff'))->toBeFalse()
        ->and(Screen::where('route_name', 'manager.staff-members')->exists())->toBeFalse();

    $this->actingAs(Manager::factory()->create(), 'manager');

    $this->get(route('manager.dashboard'))
        ->assertSuccessful()
        ->assertDontSee('إدارة الموظفين');

    $this->get('/staff/dashboard')->assertNotFound();
});

it('clears what a staff account left behind, and keeps the person', function () {
    $manager = Manager::factory()->create();
    $person = Manager::factory()->create();

    $customRole = Role::create(['key' => 'accountant', 'label' => 'محاسب', 'guard_name' => 'staff', 'is_system' => false, 'is_active' => true]);
    RoleScreenPermission::create(['role_id' => $customRole->id, 'screen_id' => Screen::value('id')]);
    DB::table('users')->where('id', $person->id)->update(['staff_role_id' => $customRole->id]);
    DB::table('user_roles')->insert(['user_id' => $person->id, 'role' => 'staff', 'is_approved' => true, 'created_at' => now(), 'updated_at' => now()]);

    $conversation = DB::table('conversations')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
    DB::table('conversation_participants')->insert([
        ['conversation_id' => $conversation, 'participant_type' => 'manager', 'participant_id' => $manager->id, 'created_at' => now(), 'updated_at' => now()],
        ['conversation_id' => $conversation, 'participant_type' => 'staff', 'participant_id' => $person->id, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('messages')->insert(['conversation_id' => $conversation, 'sender_type' => 'staff', 'sender_id' => $person->id, 'body' => 'مرحباً', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('app_notifications')->insert(['recipient_type' => 'staff', 'recipient_id' => $person->id, 'type' => 'general', 'title' => 'تنبيه', 'body' => 'نص', 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_08_124044_remove_staff_accounts.php'))->up();

    expect(DB::table('user_roles')->where('role', 'staff')->exists())->toBeFalse()
        ->and(Role::whereKey($customRole->id)->exists())->toBeFalse()
        ->and(RoleScreenPermission::where('role_id', $customRole->id)->exists())->toBeFalse()
        ->and(DB::table('conversations')->where('id', $conversation)->exists())->toBeFalse()
        ->and(DB::table('messages')->where('conversation_id', $conversation)->exists())->toBeFalse()
        ->and(DB::table('app_notifications')->where('recipient_type', 'staff')->exists())->toBeFalse()
        // The person keeps their row and their other role.
        ->and(DB::table('users')->where('id', $person->id)->value('staff_role_id'))->toBeNull()
        ->and(DB::table('user_roles')->where('user_id', $person->id)->where('role', 'manager')->exists())->toBeTrue();
});
