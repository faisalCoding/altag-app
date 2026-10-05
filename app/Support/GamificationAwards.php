<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * The awards waiting for the student to take them on their gamification page:
 * badges a teacher or supervisor approved, and streak milestones reached. Each
 * shows as a celebration card and as a row in the rewards panel, and both ask
 * the claim store whether its key is hidden, so taking it in one place takes
 * it from the other too.
 *
 * The keys must not move under an open page. A badge's is its id. A
 * milestone's claim row is written again (with a new id) whenever the streak
 * is rebuilt, and the same milestone can be reached again on a later run, so
 * its key is the milestone's id and the day the run reached it: the same
 * across rebuilds, and different for each run.
 */
class GamificationAwards
{
    /**
     * @param  iterable<int, object>  $badges  approved badges (GamificationBadge rows)
     * @param  iterable<int, object>  $milestones  approved milestone rows, with reached_on
     * @return list<array{key: string, kind: 'badge'|'milestone', id: int, name: string, description: string, icon: string|null, days: int|null, xp: int, coins: int}>
     */
    public static function from(iterable $badges, iterable $milestones): array
    {
        $awards = [];

        foreach ($badges as $badge) {
            $awards[] = [
                'key' => self::badgeKey((int) $badge->id),
                'kind' => 'badge',
                'id' => (int) $badge->id,
                'name' => (string) $badge->name,
                'description' => (string) ($badge->description ?? ''),
                'icon' => $badge->icon,
                'days' => null,
                'xp' => max(0, (int) $badge->reward_xp),
                'coins' => max(0, (int) $badge->reward_coins),
            ];
        }

        foreach ($milestones as $milestone) {
            $awards[] = [
                'key' => self::milestoneKey((int) $milestone->id, (string) $milestone->reached_on),
                'kind' => 'milestone',
                'id' => (int) $milestone->id,
                'name' => '',
                'description' => (string) ($milestone->description ?? ''),
                'icon' => null,
                'days' => (int) $milestone->days_required,
                'xp' => max(0, (int) $milestone->reward_xp),
                'coins' => max(0, (int) $milestone->reward_coins),
            ];
        }

        return $awards;
    }

    public static function badgeKey(int $badgeId): string
    {
        return 'badge-'.$badgeId;
    }

    /**
     * @param  string  $reachedOn  when the streak reached it (its claim row's created_at)
     */
    public static function milestoneKey(int $milestoneId, string $reachedOn): string
    {
        return 'milestone-'.$milestoneId.'-'.Carbon::parse($reachedOn)->toDateString();
    }
}
