<?php

use App\Livewire\Teacher\Attendance;
use App\Models\Attendance as AttendanceModel;
use App\Models\Circle;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-07-08 10:00:00'); // Wednesday, 23 Muharram 1448

    $this->circle = Circle::factory()->create([
        'stage_id' => Stage::factory()->create()->id,
        'name' => 'حلقة الفجر',
    ]);

    $this->teacher = Teacher::factory()->create(['name' => 'عبدالله المعلم']);
    $this->teacher->circles()->attach($this->circle->id);

    $this->actingAs($this->teacher, 'teacher');
});

function recordSummaryStatus(Student $student, string $status): void
{
    AttendanceModel::create([
        'student_id' => $student->id,
        'teacher_id' => test()->teacher->id,
        'circle_id' => test()->circle->id,
        'date' => '2026-07-08',
        'status' => $status,
    ]);
}

it('lists the absent, excused and late students under the circle, teacher and Hijri day', function () {
    $present = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'أحمد الحاضر']);
    $absentA = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'بدر الغائب']);
    $absentB = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'تركي الغائب']);
    $excused = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'خالد المستأذن']);
    $late = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'سعد المتأخر']);

    recordSummaryStatus($present, 'present');
    recordSummaryStatus($absentA, 'absent');
    recordSummaryStatus($absentB, 'absent');
    recordSummaryStatus($excused, 'excused');
    recordSummaryStatus($late, 'late');

    $message = Livewire::test(Attendance::class)->instance()->absenceSummaryMessage();

    expect($message)->toBe(implode("\n", [
        'الحلقة: حلقة الفجر',
        'المعلم: عبدالله المعلم',
        'اليوم: الأربعاء، ٢٣ محرم ١٤٤٨هـ',
        '',
        'الغائبون (٢):',
        '١. بدر الغائب',
        '٢. تركي الغائب',
        '',
        'المستأذنون (١):',
        '١. خالد المستأذن',
        '',
        'المتأخرون (١):',
        '١. سعد المتأخر',
    ]));
});

it('says so when nobody falls under a status', function () {
    $student = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'بدر الغائب']);
    Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'زياد لم يحضر بعد']);

    recordSummaryStatus($student, 'absent');

    $message = Livewire::test(Attendance::class)->instance()->absenceSummaryMessage();

    expect($message)
        ->toContain("الغائبون (١):\n١. بدر الغائب")
        ->toContain("المستأذنون (٠):\nلا يوجد")
        ->toContain("المتأخرون (٠):\nلا يوجد")
        ->not->toContain('زياد');
});

it('follows a status the teacher changes on the page', function () {
    $student = Student::factory()->create(['circle_id' => $this->circle->id, 'name' => 'بدر']);

    $component = Livewire::test(Attendance::class)->call('updateStatus', $student->id, 'late');

    expect($component->instance()->absenceSummaryMessage())->toContain("المتأخرون (١):\n١. بدر");

    $component->call('updateStatus', $student->id, 'present');

    expect($component->instance()->absenceSummaryMessage())->toContain("المتأخرون (٠):\nلا يوجد");
});

it('is empty until a circle is chosen', function () {
    $this->teacher->circles()->attach(Circle::factory()->create()->id);

    expect(Livewire::test(Attendance::class)->instance()->absenceSummaryMessage())->toBe('');
});

it('offers the copy button on the attendance page', function () {
    Student::factory()->create(['circle_id' => $this->circle->id]);

    Livewire::test(Attendance::class)
        ->assertSee('نسخ رسالة الغياب')
        ->assertSeeHtml('data-msg="الحلقة: حلقة الفجر');
});

it('opens the stage\'s WhatsApp group once the message is copied', function () {
    $this->circle->stage->update(['whatsapp_group_url' => 'https://chat.whatsapp.com/StageGroup1234567890']);
    Student::factory()->create(['circle_id' => $this->circle->id]);

    Livewire::test(Attendance::class)
        ->assertSee('نسخ وفتح المجموعة')
        ->assertSeeHtml('href="https://chat.whatsapp.com/StageGroup1234567890"')
        ->assertSeeHtml('target="_blank"');
});

it('opens the circle\'s own group in place of its stage\'s', function () {
    $this->circle->stage->update(['whatsapp_group_url' => 'https://chat.whatsapp.com/StageGroup1234567890']);
    $this->circle->update(['whatsapp_group_url' => 'https://chat.whatsapp.com/CircleGroup123456789']);
    Student::factory()->create(['circle_id' => $this->circle->id]);

    Livewire::test(Attendance::class)
        ->assertSeeHtml('href="https://chat.whatsapp.com/CircleGroup123456789"')
        ->assertDontSeeHtml('StageGroup1234567890');
});

it('only copies when the supervisor has set no group', function () {
    Student::factory()->create(['circle_id' => $this->circle->id]);

    Livewire::test(Attendance::class)
        ->assertSee('نسخ رسالة الغياب')
        ->assertDontSeeHtml('chat.whatsapp.com')
        ->assertDontSeeHtml('target="_blank"');
});

/**
 * On a phone the three actions share one row: «نسخ وفتح المجموعة» made it
 * wider than the screen and pushed the whole page sideways.
 */
it('gives the attendance actions short labels on a phone so the row fits', function () {
    $this->circle->stage->update(['whatsapp_group_url' => 'https://chat.whatsapp.com/StageGroup1234567890']);
    Student::factory()->create(['circle_id' => $this->circle->id]);

    Livewire::test(Attendance::class)
        ->assertSeeHtml('class="flex flex-wrap items-center gap-2 w-full sm:w-auto"')
        ->assertSeeHtml('<span class="sm:hidden">نسخ وفتح</span>')
        ->assertSeeHtml('<span class="sm:hidden">حذف</span>');
});
