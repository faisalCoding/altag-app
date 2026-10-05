<?php

use App\Models\Circle;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use App\Services\GamificationNewsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stage = Stage::create(['name' => 'مرحلة اختبار شارة الأخبار']);
    $this->circle = Circle::create(['name' => 'حلقة اختبار شارة الأخبار', 'stage_id' => $this->stage->id]);

    $this->student = Student::create([
        'name' => 'طالب تجربة شارة الأخبار',
        'email' => 'news-badge-student@example.com',
        'password' => bcrypt('password'),
        'circle_id' => $this->circle->id,
        'is_approved' => true,
        'status' => 'active',
    ]);

    $this->leaderboard = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة اختبار شارة الأخبار',
        'competition_type' => 'gamification',
        'start_date' => now()->subDays(2),
        'end_date' => now()->addDays(2),
        'is_active' => true,
        'settings' => [],
    ]);
    $this->leaderboard->circles()->attach($this->circle->id);

    $this->actingAs($this->student, 'student');
});

it('loads the latest news ids for the competition on mount', function () {
    GamificationNewsService::record($this->leaderboard->id, 'level_up', ['student_name' => 'أحمد']);
    $second = GamificationNewsService::record($this->leaderboard->id, 'level_up', ['student_name' => 'سارة']);

    Livewire::test('student.gamification-news-badge', ['leaderboardId' => $this->leaderboard->id])
        ->assertSet('newsIds', fn ($ids) => $ids === [$second->id, $second->id - 1]);
});

it('refreshes the news ids when polled', function () {
    $component = Livewire::test('student.gamification-news-badge', ['leaderboardId' => $this->leaderboard->id])
        ->assertSet('newsIds', []);

    $news = GamificationNewsService::record($this->leaderboard->id, 'level_up', ['student_name' => 'خالد']);

    $component->call('refresh')
        ->assertSet('newsIds', [$news->id]);
});

it('only includes news belonging to the given competition', function () {
    $otherLeaderboard = Leaderboard::create([
        'circle_id' => $this->circle->id,
        'title' => 'مسابقة أخرى',
        'competition_type' => 'gamification',
        'start_date' => now()->subDays(2),
        'end_date' => now()->addDays(2),
        'is_active' => true,
        'settings' => [],
    ]);

    GamificationNewsService::record($otherLeaderboard->id, 'level_up', ['student_name' => 'فهد']);

    Livewire::test('student.gamification-news-badge', ['leaderboardId' => $this->leaderboard->id])
        ->assertSet('newsIds', []);
});

it('renders the news badge inside the student gamification bottom nav', function () {
    GamificationNewsService::record($this->leaderboard->id, 'level_up', ['student_name' => 'منى']);

    $response = $this->get(route('student.dashboard'));

    $response->assertSuccessful();
    $response->assertSeeLivewire('student.gamification-news-badge');
});

it('marks as seen the newest news the tab drew, not only the ids the badge last asked for', function () {
    GamificationNewsService::record($this->leaderboard->id, 'level_up', ['student_name' => 'أحمد', 'level_name' => 'المستوى 2']);
    $newest = GamificationNewsService::record($this->leaderboard->id, 'level_up', ['student_name' => 'سارة', 'level_name' => 'المستوى 3']);

    $html = $this->get(route('student.dashboard'))->assertSuccessful()->getContent();

    // The tab is drawn on every tap on the page, so it may hold news newer
    // than the badge's last ask once a minute; opening it marks that too.
    expect($html)->toContain('data-news-max-id="'.$newest->id.'"')
        ->and($html)->toContain("let drawn = Number(document.querySelector('[data-news-max-id]')?.dataset.newsMaxId || 0);")
        ->and($html)->toContain('this.seen = Math.max(this.seen, max, drawn);');
});

it('draws no news id on the tab for a day without news', function () {
    $html = $this->get(route('student.dashboard'))->assertSuccessful()->getContent();

    expect($html)->toContain('data-news-max-id="0"');
});
