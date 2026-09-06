<?php

namespace App\Models;

use App\Services\GamificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GamificationStorePurchase extends Model
{
    /**
     * A bought multiplier changes what past earnings are worth to the team, so the
     * cached standings must be retired when one is bought, approved or withdrawn.
     */
    protected static function booted(): void
    {
        static::saved(function (GamificationStorePurchase $purchase) {
            GamificationService::forgetTeamStandings($purchase->item?->leaderboard_id);
        });

        static::deleted(function (GamificationStorePurchase $purchase) {
            GamificationService::forgetTeamStandings($purchase->item?->leaderboard_id);
        });
    }

    use HasFactory;

    protected $guarded = [];

    /** @return BelongsTo<GamificationStoreItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(GamificationStoreItem::class, 'store_item_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<GamificationTeam, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(GamificationTeam::class);
    }

    /** @return BelongsTo<GamificationTeam, $this> */
    public function targetTeam(): BelongsTo
    {
        return $this->belongsTo(GamificationTeam::class, 'target_team_id');
    }

    /** @return HasMany<GamificationPurchaseVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(GamificationPurchaseVote::class, 'store_purchase_id');
    }
}
