<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time, idempotent, additive migration of the 6 legacy role tables
 * (managers, supervisors, teachers, students, guardians, staffs) into the
 * new `users` + `user_roles` tables. Never touches the legacy tables — the
 * app keeps reading/writing them unchanged until a later cutover phase.
 *
 * Re-running is safe: `id_migration_map` (old_table, old_id) is unique, so
 * already-migrated rows are skipped. When the same email already exists in
 * `users` (a person who genuinely holds more than one legacy role — e.g. a
 * manager who is also a teacher), the existing user row is reused and a
 * second `user_roles` entry is added instead of failing on the email
 * uniqueness constraint.
 *
 * Known limitation of "reuse existing user by email": if two different
 * legacy rows for the same person both have a magic-link access_token set,
 * only the token from whichever table migrates first (see ORDER) survives
 * on the merged user. This is harmless during this additive phase since the
 * app still reads the legacy tables, but must be handled explicitly before
 * the later cutover phase for the handful of people this applies to.
 */
#[Signature('users:migrate-legacy {--dry-run : Only count rows per table and report; write nothing}')]
#[Description('Copy the 5 legacy role tables into the unified users/user_roles tables (Phase 2 of the users-table consolidation).')]
class MigrateLegacyUsers extends Command
{
    /**
     * Guardians must migrate before students (students.guardian_id needs the
     * guardian's NEW id). Managers must migrate before anything whose
     * approved_by/rejected_by needs remapping to a manager's new id.
     */
    protected const ORDER = ['managers', 'guardians', 'supervisors', 'teachers', 'students'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        foreach (self::ORDER as $table) {
            $count = DB::table($table)->count();
            $this->line("{$table}: {$count} row(s) found.");

            if (! $dryRun) {
                $this->migrateTable($table);
            }
        }

        $this->info($dryRun ? 'Dry run complete — nothing was written.' : 'Migration complete.');

        return self::SUCCESS;
    }

    protected function migrateTable(string $table): void
    {
        $role = rtrim($table, 's');
        $migrated = 0;
        $reused = 0;

        DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $role, &$migrated, &$reused) {
            foreach ($rows as $row) {
                $alreadyMapped = DB::table('id_migration_map')
                    ->where('old_table', $table)->where('old_id', $row->id)->exists();

                if ($alreadyMapped) {
                    continue;
                }

                DB::transaction(function () use ($table, $role, $row, &$migrated, &$reused) {
                    $existingUser = DB::table('users')->where('email', $row->email)->first();

                    if ($existingUser) {
                        $userId = $existingUser->id;
                        $reused++;
                    } else {
                        $userId = DB::table('users')->insertGetId($this->mapUserColumns($table, $row));
                        $migrated++;
                    }

                    DB::table('id_migration_map')->insert([
                        'old_table' => $table,
                        'old_id' => $row->id,
                        'new_id' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('user_roles')->insert([
                        'user_id' => $userId,
                        'role' => $role,
                        'is_approved' => (bool) ($row->is_approved ?? false),
                        'approved_by' => $this->mapId('managers', $row->approved_by ?? null),
                        'is_rejected' => (bool) ($row->is_rejected ?? false),
                        'rejected_at' => $row->rejected_at ?? null,
                        'rejected_by' => $this->mapId('managers', $row->rejected_by ?? null),
                        'is_data_completed' => (bool) ($row->is_data_completed ?? true),
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ]);
                });
            }
        });

        $this->info("{$table}: {$migrated} new user(s) created, {$reused} matched an existing user by email.");
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapUserColumns(string $table, object $row): array
    {
        $data = [
            'name' => $row->name,
            'email' => $row->email,
            'email_verified_at' => $row->email_verified_at ?? null,
            'password' => $row->password,
            'remember_token' => $row->remember_token ?? null,
            'two_factor_secret' => $row->two_factor_secret ?? null,
            'two_factor_recovery_codes' => $row->two_factor_recovery_codes ?? null,
            'two_factor_confirmed_at' => $row->two_factor_confirmed_at ?? null,
            'phone' => $row->phone ?? null,
            'access_token' => $row->access_token ?? null,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];

        if ($table === 'teachers') {
            $data['permissions'] = $row->permissions ?? null;
        }

        if ($table === 'students') {
            $data['circle_id'] = $row->circle_id ?? null;
            $data['guardian_id'] = $this->mapId('guardians', $row->guardian_id ?? null);
            $data['stage_id'] = $row->stage_id ?? null;
            $data['national_id'] = $row->national_id ?? null;
            $data['nationality'] = $row->nationality ?? null;
            $data['birth_date'] = $row->birth_date ?? null;
            $data['joined_at'] = $row->joined_at ?? null;
            $data['status'] = $row->status ?? 'active';
            $data['avatar_path'] = $row->avatar_path ?? null;
        }

        return $data;
    }

    protected function mapId(string $oldTable, ?int $oldId): ?int
    {
        if (! $oldId) {
            return null;
        }

        return DB::table('id_migration_map')
            ->where('old_table', $oldTable)->where('old_id', $oldId)
            ->value('new_id');
    }
}
