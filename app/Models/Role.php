<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'label',
        'guard_name',
        'is_system',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Screens whose route lives under this role's own namespace. */
    /** @return HasMany<Screen, $this> */
    public function ownedScreens(): HasMany
    {
        return $this->hasMany(Screen::class, 'owner_role_id');
    }

    /** @return HasMany<RoleScreenPermission, $this> */
    public function screenPermissions(): HasMany
    {
        return $this->hasMany(RoleScreenPermission::class);
    }

    /** @return BelongsTo<Manager, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Manager::class, 'created_by');
    }
}
