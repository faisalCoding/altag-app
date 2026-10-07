<?php

use App\Jobs\SendGuardianWhatsappJob;
use App\Livewire\Teacher\Attendance;
use App\Models\AcademicCalendarEvent;
use App\Models\Circle;
use App\Models\Guardian;
use App\Models\GuardianNotification;
use App\Models\Leaderboard;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Services\GuardianNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon\Carbon::setTestNow('2026-06-10 10:00:00');

    $this->stage = Stage::factory()->create();
    $this->circle = Circle::factory()->create(['stage_id' => $this->stage->id]);
    $this->teacher = Teacher::factory()->create();
    $this->guardian = Guardian::factory()->create(['is_approved' => true, 'phone' => '0501234567']);
    $this->child = Student::factory()->create([
        'name' => 'الابن الأول',
        'guardian_id' => $this->guardian->id,
        'circle_id' => $this->circle->id,
    ]);
});

function activateWhatsappSender($circle): Supervisor
{
    $supervisor = Supervisor::factory()->create();
    $leaderboard = Leaderboard::create([
        'circle_id' => $circle->id,
        'title' => 'مسابقة',
        'competition_type' => 'gamification',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDays(10),
        'is_active' => true,
        'supervisor_id' => $supervisor->id,
        'settings' => [],
    ]);
    $leaderboard->circles()->attach($circle->id);

    return $supervisor;
}

it('records an in-app absence notification for the guardian', function () {
    $note = GuardianNotificationService::notifyAbsence($this->child, 'absent', '2026-06-10');

    expect($note)->not->toBeNull();
    expect(GuardianNotification::where('guardian_id', $this->guardian->id)->where('type', 'absence')->count())->toBe(1);
    expect($note->student_id)->toBe($this->child->id);
});

it('formats the alert date as hijri weekday, day, and month without a year', function () {
    $note = GuardianNotificationService::notifyAbsence($this->child, 'absent', '2026-06-10');

    $expectedHijri = GuardianNotificationService::formatHijriDayMonth('2026-06-10');

    expect($note->body)->toContain($expectedHijri);
    expect($note->body)->not->toContain('2026-06-10');
    expect($expectedHijri)->not->toContain('١٤٤')->not->toContain('144');
    expect($note->body)->toBe("نشعركم بغياب الطالب ({$this->child->name}) ليوم {$expectedHijri} وذلك للمرة الأولى");
});

it('counts absence occurrences since the start of the current attendance period', function () {
    AcademicCalendarEvent::create([
        'event_name' => 'الفصل الدراسي الحالي',
        'start_date' => '2026-06-01',
        'end_date' => '2026-08-30',
        'is_attendance_period' => true,
        'is_visible' => true,
    ]);

    // Absence before the period must not be counted.
    foreach (['2026-05-20', '2026-06-03', '2026-06-07', '2026-06-10'] as $date) {
        App\Models\Attendance::create([
            'student_id' => $this->child->id,
            'teacher_id' => $this->teacher->id,
            'circle_id' => $this->circle->id,
            'date' => $date,
            'status' => 'absent',
        ]);
    }

    $note = GuardianNotificationService::notifyAbsence($this->child, 'absent', '2026-06-10');

    expect($note->body)->toContain('وذلك للمرة الثالثة');
});

it('sends the WhatsApp message opening with the salam greeting and no title prefix', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config(['services.whatsapp.url' => 'http://wa.test']);
    activateWhatsappSender($this->circle);

    GuardianNotificationService::notifyAbsence($this->child, 'absent', '2026-06-10');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/send')
            && str_starts_with($request['message'], "السلام عليكم ورحمة الله وبركاته\nنشعركم بغياب الطالب");
    });
});

it('does not record a duplicate absence for the same student and date', function () {
    GuardianNotificationService::notifyAbsence($this->child, 'absent', '2026-06-10');
    $second = GuardianNotificationService::notifyAbsence($this->child, 'absent', '2026-06-10');

    expect($second)->toBeNull();
    expect(GuardianNotification::where('student_id', $this->child->id)->count())->toBe(1);
});

it('does nothing for a student without a linked guardian', function () {
    $orphan = Student::factory()->create(['guardian_id' => null, 'circle_id' => $this->circle->id]);

    expect(GuardianNotificationService::notifyAbsence($orphan, 'absent', '2026-06-10'))->toBeNull();
    expect(GuardianNotification::count())->toBe(0);
});

it('pushes a WhatsApp message when a sender session and phone are available', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config(['services.whatsapp.url' => 'http://wa.test']);
    $supervisor = activateWhatsappSender($this->circle);

    GuardianNotificationService::notifyAbsence($this->child, 'absent', '2026-06-10');

    Http::assertSent(function ($request) use ($supervisor) {
        return str_contains($request->url(), '/send')
            && $request['clientId'] === 'supervisor_'.$supervisor->id
            && $request['phone'] === '966501234567';
    });
});

it('does not push WhatsApp when no sender session can be resolved', function () {
    Http::fake();

    GuardianNotificationService::notifyAbsence($this->child, 'absent', '2026-06-10');

    Http::assertNothingSent();
});

it('skips the human pause entirely when the delay config is zero', function () {
    config(['services.whatsapp.send_delay_min' => 0, 'services.whatsapp.send_delay_max' => 0]);

    $start = microtime(true);
    SendGuardianWhatsappJob::humanPause();

    expect(microtime(true) - $start)->toBeLessThan(0.5);
});

it('sends the shared API key header to the WhatsApp gateway', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config(['services.whatsapp.url' => 'http://wa.test']);
    config(['services.whatsapp.key' => 'secret-gateway-key']);
    activateWhatsappSender($this->circle);

    GuardianNotificationService::notifyAbsence($this->child, 'absent', '2026-06-10');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/send')
            && $request->header('X-Api-Key') === ['secret-gateway-key'];
    });
});

it('creates a guardian absence notification when a teacher marks a student absent', function () {
    // The register writes only to the teacher's own circles.
    $this->teacher->circles()->attach($this->circle->id);
    $this->actingAs($this->teacher, 'teacher');

    Livewire::test(Attendance::class)
        ->set('selectedCircle', $this->circle->id)
        ->set('date', '2026-06-10')
        ->call('markStatus', $this->child->id, 'absent');

    expect(GuardianNotification::where('student_id', $this->child->id)->where('type', 'absence')->exists())->toBeTrue();
});

it('records a weekly digest notification for every guardian', function () {
    $this->artisan('guardian:weekly-digest')->assertSuccessful();

    $digest = GuardianNotification::where('guardian_id', $this->guardian->id)->where('type', 'weekly_digest')->first();

    expect($digest)->not->toBeNull();
    expect($digest->body)->toContain('الابن الأول');
});

it('shows notifications and marks them read from the guardian dashboard', function () {
    GuardianNotificationService::notifyAbsence($this->child, 'absent', '2026-06-10');

    $this->actingAs($this->guardian, 'guardian');

    $component = Livewire::test('guardian.dashboard')
        ->assertSee('تنبيه غياب')
        ->assertSee('آخر التنبيهات');

    $component->call('markAllNotificationsRead');

    expect(GuardianNotification::where('guardian_id', $this->guardian->id)->whereNull('read_at')->count())->toBe(0);
});
