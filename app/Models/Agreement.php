<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * إقرار: what the students of some stages, or their guardians, give their
 * word on. It holds the supervisor's draft; each publish freezes it as a
 * version (App\Support\Agreements).
 */
class Agreement extends Model
{
    /** @var list<string> */
    protected $fillable = ['title', 'type', 'audience', 'topics', 'status', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['topics' => 'array'];
    }

    /** @return BelongsToMany<Stage, $this> */
    public function stages(): BelongsToMany
    {
        return $this->belongsToMany(Stage::class);
    }

    /** @return HasMany<AgreementVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AgreementVersion::class);
    }

    /** The version on show: the last one published. */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(AgreementVersion::class)->latestOfMany('number');
    }

    /** @return HasMany<AgreementSignature, $this> */
    public function signatures(): HasMany
    {
        return $this->hasMany(AgreementSignature::class);
    }

    /** @return HasMany<AgreementCircleType, $this> */
    public function circleTypes(): HasMany
    {
        return $this->hasMany(AgreementCircleType::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
