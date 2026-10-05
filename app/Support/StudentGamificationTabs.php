<?php

namespace App\Support;

use App\Models\GamificationTeam;

/**
 * The tabs of the student's themed dashboard, in the order every switcher
 * shows them.
 *
 * Gathered here because three places need the same list: the phone's bottom
 * bar, the strip that stands in for it on a wide screen, and the dashboard's
 * check of the tab it remembered. Kept apart, a tab could be offered by one
 * and missing from another, which is how a remembered «فريقي» came to open
 * onto a blank page.
 */
class StudentGamificationTabs
{
    /**
     * The team tab only for a student on a team: its panel isn't drawn for
     * anyone else, so offering it would open onto nothing.
     *
     * @param  array<string, mixed>|null  $theme  the competition's theme, for what it calls «my team»
     * @return list<array{tab: string, name: string, icon: string}>
     */
    public static function for(?GamificationTeam $team, ?array $theme = null): array
    {
        $tabs = [
            ['tab' => 'leaderboard', 'name' => 'الرئيسية', 'icon' => 'home'],
            ['tab' => 'store', 'name' => 'المتجر', 'icon' => 'shopping-bag'],
            ['tab' => 'badges', 'name' => 'الأوسمة', 'icon' => 'trophy'],
            ['tab' => 'news', 'name' => 'الأخبار', 'icon' => 'newspaper'],
        ];

        if ($team) {
            $tabs[] = ['tab' => 'team', 'name' => $theme['team_possessive_my'] ?? 'فريقي', 'icon' => 'users'];
        }

        return $tabs;
    }
}
