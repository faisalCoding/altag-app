<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The «موظف» role is gone. It never reached past its own dashboard and the
 * messages: the pages a manager granted its custom roles were listed as
 * «قريباً» and never opened. Its accounts lose the role, the custom roles
 * built for it go with their grants, and what was addressed to it — the
 * conversations, notifications and surveys — goes too, since nobody is left
 * to read it. The people's user rows stay: their other roles, if any, are
 * untouched. The users.staff_role_id column is left in place, unused, rather
 * than rebuilding the users table on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conversationIds = DB::table('conversation_participants')
            ->where('participant_type', 'staff')
            ->pluck('conversation_id');

        DB::table('messages')->whereIn('conversation_id', $conversationIds)->delete();
        DB::table('conversation_participants')->whereIn('conversation_id', $conversationIds)->delete();
        DB::table('conversations')->whereIn('id', $conversationIds)->delete();

        DB::table('app_notifications')->where('recipient_type', 'staff')->delete();
        DB::table('form_assignments')->where('role', 'staff')->delete();

        DB::table('user_roles')->where('role', 'staff')->delete();

        $customRoleIds = DB::table('roles')->where('guard_name', 'staff')->pluck('id');
        DB::table('users')->whereIn('staff_role_id', $customRoleIds)->update(['staff_role_id' => null]);
        DB::table('role_screen_permissions')->whereIn('role_id', $customRoleIds)->delete();
        DB::table('roles')->whereIn('id', $customRoleIds)->delete();

        DB::table('screens')->where('route_name', 'manager.staff-members')->delete();
    }

    public function down(): void
    {
        // Not reversible: the role and its code are gone.
    }
};
