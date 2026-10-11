<?php

use App\Livewire\Manager\ExamBatches;
use App\Livewire\Shared\ExamReadinessList;
use App\Models\AppNotification;
use App\Models\Circle;
use App\Models\ExamBatch;
use App\Models\ExamLevel;
use App\Models\Manager;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Services\ExamReadiness;
use Carbon\Carbon;
use Livewire\Livewire;

/*
 * Sunday 11 October 2026. Two stages, a circle each; in the middle stage one
 * student ready and one only expected, in the primary one a student ready.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-11 07:00:00');

    $this->level = ExamLevel::create(['name' => 'جزء عم']);
    $this->middle = Stage::factory()->create(['name' => 'المتوسطة']);
    $this->primary = Stage::factory()->create(['name' => 'الابتدائية']);
    $this->teacher = Teacher::factory()->create(['name' => 'المعلم خالد']);

    $student = function (string $name, Stage $stage) {
        $circle = Circle::factory()->create(['stage_id' => $stage->id]);
        $this->teacher->circles()->attach($circle->id);

        return Student::factory()->create(['name' => $name, 'circle_id' => $circle->id]);
    };

    $this->ready = ExamReadiness::nominate($student('سالم', $this->middle), $this->level->id, null, $this->teacher);
    $this->expected = ExamReadiness::nominate($student('ماجد', $this->middle), $this->level->id, '2026-10-25', $this->teacher);
    $this->young = ExamReadiness::nominate($student('يوسف', $this->primary), $this->level->id, null, $this->teacher);

    $this->manager = Manager::factory()->create();
});

it('lists the ready apart from the expected, with who put them forward', function () {
    $this->actingAs($this->manager, 'manager');

    $list = Livewire::test(ExamReadinessList::class, ['role' => 'manager']);

    expect($list->instance()->ready->pluck('id')->all())->toEqualCanonicalizing([$this->ready->id, $this->young->id])
        ->and($list->instance()->expected->pluck('id')->all())->toBe([$this->expected->id]);

    $list->assertSee('رشّحه المعلم خالد')
        ->assertSee('يُدرجون في الدفعات بعد أن يؤكد معلموهم جاهزيتهم.')
        ->set('stageId', $this->primary->id)
        ->assertSee('يوسف')
        ->assertDontSee('سالم');
});

it('opens a batch on a day and hour for the students ticked, only the ready taken', function () {
    $this->actingAs($this->manager, 'manager');

    Livewire::test(ExamReadinessList::class, ['role' => 'manager'])
        ->set('selected', [(string) $this->ready->id, (string) $this->expected->id])
        ->call('openNewBatch')
        ->set('batchDate', '2026-10-20')
        ->set('batchTime', '16:30')
        ->set('batchLocation', 'قاعة الجمعية')
        ->call('createBatch')
        ->assertHasNoErrors()
        ->assertSet('selected', [])
        ->assertDispatched('exam-batch-saved');

    $batch = ExamBatch::sole();

    expect($batch->date_time->format('Y-m-d H:i'))->toBe('2026-10-20 16:30')
        ->and($batch->created_by)->toBe($this->manager->id)
        ->and($this->ready->fresh()->exam_batch_id)->toBe($batch->id)
        ->and($this->expected->fresh()->status)->toBe('nominated')
        ->and(AppNotification::where('type', 'exam_scheduled')->count())->toBe(1);
});

it('refuses a batch on a day gone by, and adds students to a batch already set', function () {
    $this->actingAs($this->manager, 'manager');
    $batch = ExamReadiness::createBatch('2026-10-20 16:30', null, null, [$this->young->id], $this->manager);

    Livewire::test(ExamReadinessList::class, ['role' => 'manager'])
        ->set('selected', [(string) $this->ready->id])
        ->set('batchDate', '2026-10-10')
        ->call('createBatch')
        ->assertHasErrors(['batchDate' => 'after_or_equal'])
        ->set('targetBatchId', $batch->id)
        ->call('addToBatch');

    expect($batch->exams()->count())->toBe(2);
});

it('shows a supervisor their stages only, and seats no one', function () {
    $supervisor = Supervisor::factory()->create();
    $supervisor->stages()->attach($this->middle->id);
    $this->actingAs($supervisor, 'supervisor');

    $this->get(route('supervisor.exam-readiness'))->assertSuccessful()->assertSee('جاهزية الاختبارات');

    $list = Livewire::test(ExamReadinessList::class, ['role' => 'supervisor'])
        ->assertSee('سالم')
        ->assertDontSee('يوسف')
        ->assertDontSee('إنشاء دفعة')
        ->set('selected', [(string) $this->ready->id])
        ->set('batchDate', '2026-10-20')
        ->call('createBatch');

    expect(ExamBatch::count())->toBe(0);
});

it('records a batch\'s results together, an absent student put forward again', function () {
    $this->actingAs($this->manager, 'manager');
    $batch = ExamReadiness::createBatch('2026-10-20 16:30', null, null, [$this->ready->id, $this->young->id], $this->manager);

    Livewire::test(ExamBatches::class)
        ->call('openBatch', $batch->id)
        ->assertSee('سالم')
        ->set("results.{$this->ready->id}.status", 'passed')
        ->set("results.{$this->ready->id}.score", '97')
        ->set("results.{$this->young->id}.status", 'absent')
        ->call('saveResults')
        ->assertHasNoErrors();

    expect($this->ready->fresh()->status)->toBe('passed')
        ->and($this->ready->fresh()->score_percentage)->toBe('97.00')
        ->and($this->young->fresh()->status)->toBe('absent')
        ->and(StudentExam::where('student_id', $this->young->student_id)->nominated()->whereNotNull('ready_at')->count())->toBe(1);
});

it('moves a batch and takes a student out of it, and lets an empty batch go', function () {
    $this->actingAs($this->manager, 'manager');
    $batch = ExamReadiness::createBatch('2026-10-20 16:30', null, null, [$this->ready->id], $this->manager);

    $page = Livewire::test(ExamBatches::class)
        ->call('openBatch', $batch->id)
        ->call('openEdit')
        ->assertSet('editDate', '2026-10-20')
        ->assertSet('editTime', '16:30')
        ->set('editTime', '17:00')
        ->call('saveEdit');

    expect($this->ready->fresh()->date_time->format('H:i'))->toBe('17:00')
        ->and(AppNotification::where('type', 'exam_rescheduled')->count())->toBe(1);

    $page->call('unseat', $this->ready->id)->call('deleteBatch');

    expect($this->ready->fresh()->status)->toBe('nominated')
        ->and(ExamBatch::count())->toBe(0);
});

it('draws the manager\'s page with both tabs', function () {
    $this->actingAs($this->manager, 'manager');

    $this->get(route('manager.exam-readiness'))
        ->assertSuccessful()
        ->assertSee('جاهزون للاختبار')
        ->assertSee('الدفعات');
});
