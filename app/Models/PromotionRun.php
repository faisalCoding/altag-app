<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'status', 'created_by_id', 'applied_at', 'reverted_at'])]
class PromotionRun extends Model
{
    use HasFactory;

    public const DRAFT = 'draft';

    public const APPLIED = 'applied';

    public const REVERTED = 'reverted';

    protected $casts = [
        'applied_at' => 'datetime',
        'reverted_at' => 'datetime',
    ];

    /** @return HasMany<PromotionRunItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PromotionRunItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * A draft is the only state that may still be edited or applied.
     */
    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /**
     * Only a run that actually moved students can be put back.
     */
    public function isApplied(): bool
    {
        return $this->status === self::APPLIED;
    }
}
