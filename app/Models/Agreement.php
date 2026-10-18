<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * إقرار: what the students of some stages, or their guardians, give their
 * word on. It holds its author's draft; each publish freezes it as a version
 * (App\Support\Agreements).
 *
 * A general one binds every student of its stages — of every stage when
 * `all_stages`, as the manager may write it. A template binds nobody: a
 * teacher sends it to students they pick, each send an AgreementRequest.
 */
class Agreement extends Model
{
    /** @var list<string> */
    protected $fillable = ['title', 'type', 'audience', 'topics', 'status', 'created_by', 'kind', 'all_stages', 'author_role'];

    public const KINDS = ['general' => 'إقرار عام', 'template' => 'نموذج يرسله المعلم'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['topics' => 'array', 'all_stages' => 'boolean'];
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

    /** @return HasMany<AgreementRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(AgreementRequest::class);
    }

    public function isTemplate(): bool
    {
        return $this->kind === 'template';
    }

    /**
     * The agreements that reach any of the stages: those written for one of
     * them, and those written for every stage.
     *
     * @param  Builder<Agreement>  $query
     * @param  array<int, int>  $stageIds
     */
    public function scopeReaching(Builder $query, array $stageIds): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('all_stages', true)
            ->orWhereHas('stages', fn (Builder $stages) => $stages->whereIn('stages.id', $stageIds)));
    }

    /**
     * The stages the agreement reaches: every one when written for all.
     *
     * @return list<int>
     */
    public function stageIds(): array
    {
        return $this->all_stages ? Stage::pluck('id')->all() : $this->stages->modelKeys();
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
