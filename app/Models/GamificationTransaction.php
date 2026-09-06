<?php

namespace App\Models;

use App\Services\GamificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GamificationTransaction extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'claimed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($transaction) {
            // Only default xp_amount when it was not provided at all.
            // An explicit 0 means "coins only, no score" and must be respected.
            if ($transaction->type === 'earn' && is_null($transaction->xp_amount)) {
                $transaction->xp_amount = $transaction->amount;
            }

            // Default to "already claimed" unless the caller explicitly passed
            // claimed_at (passing null marks the reward as pending a manual claim).
            if (! array_key_exists('claimed_at', $transaction->getAttributes())) {
                $transaction->claimed_at = now();
            }
        });

        // Points just moved, so the cached team standings are out of date.
        static::saved(function ($transaction) {
            GamificationService::forgetTeamStandings($transaction->leaderboard_id);
        });

        static::deleted(function ($transaction) {
            GamificationService::forgetTeamStandings($transaction->leaderboard_id);
        });
    }

    /**
     * Pending (unclaimed) reward transactions.
     */
    public function scopeUnclaimed($query)
    {
        return $query->whereNull('claimed_at');
    }

    /**
     * Transactions that count toward balances and standings.
     */
    public function scopeClaimed($query)
    {
        return $query->whereNotNull('claimed_at');
    }

    /** @return BelongsTo<Leaderboard, $this> */
    public function leaderboard(): BelongsTo
    {
        return $this->belongsTo(Leaderboard::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<GamificationTeam, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(GamificationTeam::class, 'team_id');
    }
}
