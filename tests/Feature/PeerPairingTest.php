<?php

use App\Models\Attendance;
use App\Models\Ayah;
use App\Models\Circle;
use App\Models\PeerPair;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\StudentPlanDay;
use App\Models\Surah;
use App\Models\Teacher;
use App\Services\PeerPairing;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-08 10:00:00'); // 13:00 in Riyadh.

    // One surah of twenty verses: ayah ids 1–20.
    Surah::create([
        'id' => 1, 'number' => 1, 'name_arabic' => 'الفاتحة', 'name_simple' => 'Al-Fatihah',
        'revelation_place' => 'makkah', 'revelation_order' => 1, 'verses_count' => 20, 'start_page' => 1, 'end_page' => 1,
    ]);

    foreach (range(1, 20) as $verse) {
        Ayah::create([
            'id' => $verse, 'surah_id' => 1, 'verse_number' => $verse, 'page_number' => 1,
            'line_number_start' => 1, 'line_number_end' => 1, 'verse_key' => "1:{$verse}",
            'juz_number' => 1, 'hizb_number' => 1, 'rub_number' => 1, 'ruku_number' => 1, 'manzil_number' => 1, 'text_uthmani' => 'آية',
        ]);
    }

    $this->circle = Circle::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->teacher->circles()->attach($this->circle->id);

    // Memorised 1–10, reviewing 1–5; memorised 1–12, reviewing 3–8: each
    // holds the other's review.
    $this->ahmad = pairStudent('أحمد', memorised: 10, review: [1, 5]);
    $this->badr = pairStudent('بدر', memorised: 12, review: [3, 8]);
    // Memorised 1–4 only: holds neither's review, and they not his alone.
    $this->saad = pairStudent('سعد', memorised: 4, review: [1, 3]);
    // Memorised all twenty, nothing pending: only ever listens.
    $this->khalid = pairStudent('خالد', memorised: 20, review: null);
    // Nothing memorised on record.
    $this->omar = pairStudent('عمر', memorised: null, review: null);
});

/**
 * A present student with a forward plan: a hifz day graded excellent up to
 * the verse memorised, and a review day not recited yet.
 *
 * @param  array{0: int, 1: int}|null  $review
 */
function pairStudent(string $name, ?int $memorised, ?array $review): Student
{
    $student = Student::factory()->create(['name' => $name, 'circle_id' => test()->circle->id, 'status' => 'active']);

    Attendance::create([
        'student_id' => $student->id, 'circle_id' => test()->circle->id, 'teacher_id' => test()->teacher->id,
        'date' => '2026-07-08', 'status' => 'present',
    ]);

    if ($memorised === null) {
        return $student;
    }

    $plan = StudentPlan::create([
        'student_id' => $student->id, 'teacher_id' => test()->teacher->id, 'start_date' => '2026-07-01', 'days_count' => 2,
        'active_days' => [0, 1, 2, 3, 4, 5, 6], 'status' => 'active', 'plan_type' => 'hifz_review',
        'direction' => 'forward', 'is_approved' => true, 'created_by_role' => 'teacher',
    ]);

    StudentPlanDay::create([
        'student_plan_id' => $plan->id, 'date' => '2026-07-01', 'day_name' => 'Wednesday',
        'from_ayah_id' => 1, 'to_ayah_id' => $memorised, 'hifz_achievement' => 3, 'hifz_graded_at' => '2026-07-01 09:00:00',
    ]);

    if ($review) {
        StudentPlanDay::create([
            'student_plan_id' => $plan->id, 'date' => '2026-07-08', 'day_name' => 'Wednesday',
            'review_from_ayah_id' => $review[0], 'review_to_ayah_id' => $review[1],
        ]);
    }

    return $student;
}

/** The day's pairs as [first name, second name, mutual]. */
function pairNames(): array
{
    return PeerPairing::forDay(test()->circle->id, '2026-07-08')
        ->map(fn (PeerPair $pair) => [$pair->first->name, $pair->second->name, $pair->mutual])
        ->all();
}

it('pairs mutually those holding each other\'s review, and one to listen to another', function () {
    PeerPairing::generate($this->circle, '2026-07-08', $this->teacher->id);

    expect(pairNames())->toBe([
        ['أحمد', 'بدر', true],
        ['سعد', 'خالد', false],
    ]);

    $mutual = PeerPair::where('mutual', true)->sole();
    expect([$mutual->first_from_ayah_id, $mutual->first_to_ayah_id])->toBe([1, 5])
        ->and([$mutual->second_from_ayah_id, $mutual->second_to_ayah_id])->toBe([3, 8]);

    $unpaired = PeerPairing::unpaired($this->circle->id, '2026-07-08', PeerPairing::forDay($this->circle->id, '2026-07-08'));
    expect($unpaired->map(fn ($item) => [$item['student']->name, $item['reason']])->all())
        ->toBe([['عمر', 'ليس له سجل حفظ معتمد']]);
});

it('never calls a pair mutual when one of them has nothing to recite', function () {
    $this->ahmad->delete();
    $this->badr->delete();

    PeerPairing::generate($this->circle, '2026-07-08', $this->teacher->id);

    // Khalid holds Saad's review but has none himself: he listens.
    expect(pairNames())->toBe([['سعد', 'خالد', false]]);
});

it('replaces the day\'s pairs and what was recorded on them when made again', function () {
    $first = PeerPairing::generate($this->circle, '2026-07-08', $this->teacher->id)->first();
    PeerPairing::record($first, 'first', 2, true);

    PeerPairing::generate($this->circle, '2026-07-08', $this->teacher->id);

    expect(PeerPair::count())->toBe(2)
        ->and(PeerPair::whereNotNull('first_mistakes')->exists())->toBeFalse();
});

it('swaps two paired students, each keeping their own portion', function () {
    PeerPairing::generate($this->circle, '2026-07-08', $this->teacher->id);

    PeerPairing::swap($this->circle, '2026-07-08', $this->badr->id, $this->saad->id);

    expect(pairNames())->toBe([
        ['أحمد', 'سعد', true],
        ['بدر', 'خالد', false],
    ]);

    $moved = PeerPair::where('first_id', $this->badr->id)->sole();
    expect([$moved->first_from_ayah_id, $moved->first_to_ayah_id])->toBe([3, 8]);
});

it('puts an unpaired student in a paired one\'s place, and pairs two left out', function () {
    PeerPairing::generate($this->circle, '2026-07-08', $this->teacher->id);

    // Omar takes Khalid's place: he listens; Khalid is left out.
    PeerPairing::swap($this->circle, '2026-07-08', $this->khalid->id, $this->omar->id);
    expect(pairNames())->toContain(['سعد', 'عمر', false]);

    // And back: Khalid takes Omar's place again.
    PeerPairing::swap($this->circle, '2026-07-08', $this->omar->id, $this->khalid->id);
    expect(pairNames())->toContain(['سعد', 'خالد', false]);
});

it('pairs two students left out, the one with a review reciting', function () {
    $this->ahmad->delete();
    $this->badr->delete();
    $this->khalid->delete();

    PeerPairing::generate($this->circle, '2026-07-08', $this->teacher->id);
    expect(pairNames())->toBe([]);

    PeerPairing::swap($this->circle, '2026-07-08', $this->omar->id, $this->saad->id);

    expect(pairNames())->toBe([['سعد', 'عمر', false]]);
});

it('records a recitation\'s outcome, but none for a listener', function () {
    PeerPairing::generate($this->circle, '2026-07-08', $this->teacher->id);
    $oneWay = PeerPair::where('mutual', false)->sole();

    PeerPairing::record($oneWay, 'first', 3, false);
    PeerPairing::record($oneWay, 'second', 1, true);

    $oneWay->refresh();
    expect([$oneWay->first_mistakes, $oneWay->first_ready])->toBe([3, false])
        ->and([$oneWay->second_mistakes, $oneWay->second_ready])->toBe([null, null]);
});

// ── The site's page ─────────────────────────────────────────────────────────

it('lets the teacher make the pairs, record and swap from the site', function () {
    $this->actingAs($this->teacher, 'teacher');

    $page = Livewire::test('teacher.pairs-manager')
        ->assertSet('date', '2026-07-08')
        ->call('generatePairs')
        ->assertSee('أحمد')
        ->assertSee('عمر');

    $mutual = PeerPair::where('mutual', true)->sole();

    $page->call('adjustMistakes', $mutual->id, 'first', 1)
        ->call('adjustMistakes', $mutual->id, 'first', 1)
        ->call('toggleReady', $mutual->id, 'second');

    expect($mutual->fresh()->first_mistakes)->toBe(2)
        ->and($mutual->fresh()->second_ready)->toBeTrue();

    $page->call('pick', $this->badr->id)->assertSet('swapping', $this->badr->id)
        ->call('pick', $this->saad->id)->assertSet('swapping', null);

    expect(PeerPair::where('first_id', $this->badr->id)->exists())->toBeTrue();
});

it('shows the outcome as a hint on the tasmeeh card', function () {
    $pair = PeerPairing::generate($this->circle, '2026-07-08', $this->teacher->id)->first();
    PeerPairing::record($pair, 'first', 2, true);

    $this->actingAs($this->teacher, 'teacher');

    Livewire::test('teacher.student-tasmeeh-card', [
        'student' => $this->ahmad,
        'sPlans' => StudentPlan::where('student_id', $this->ahmad->id)->get(),
        'activePlanId' => StudentPlan::where('student_id', $this->ahmad->id)->value('id'),
        'gradedAtDate' => '2026-07-08',
    ])->assertSee('التسميع المتبادل:')->assertSee('2 أخطاء')->assertSee('جاهز للمعلم');
});

// ── The app ─────────────────────────────────────────────────────────────────

it('makes, swaps and records pairs from the app, and sends them with the sync', function () {
    Sanctum::actingAs($this->teacher);

    $this->postJson('/api/v1/teacher/pairs/generate', ['circle_id' => $this->circle->id, 'date' => '2026-07-08'])
        ->assertSuccessful()
        ->assertJsonCount(2, 'data.pairs')
        ->assertJsonPath('data.pairs.0.mutual', true)
        ->assertJsonPath('data.pairs.0.first.student_id', $this->ahmad->id)
        ->assertJsonPath('data.pairs.0.first.portion', ['from' => ['surah' => 1, 'verse' => 1], 'to' => ['surah' => 1, 'verse' => 5]]);

    $pair = PeerPair::where('mutual', true)->sole();

    $this->postJson("/api/v1/teacher/pairs/{$pair->id}/result", ['place' => 'second', 'mistakes' => 1, 'ready' => true])
        ->assertSuccessful()
        ->assertJsonPath('data.pair.second.mistakes', 1)
        ->assertJsonPath('data.pair.second.ready', true);

    $this->postJson('/api/v1/teacher/pairs/swap', [
        'circle_id' => $this->circle->id, 'date' => '2026-07-08', 'student_id' => $this->badr->id, 'with_id' => $this->omar->id,
    ])->assertSuccessful();

    $this->getJson('/api/v1/teacher/sync')
        ->assertJsonPath('data.pages.pairs', true)
        ->assertJsonCount(2, 'data.peer_pairs');
});

it('refuses another teacher\'s circle and pair', function () {
    Sanctum::actingAs(Teacher::factory()->create());

    $this->postJson('/api/v1/teacher/pairs/generate', ['circle_id' => $this->circle->id, 'date' => '2026-07-08'])
        ->assertForbidden()
        ->assertJsonPath('code', 'circle_unavailable');

    $pair = PeerPairing::generate($this->circle, '2026-07-08', null)->first();

    $this->postJson("/api/v1/teacher/pairs/{$pair->id}/result", ['place' => 'first', 'mistakes' => 0, 'ready' => true])
        ->assertForbidden();
});
