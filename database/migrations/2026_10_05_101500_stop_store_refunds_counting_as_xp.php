<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Store refunds give coins back, not XP.
 *
 * A purchase rejected by vote, a cancelled purchase and a cancelled attack
 * were each refunded with an 'earn' row that named no XP, and the transaction
 * model fills in XP equal to the coins for such a row. So the buyer's XP,
 * level and rank (and their team's score, or the attacked team's) rose by
 * the price of something they never got. The refunds now say 'xp_amount' => 0;
 * this takes the XP off the refunds already written.
 *
 * Cancelling a «دعم الفريق» purchase was written as a 'spend', which the team
 * score never reads, so the support points stayed. It is now an earning of
 * minus the points, as a supervisor's deduction from a team is written, and
 * the cancels already written are turned into that. Each is dated as the
 * support row it takes back, as a cancel now is: the team score doubles a row
 * on a team multiplier day of its own, so a cancel dated by its own day left
 * the team above or below where it stood whenever the two days differed.
 *
 * All of it is idempotent: a second run finds nothing left to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $purchase = 'App\\Models\\GamificationStorePurchase';

        DB::table('gamification_transactions')
            ->where('type', 'earn')
            ->where('reference_type', $purchase)
            ->where('description', 'like', 'استرداد%')
            ->where('amount', '>', 0)
            ->where('xp_amount', '>', 0)
            ->update(['xp_amount' => 0]);

        DB::table('gamification_transactions')
            ->where('type', 'spend')
            ->where('reference_type', $purchase)
            ->whereNotNull('team_id')
            ->whereNull('student_id')
            ->where('description', 'like', 'إلغاء دعم الفريق:%')
            ->update(['type' => 'earn', 'xp_amount' => DB::raw('amount')]);

        $cancels = DB::table('gamification_transactions')
            ->where('type', 'earn')
            ->where('reference_type', $purchase)
            ->whereNotNull('team_id')
            ->whereNull('student_id')
            ->where('description', 'like', 'إلغاء دعم الفريق:%')
            ->get(['id', 'leaderboard_id', 'team_id', 'amount', 'reference_id', 'created_at']);

        foreach ($cancels as $cancel) {
            $supportedAt = $this->supportRowDate($cancel, $purchase);

            if ($supportedAt !== null && $supportedAt !== $cancel->created_at) {
                DB::table('gamification_transactions')
                    ->where('id', $cancel->id)
                    ->update(['created_at' => $supportedAt]);
            }
        }
    }

    /**
     * When the support row a cancel takes back was written, as
     * GamificationService::teamSupportRow() finds it: the row naming the
     * purchase, or for the rows written before that, the team's first support
     * row of the same size from the moment the purchase was made until the
     * cancel. Null when there is none to find.
     */
    private function supportRowDate(object $cancel, string $purchase): ?string
    {
        $supportRows = fn () => DB::table('gamification_transactions')
            ->where('leaderboard_id', $cancel->leaderboard_id)
            ->where('team_id', $cancel->team_id)
            ->whereNull('student_id')
            ->where('type', 'earn')
            ->where('description', 'تفعيل قوة ميزة دعم الفريق: +'.abs((int) $cancel->amount).' نقاط');

        $exact = $supportRows()
            ->where('reference_type', $purchase)
            ->where('reference_id', $cancel->reference_id)
            ->value('created_at');

        if ($exact !== null) {
            return $exact;
        }

        $boughtAt = DB::table('gamification_store_purchases')->where('id', $cancel->reference_id)->value('created_at');

        if ($boughtAt === null) {
            return null;
        }

        return $supportRows()
            ->whereNull('reference_type')
            ->where('created_at', '>=', $boughtAt)
            ->where('created_at', '<=', $cancel->created_at)
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('created_at');
    }

    /**
     * Not reversed: the XP taken off was never earned, and putting it back
     * would only reinstate the bug.
     */
    public function down(): void {}
};
