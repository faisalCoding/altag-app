<?php

namespace App\Models\Concerns;

use App\Models\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scopes a User subclass (Manager/Supervisor/Teacher/Student/Guardian) to
 * the users who hold that one role, and relays the per-role approval columns
 * (which live on `user_roles`, not `users`, since a person can be approved for
 * one role and pending for another simultaneously) through attribute
 * accessors/mutators so existing call sites reading/writing e.g. `is_approved`
 * keep working unchanged. Query *filters* on those columns cannot be relayed
 * transparently (they operate on raw SQL columns), so callers must use
 * `whereRoleState()` instead of a raw `where('is_approved', ...)`.
 */
trait BelongsToRole
{
    /** @var array<string, mixed> */
    protected array $pendingRoleAttributes = [];

    protected static function bootBelongsToRole(): void
    {
        static::addGlobalScope('role', function (Builder $builder) {
            $builder->whereHas('roles', function ($q) {
                $q->where('role', static::ROLE);
            });
        });

        // `saved` (unlike `updated`) fires unconditionally on every save, even
        // when Eloquent sees no dirty real columns — which is always the case
        // here, since these mutators redirect straight into
        // $pendingRoleAttributes instead of $this->attributes.
        static::saved(function (self $model) {
            if ($model->wasRecentlyCreated) {
                $model->roles()->firstOrCreate(['role' => static::ROLE], $model->pendingRoleAttributes);
            }

            if ($model->pendingRoleAttributes !== []) {
                $model->roleRecord()->firstOrCreate(['role' => static::ROLE])
                    ->update($model->pendingRoleAttributes);
            }

            $model->pendingRoleAttributes = [];
            $model->unsetRelation('roleRecord');
        });
    }

    protected function initializeBelongsToRole(): void
    {
        $this->with = array_unique(array_merge($this->with, ['roleRecord']));
    }

    /** @return HasOne<UserRole, $this> */
    public function roleRecord(): HasOne
    {
        return $this->hasOne(UserRole::class, 'user_id')->where('role', static::ROLE);
    }

    /**
     * Resolve a magic-link access token, falling back to the pre-consolidation
     * role table. When the 6 legacy tables merged into `users`, a person with
     * several roles kept only the first-migrated role's token on the unified
     * row — links issued for their other roles would otherwise dead-end. The
     * fallback finds the token in this role's legacy table and follows
     * `id_migration_map` to the live user, so every link sent before the
     * consolidation keeps working. Once the legacy tables are dropped in the
     * final cleanup phase, the fallback silently disappears.
     */
    public static function findByAccessToken(string $token): ?static
    {
        $direct = static::where('access_token', $token)->first();

        if ($direct) {
            return $direct;
        }

        $legacyTable = static::ROLE.'s';

        if (! Schema::hasTable($legacyTable) || ! Schema::hasTable('id_migration_map')) {
            return null;
        }

        $legacyId = DB::table($legacyTable)->where('access_token', $token)->value('id');

        if (! $legacyId) {
            return null;
        }

        $newId = DB::table('id_migration_map')
            ->where('old_table', $legacyTable)
            ->where('old_id', $legacyId)
            ->value('new_id');

        return $newId ? static::find($newId) : null;
    }

    /**
     * Deleting a role-scoped model must never destroy the person's whole
     * account: one users row can hold several roles (e.g. manager + student),
     * and each panel only manages its own. "Delete" removes this model's
     * role row; the underlying users row is deleted only when no other role
     * remains on it.
     */
    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(function () {
            if ($this->roles()->where('role', '!=', static::ROLE)->exists()) {
                $this->roles()->where('role', static::ROLE)->delete();
                $this->unsetRelation('roles');
                $this->unsetRelation('roleRecord');

                return true;
            }

            return parent::delete();
        });
    }

    public function getIsApprovedAttribute(): bool
    {
        return (bool) ($this->roleRecord?->is_approved ?? false);
    }

    public function setIsApprovedAttribute(mixed $value): void
    {
        $this->pendingRoleAttributes['is_approved'] = (bool) $value;
    }

    public function getApprovedByAttribute(): ?int
    {
        return $this->roleRecord?->approved_by;
    }

    public function setApprovedByAttribute(mixed $value): void
    {
        $this->pendingRoleAttributes['approved_by'] = $value;
    }

    public function getIsRejectedAttribute(): bool
    {
        return (bool) ($this->roleRecord?->is_rejected ?? false);
    }

    public function setIsRejectedAttribute(mixed $value): void
    {
        $this->pendingRoleAttributes['is_rejected'] = (bool) $value;
    }

    public function getRejectedAtAttribute(): mixed
    {
        return $this->roleRecord?->rejected_at;
    }

    public function setRejectedAtAttribute(mixed $value): void
    {
        $this->pendingRoleAttributes['rejected_at'] = $value;
    }

    public function getRejectedByAttribute(): ?int
    {
        return $this->roleRecord?->rejected_by;
    }

    public function setRejectedByAttribute(mixed $value): void
    {
        $this->pendingRoleAttributes['rejected_by'] = $value;
    }

    public function getIsDataCompletedAttribute(): bool
    {
        return (bool) ($this->roleRecord?->is_data_completed ?? true);
    }

    public function setIsDataCompletedAttribute(mixed $value): void
    {
        $this->pendingRoleAttributes['is_data_completed'] = (bool) $value;
    }

    /**
     * Filter the query by a condition on the associated `user_roles` row —
     * the query-builder equivalent of reading `is_approved` etc. as an
     * attribute, since those columns no longer exist on `users` directly.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereRoleState(Builder $query, \Closure $callback): Builder
    {
        return $query->whereHas('roles', function ($q) use ($callback) {
            $q->where('role', static::ROLE);
            $callback($q);
        });
    }
}
