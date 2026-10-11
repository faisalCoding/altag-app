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
    $this->middle = Stage::factory()->create(['name' => 'المتوسطة', 'position' => 1]);
    $this->primary = Stage::factory()->create(['name' => 'الابتدائية', 'position' => 2]);
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
        ->assertSee('لم يؤكد معلموهم جاهزيتهم بعد، ويمكنك تحديدهم وإدراجهم في الدفعة.')
        ->set('stageId', $this->primary->id)
        ->assertSee('يوسف')
        ->assertDontSee('سالم');
});

it('opens a batch on a day and hour for the students ticked, warned of one expected ready only after it', function () {
    $this->actingAs($this->manager, 'manager');

    Livewire::test(ExamReadinessList::class, ['role' => 'manager'])
        ->set('selected', [(string) $this->ready->id, (string) $this->expected->id])
        ->call('openNewBatch')
        ->assertDontSee('تنبيه: جاهزية')
        ->set('batchDate', '2026-10-26')
        ->assertDontSee('تنبيه: جاهزية')
        ->set('batchDate', '2026-10-20')
        ->assertSee('تنبيه: جاهزية')
        ->assertSee('ماجد — متوقعة')
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
        // Expected only, and seated all the same: the warning is no bar.
        ->and($this->expected->fresh()->exam_batch_id)->toBe($batch->id)
        ->and(AppNotification::where('type', 'exam_scheduled')->count())->toBe(2);
});

it('lists the ready by stage and circle, and ticks a stage or everyone at once', function () {
    $this->actingAs($this->manager, 'manager');

    $list = Livewire::test(ExamReadinessList::class, ['role' => 'manager']);

    expect($list->instance()->readyGroups->pluck('name')->all())->toBe(['المتوسطة', 'الابتدائية'])
        ->and($list->instance()->readyGroups->first()['circles']->first()['exams']->pluck('id')->all())->toBe([$this->ready->id]);

    $list->assertSeeInOrder(['المتوسطة', 'سالم', 'الابتدائية', 'يوسف'])
        ->assertSee('تحديد الجميع من كل المراحل والحلقات')
        ->call('toggleMany', [$this->young->id])
        ->assertSet('selected', [$this->young->id])
        ->call('toggleMany', [$this->ready->id, $this->young->id])
        ->assertSet('selected', [$this->young->id, $this->ready->id])
        // All ticked already: the same toggle lets them all go.
        ->call('toggleMany', [$this->ready->id, $this->young->id])
        ->assertSet('selected', []);
});

it('warns before adding a student expected later to a batch already set', function () {
    $this->actingAs($this->manager, 'manager');
    $batch = ExamReadiness::createBatch('2026-10-20 16:30', null, null, [$this->young->id], $this->manager);

    Livewire::test(ExamReadinessList::class, ['role' => 'manager'])
        ->set('selected', [(string) $this->expected->id])
        ->set('targetBatchId', $batch->id)
        ->assertSee('تنبيه: جاهزية')
        ->assertSee('موعد هذه الدفعة')
        ->call('addToBatch');

    expect($this->expected->fresh()->exam_batch_id)->toBe($batch->id);
});

it('prints a batch\'s names and exports them as CSV, by stage and circle', function () {
    $this->actingAs($this->manager, 'manager');
    $batch = ExamReadiness::createBatch('2026-10-20 16:30', 'قاعة الجمعية', null, [$this->young->id, $this->ready->id], $this->manager);
    ExamReadiness::recordResult($this->ready->fresh(), 'passed', '97');

    Livewire::test(ExamBatches::class)
        ->assertSee(route('manager.exam-batches.print', $batch), false)
        ->assertSee(route('manager.exam-batches.csv', $batch), false);

    $this->get(route('manager.exam-batches.print', $batch))
        ->assertSuccessful()
        ->assertSee('كشف أسماء دفعة الاختبار')
        ->assertSee('قاعة الجمعية')
        ->assertSeeInOrder(['سالم', 'المتوسطة', 'تم التجاوز', '97', 'يوسف', 'الابتدائية']);

    $csv = $this->get(route('manager.exam-batches.csv', $batch))->assertSuccessful()->streamedContent();
    $lines = array_map('str_getcsv', explode("\n", trim(substr($csv, 3))));

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($lines[0])->toBe(['م', 'اسم الطالب', 'المرحلة', 'الحلقة', 'مستوى الاختبار', 'المعلم المرشِّح', 'الحالة', 'الدرجة'])
        ->and(array_column(array_slice($lines, 1), 1))->toBe(['سالم', 'يوسف'])
        ->and($lines[1][6])->toBe('تم التجاوز');
});

it('keeps a batch\'s names from anyone but the manager', function () {
    $batch = ExamReadiness::createBatch('2026-10-20 16:30', null, null, [$this->young->id], $this->manager);
    $this->actingAs(Supervisor::factory()->create(), 'supervisor');

    $this->get(route('manager.exam-batches.csv', $batch))->assertRedirect();
    $this->get(route('manager.exam-batches.print', $batch))->assertRedirect();
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
        ->assertSee('دفعات الاختبارات')
        ->assertSee('جاهزون للاختبار')
        ->assertSee('الدفعات');
});

it('keeps each stage whole on the sheet when two stages share a place', function () {
    $this->primary->update(['position' => 1]);
    $circle = fn (string $name, Stage $stage) => Circle::factory()->create(['name' => $name, 'stage_id' => $stage->id]);
    $put = fn (string $name, Circle $circle) => ExamReadiness::nominate(Student::factory()->create(['name' => $name, 'circle_id' => $circle->id]), $this->level->id, null, $this->teacher);

    // Circle names that would interleave the two stages if sorted by name alone.
    $exams = [
        $put('أ', $circle('حلقة ١', $this->middle)),
        $put('ب', $circle('حلقة ٢', $this->primary)),
        $put('ج', $circle('حلقة ٣', $this->middle)),
    ];
    $batch = ExamReadiness::createBatch('2026-10-20 16:30', null, null, array_map(fn ($exam) => $exam->id, $exams), $this->manager);

    $stages = $batch->roster()->map(fn ($exam) => $exam->student->circle->stage_id)->all();

    expect($stages)->toBe([$this->middle->id, $this->middle->id, $this->primary->id]);
});
