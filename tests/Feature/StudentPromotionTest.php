<?php

use App\Livewire\Manager\Promotions as PromotionsScreen;
use App\Models\Circle;
use App\Models\Leaderboard;
use App\Models\Manager;
use App\Models\PromotionRun;
use App\Models\PromotionRunItem;
use App\Models\Stage;
use App\Models\Student;
use App\Services\StudentPromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $primary = Stage::factory()->create(['name' => 'ابتدائية', 'level' => 1]);
    $middle = Stage::factory()->create(['name' => 'متوسطة', 'level' => 2]);

    $this->first = Circle::factory()->create(['name' => 'أول ابتدائي', 'stage_id' => $primary->id, 'level' => 1]);
    $this->sixth = Circle::factory()->create(['name' => 'سادس ابتدائي', 'stage_id' => $primary->id, 'level' => 2]);
    $this->seventh = Circle::factory()->create(['name' => 'أول متوسط', 'stage_id' => $middle->id, 'level' => 1]);
    $this->last = Circle::factory()->create(['name' => 'ثاني متوسط', 'stage_id' => $middle->id, 'level' => 2]);
    $this->offLadder = Circle::factory()->create(['name' => 'النورانية', 'stage_id' => $primary->id, 'level' => null]);

    $this->promotions = new StudentPromotionService;
});

it('proposes a destination for every active student from the ladder', function () {
    $climber = Student::factory()->create(['circle_id' => $this->first->id, 'status' => 'active']);
    $leaver = Student::factory()->create(['circle_id' => $this->last->id, 'status' => 'active']);
    $stayer = Student::factory()->create(['circle_id' => $this->offLadder->id, 'status' => 'active']);

    $run = $this->promotions->draft('١٤٤٨/١٤٤٩');

    $items = $run->items->keyBy('student_id');

    expect($items[$climber->id]->action)->toBe(PromotionRunItem::PROMOTE);
    expect($items[$climber->id]->to_circle_id)->toBe($this->sixth->id);

    expect($items[$leaver->id]->action)->toBe(PromotionRunItem::GRADUATE);
    expect($items[$stayer->id]->action)->toBe(PromotionRunItem::HOLD);
});

it('drafts without moving a single student', function () {
    $student = Student::factory()->create(['circle_id' => $this->first->id, 'status' => 'active']);

    $this->promotions->draft('١٤٤٨/١٤٤٩');

    expect($student->fresh()->circle_id)->toBe($this->first->id);
    expect($student->fresh()->status)->toBe('active');
});

it('leaves out students who are not active or hold no circle', function () {
    Student::factory()->create(['circle_id' => $this->first->id, 'status' => 'left']);
    Student::factory()->create(['circle_id' => null, 'status' => 'active']);
    $included = Student::factory()->create(['circle_id' => $this->first->id, 'status' => 'active']);

    $run = $this->promotions->draft('١٤٤٨/١٤٤٩');

    expect($run->items()->pluck('student_id')->all())->toBe([$included->id]);
    expect($this->promotions->studentsWithoutACircle())->toHaveCount(1);
});

it('moves students only when the run is applied', function () {
    $student = Student::factory()->create(['circle_id' => $this->first->id, 'status' => 'active']);

    $run = $this->promotions->draft('١٤٤٨/١٤٤٩');
    $this->promotions->apply($run);

    expect($student->fresh()->circle_id)->toBe($this->sixth->id);
    expect($run->fresh()->status)->toBe(PromotionRun::APPLIED);
    expect($run->fresh()->applied_at)->not->toBeNull();
});

it('records a graduation as having left, with a history entry', function () {
    $student = Student::factory()->create(['circle_id' => $this->last->id, 'status' => 'active']);

    $run = $this->promotions->draft('١٤٤٨/١٤٤٩');
    $this->promotions->apply($run);

    expect($student->fresh()->status)->toBe('left');
    expect($student->statusHistories()->where('status', 'left')->exists())->toBeTrue();
});

it('puts every student back where the run found them', function () {
    $climber = Student::factory()->create(['circle_id' => $this->first->id, 'status' => 'active']);
    $leaver = Student::factory()->create(['circle_id' => $this->last->id, 'status' => 'active']);

    $run = $this->promotions->draft('١٤٤٨/١٤٤٩');
    $this->promotions->apply($run);

    expect($climber->fresh()->circle_id)->toBe($this->sixth->id);
    expect($leaver->fresh()->status)->toBe('left');

    $this->promotions->revert($run->fresh());

    expect($climber->fresh()->circle_id)->toBe($this->first->id);
    expect($leaver->fresh()->status)->toBe('active');
    expect($run->fresh()->status)->toBe(PromotionRun::REVERTED);
});

it('reverts correctly even after the ladder itself has been reordered', function () {
    $student = Student::factory()->create(['circle_id' => $this->first->id, 'status' => 'active']);

    $run = $this->promotions->draft('١٤٤٨/١٤٤٩');
    $this->promotions->apply($run);

    // The academy reshuffles its grades after the promotion.
    $this->first->update(['level' => 9]);
    $this->sixth->update(['level' => null]);

    $this->promotions->revert($run->fresh());

    // Restored from what the run recorded, not from the ladder as it now reads.
    expect($student->fresh()->circle_id)->toBe($this->first->id);
});

it('refuses to apply the same run twice', function () {
    Student::factory()->create(['circle_id' => $this->first->id, 'status' => 'active']);

    $run = $this->promotions->draft('١٤٤٨/١٤٤٩');
    $this->promotions->apply($run);

    expect(fn () => $this->promotions->apply($run->fresh()))->toThrow(RuntimeException::class);
});

it('refuses to revert a run that never ran', function () {
    $run = $this->promotions->draft('١٤٤٨/١٤٤٩');

    expect(fn () => $this->promotions->revert($run))->toThrow(RuntimeException::class);
});

it('does not double-move a student when a run is applied after an override', function () {
    $student = Student::factory()->create(['circle_id' => $this->first->id, 'status' => 'active']);

    $run = $this->promotions->draft('١٤٤٨/١٤٤٩');
    $run->items()->first()->update(['to_circle_id' => $this->seventh->id]);

    $this->promotions->apply($run->fresh());

    expect($student->fresh()->circle_id)->toBe($this->seventh->id);

    $this->promotions->revert($run->fresh());

    expect($student->fresh()->circle_id)->toBe($this->first->id);
});

it('holds a student in place when the manager clears their destination', function () {
    $student = Student::factory()->create(['circle_id' => $this->first->id, 'status' => 'active']);

    $this->actingAs(Manager::factory()->create(), 'manager');

    $run = $this->promotions->draft('١٤٤٨/١٤٤٩');
    $item = $run->items()->first();

    Livewire::test(PromotionsScreen::class)
        ->set('selectedRunId', $run->id)
        ->call('setDestination', $item->id, '')
        ->call('apply');

    expect($item->fresh()->action)->toBe(PromotionRunItem::HOLD);
    expect($student->fresh()->circle_id)->toBe($this->first->id);
});

it('will not let an applied run be edited', function () {
    Student::factory()->create(['circle_id' => $this->first->id, 'status' => 'active']);

    $this->actingAs(Manager::factory()->create(), 'manager');

    $run = $this->promotions->draft('١٤٤٨/١٤٤٩');
    $item = $run->items()->first();
    $this->promotions->apply($run);

    Livewire::test(PromotionsScreen::class)
        ->set('selectedRunId', $run->id)
        ->call('setAction', $item->id, PromotionRunItem::GRADUATE);

    expect($item->fresh()->action)->toBe(PromotionRunItem::PROMOTE);
});

it('creates a run from the screen and names it', function () {
    Student::factory()->create(['circle_id' => $this->first->id, 'status' => 'active']);

    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(PromotionsScreen::class)
        ->set('newRunName', '١٤٤٨/١٤٤٩')
        ->call('createRun')
        ->assertHasNoErrors();

    expect(PromotionRun::where('name', '١٤٤٨/١٤٤٩')->exists())->toBeTrue();
});

it('requires a name for the run', function () {
    $this->actingAs(Manager::factory()->create(), 'manager');

    Livewire::test(PromotionsScreen::class)
        ->set('newRunName', '')
        ->call('createRun')
        ->assertHasErrors('newRunName');

    expect(PromotionRun::count())->toBe(0);
});

it('guards the revert behind typing the run name', function () {
    $student = Student::factory()->create(['circle_id' => $this->first->id, 'status' => 'active']);

    $this->actingAs(Manager::factory()->create(), 'manager');

    $run = $this->promotions->draft('١٤٤٨/١٤٤٩');
    $this->promotions->apply($run);

    Livewire::test(PromotionsScreen::class)
        ->set('selectedRunId', $run->id)
        ->set('confirmRevertInput', 'خطأ')
        ->call('revert');

    expect($student->fresh()->circle_id)->toBe($this->sixth->id);
    expect($run->fresh()->status)->toBe(PromotionRun::APPLIED);
});

it('warns when a competition is running over the promotion', function () {
    Leaderboard::create([
        'circle_id' => $this->first->id,
        'title' => 'مسابقة جارية',
        'competition_type' => 'gamification',
        'is_active' => true,
        'start_date' => now()->subWeek(),
        'end_date' => now()->addWeek(),
    ]);

    expect($this->promotions->competitionsInFlight())->toHaveCount(1);
});

it('spreads a cohort across sections instead of piling it into one', function () {
    $stage = $this->seventh->stage;
    $sectionA = Circle::factory()->create(['name' => 'ثالث متوسط أ', 'stage_id' => $stage->id, 'level' => 3]);
    $sectionB = Circle::factory()->create(['name' => 'ثالث متوسط ب', 'stage_id' => $stage->id, 'level' => 3]);

    // Ten students all leaving one circle for a grade that has two sections.
    Student::factory()->count(10)->create(['circle_id' => $this->last->id, 'status' => 'active']);

    $run = $this->promotions->draft('١٤٤٨/١٤٤٩');

    $landed = $run->items()->pluck('to_circle_id')->countBy();

    expect($landed[$sectionA->id])->toBe(5);
    expect($landed[$sectionB->id])->toBe(5);
});

it('counts only the occupants a section will still have afterwards', function () {
    $stage = $this->seventh->stage;
    $crowded = Circle::factory()->create(['name' => 'ثالث متوسط أ', 'stage_id' => $stage->id, 'level' => 3]);
    $empty = Circle::factory()->create(['name' => 'ثالث متوسط ب', 'stage_id' => $stage->id, 'level' => 3]);

    // Suspended students are not in the run, so they are still sitting there
    // when the cohort arrives and the balance has to allow for them.
    Student::factory()->count(4)->create(['circle_id' => $crowded->id, 'status' => 'suspended']);
    Student::factory()->count(4)->create(['circle_id' => $this->last->id, 'status' => 'active']);

    $run = $this->promotions->draft('١٤٤٨/١٤٤٩');

    $arrivals = $run->items()->where('from_circle_id', $this->last->id)->pluck('to_circle_id')->countBy();

    expect($arrivals[$empty->id])->toBe(4);
    expect($arrivals->has($crowded->id))->toBeFalse();
});

it('ignores occupants that this very run is promoting out', function () {
    $stage = $this->seventh->stage;
    $leavingBehind = Circle::factory()->create(['name' => 'ثالث متوسط أ', 'stage_id' => $stage->id, 'level' => 3]);
    $alsoEmptying = Circle::factory()->create(['name' => 'ثالث متوسط ب', 'stage_id' => $stage->id, 'level' => 3]);

    // Both sections look uneven now, but the run empties both, so the arriving
    // cohort should still be split down the middle.
    Student::factory()->count(6)->create(['circle_id' => $leavingBehind->id, 'status' => 'active']);
    Student::factory()->count(4)->create(['circle_id' => $this->last->id, 'status' => 'active']);

    $run = $this->promotions->draft('١٤٤٨/١٤٤٩');

    $arrivals = $run->items()->where('from_circle_id', $this->last->id)->pluck('to_circle_id')->countBy();

    expect($arrivals[$leavingBehind->id])->toBe(2);
    expect($arrivals[$alsoEmptying->id])->toBe(2);
});

it('keeps a numbered cohort together when its section number exists ahead', function () {
    $stage = $this->seventh->stage;
    $sourceOne = Circle::factory()->create(['name' => 'ثاني متوسط 1', 'stage_id' => $stage->id, 'level' => 5]);
    $targetOne = Circle::factory()->create(['name' => 'ثالث متوسط 1', 'stage_id' => $stage->id, 'level' => 6]);
    Circle::factory()->create(['name' => 'ثالث متوسط 2', 'stage_id' => $stage->id, 'level' => 6]);

    Student::factory()->count(6)->create(['circle_id' => $sourceOne->id, 'status' => 'active']);

    $run = $this->promotions->draft('١٤٤٨/١٤٤٩');

    $arrivals = $run->items()->where('from_circle_id', $sourceOne->id)->pluck('to_circle_id')->unique();

    expect($arrivals->all())->toBe([$targetOne->id]);
});
