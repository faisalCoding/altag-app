<?php

use App\Http\Controllers\Manager\BackupController;
use App\Http\Controllers\Student\StudentPlanPrintController;
use App\Http\Controllers\Teacher\TasmeehDataController;
use App\Livewire\Auth\Student\Register;
use App\Livewire\Manager\PendingApprovals;
use App\Livewire\Public\CircleReport as PublicCircleReport;
use App\Livewire\Public\CoinRedemption as PublicCoinRedemption;
use App\Livewire\Public\FormReport;
use App\Livewire\Public\FormSubmit;
use App\Livewire\Public\ResultsDisplay as PublicResultsDisplay;
use App\Models\Circle;
use App\Models\FormAssignment;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentPlan;
use App\Models\Supervisor;
use App\Models\Surah;
use App\Models\Teacher;
use App\Services\MessagingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf;

Route::get('/', function () {
    $stats = [
        'students' => Student::count(),
        'teachers' => Teacher::count(),
        'circles' => Circle::count(),
    ];

    return view('welcome', compact('stats'));
})->name('home');

Route::get('/pending-approval', fn () => view('pending-approval'))
    ->middleware('auth:manager,supervisor,teacher,student,guardian,staff')
    ->name('pending-approval');

Route::post('logout', function (Request $request) {
    $guard = request()->route('guard');

    if ($guard) {
        auth()->guard($guard)->logout();
    } else {
        $guards = ['student', 'manager', 'supervisor', 'teacher', 'guardian', 'staff', 'web'];

        foreach ($guards as $guard) {
            if (auth()->guard($guard)->check()) {
                auth()->guard($guard)->logout();
            }
        }
    }

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('home');
})->name('logout');

// Lets a user who is already authenticated under one guard switch, with one
// click and no password, into another guard for the same account — now that
// all roles live on one `users` row, "switching role" just means logging
// the same row into another guard, after confirming (server-side) that a
// `user_roles` entry for that role actually exists.
Route::post('/switch-role/{guard}', function (string $guard) {
    if (! array_key_exists($guard, MessagingService::MODELS)) {
        abort(404);
    }

    $currentUser = collect(array_keys(MessagingService::MODELS))
        ->map(fn ($g) => auth()->guard($g)->user())
        ->filter()
        ->first();

    if (! $currentUser || ! $currentUser->hasRole($guard)) {
        abort(403);
    }

    if (! auth()->guard($guard)->check() || auth()->guard($guard)->id() !== $currentUser->id) {
        $modelClass = MessagingService::MODELS[$guard];
        $target = $modelClass::findOrFail($currentUser->id);
        auth()->guard($guard)->login($target);
    }

    return redirect()->route("{$guard}.dashboard");
})->middleware('auth:manager,supervisor,teacher,student,guardian,staff')->name('switch-role');

$roles = [
    'manager' => 'مدير',
    'supervisor' => 'مشرف',
    'teacher' => 'معلم',
    'student' => 'طالب',
    'guardian' => 'ولي أمر',
    'staff' => 'موظف',
];

Route::middleware('auth:manager,supervisor,teacher,student,guardian,staff')->group(function () use ($roles) {
    Route::get('dashboard', function () use ($roles) {
        foreach (array_keys($roles) as $roleKey) {
            if (auth()->guard($roleKey)->check()) {
                return redirect()->route("{$roleKey}.dashboard");
            }
        }

        return redirect()->route('home');
    })->name('dashboard');
});

Route::middleware(['auth:manager', 'approved', 'page.enabled'])->prefix('manager')->name('manager.')->group(function () {
    Route::livewire('/pending-approvals', PendingApprovals::class)->name('pending-approvals');
    Route::view('/stages', 'manager.stages')->name('stages');
    Route::view('/circles', 'manager.circles')->name('circles');
    Route::view('/promotion-ladder', 'manager.promotion-ladder')->name('promotion-ladder');
    Route::view('/promotions', 'manager.promotions')->name('promotions');
    Route::view('/supervisors', 'manager.supervisors')->name('supervisors');
    Route::view('/teachers', 'manager.teachers')->name('teachers');
    Route::view('/students', 'manager.students')->name('students');
    Route::view('/guardians', 'manager.guardians')->name('guardians');
    Route::view('/attendance-reports', 'manager.attendance-reports')->name('attendance-reports');
    Route::view('/yearly-attendance', 'manager.yearly-attendance')->name('yearly-attendance');
    Route::view('/academic-calendar', 'manager.academic-calendar')->name('academic-calendar');
    Route::view('/quranic-achievement', 'manager.quranic-achievement-report')->name('quranic-achievement');
    Route::view('/attendance/{circleId}/{date}', 'manager.student-attendance-list')->name('attendance-list');
    Route::view('/ai-analysis', 'manager.ai-analysis')->name('ai-analysis');
    Route::view('/ai-settings', 'manager.ai-settings')->name('ai-settings');
    Route::view('/quran-editor', 'manager.quran-editor')->name('quran-editor');
    Route::view('/settings', 'manager.settings')->name('settings');
    Route::view('/whatsapp-settings', 'manager.whatsapp-settings')->name('whatsapp-settings');
    Route::view('/exceeded-limits', 'manager.exceeded-limits')->name('exceeded-limits');
    Route::view('/backups/{filename}', 'manager.backup-browser')->name('backup-browser');
    Route::view('/exam-levels', 'manager.exam-levels')->name('exam-levels');
    Route::view('/student-exams', 'manager.student-exams')->name('student-exams');
    Route::view('/tasks', 'manager.tasks')->name('tasks');
    Route::view('/api-docs', 'manager.api-docs')->name('api-docs');
    Route::view('/messages', 'manager.messages')->name('messages');
    Route::view('/role-permissions', 'manager.role-permissions')->name('role-permissions');
    Route::view('/staff-members', 'manager.staff-members')->name('staff-members');
    Route::view('/forms', 'manager.forms')->name('forms');
    Route::get('/forms/create', fn () => view('manager.form-create'))->name('forms.create');
    Route::get('/forms/{id}/edit', fn ($id) => view('manager.form-edit', ['formId' => $id]))->name('forms.edit');
    Route::get('/forms/{id}/responses', fn ($id) => view('manager.form-responses', ['formId' => $id]))->name('forms.responses');
    Route::view('/guide', 'shared.guide')->name('guide');
});

// تسجيل حساب جديد: صفحة عامة واحدة، كل التسجيلات الذاتية تُنشأ كطالب بانتظار
// موافقة المشرف، اللي بعدين يقدر يغيّر نوع الحساب من صفحة طلبات التسجيل.
Route::middleware('guest:manager,supervisor,teacher,student,guardian')
    ->get('/register', Register::class)
    ->name('register');

// مسارات لوحة التحكم (Dashboard Routes) لكل دور
Route::middleware(['auth:manager', 'approved'])->get('/manager/dashboard', fn () => view('manager.dashboard'))->name('manager.dashboard');

// تنزيل النسخ الاحتياطية عبر بثّ HTTP عادي (خارج دورة Livewire) لتفادي تحميل الملف كاملاً في الذاكرة.
Route::middleware(['auth:manager', 'approved'])->prefix('manager')->name('manager.')->group(function () {
    Route::get('/backup/download', [BackupController::class, 'downloadCurrent'])->name('backup.download');
    Route::get('/backup/download/{filename}', [BackupController::class, 'downloadStored'])->name('backup.download.stored');
});
Route::middleware(['auth:supervisor', 'approved', 'page.enabled', 'surveys.required'])->prefix('supervisor')->name('supervisor.')->group(function () {
    Route::get('/dashboard', fn () => view('supervisor.dashboard'))->name('dashboard');
    Route::view('/teachers', 'supervisor.teachers')->name('teachers');
    Route::view('/teacher-attendance', 'supervisor.teacher-attendance')->name('teacher-attendance');
    Route::view('/odes', 'supervisor.odes')->name('odes');
    Route::view('/odes/paths', 'supervisor.ode-paths')->name('odes.paths');
    Route::view('/hadiths', 'supervisor.hadiths')->name('hadiths');
    Route::view('/hadiths/paths', 'supervisor.hadith-paths')->name('hadiths.paths');
    Route::view('/hadiths/create-plan', 'supervisor.hadith-plan-creator')->name('hadiths.create-plan');
    Route::view('/odes/plans', 'supervisor.ode-plans')->name('odes.plans');
    Route::view('/odes/create-plan', 'supervisor.ode-plan-creator')->name('odes.create-plan');
    Route::view('/circles', 'supervisor.circles')->name('circles');
    Route::get('/circles/{circle}/report', fn ($circle) => view('supervisor.circle-report', ['circleId' => $circle]))->name('circles.report');
    Route::get('/stages/{stage}/report', fn ($stage) => view('supervisor.stage-report', ['stageId' => $stage]))->name('stages.report');
    Route::view('/custom-reports', 'supervisor.custom-reports')->name('custom-reports');
    Route::view('/teacher-competitions', 'supervisor.teacher-competitions')->name('teacher-competitions');
    Route::get('/teacher-competitions/{competition}', fn ($competition) => view('supervisor.teacher-competition-manage', ['competitionId' => $competition]))->name('teacher-competitions.manage');
    Route::view('/students', 'supervisor.students')->name('students');
    Route::view('/competitions', 'supervisor.competitions')->name('competitions');
    Route::get('/competitions/{competition}/gamification', fn ($competition) => view('supervisor.gamification', ['competitionId' => $competition]))->name('competitions.gamification');
    Route::get('/competitions/{competition}/standings', fn ($competition) => view('supervisor.competition-standings', ['competitionId' => $competition]))->name('competitions.standings');
    Route::view('/exceeded-limits', 'supervisor.exceeded-limits')->name('exceeded-limits');
    Route::view('/academic-calendar', 'supervisor.academic-calendar')->name('academic-calendar');
    Route::view('/yearly-attendance', 'supervisor.yearly-attendance')->name('yearly-attendance');
    Route::view('/tasks', 'supervisor.tasks')->name('tasks');
    Route::view('/whatsapp-settings', 'supervisor.whatsapp-settings')->name('whatsapp-settings');
    Route::view('/messages', 'supervisor.messages')->name('messages');

    // Forms Builder Routes
    Route::view('/forms', 'supervisor.forms')->name('forms');
    Route::get('/forms/create', fn () => view('supervisor.form-create'))->name('forms.create');
    Route::get('/forms/{id}/edit', fn ($id) => view('supervisor.form-edit', ['formId' => $id]))->name('forms.edit');
    Route::get('/forms/{id}/responses', fn ($id) => view('supervisor.form-responses', ['formId' => $id]))->name('forms.responses');

    Route::view('/guide', 'shared.guide')->name('guide');
});
Route::middleware(['auth:teacher', 'approved', 'page.enabled', 'surveys.required'])->prefix('teacher')->name('teacher.')->group(function () {
    $appShellRoute = function ($tab) {
        return function () use ($tab) {
            return view('teacher.app-shell', ['initialTab' => $tab]);
        };
    };

    Route::get('/dashboard', fn () => view('teacher.dashboard'))->name('dashboard');

    // SPA Routes (5 Tabs)
    Route::get('/attendance', $appShellRoute('attendance'))->name('attendance');
    Route::get('/students', $appShellRoute('students'))->name('students');
    Route::get('/plan-creator', $appShellRoute('plan-creator'))->name('plan-creator');
    Route::get('/tasmeeh', $appShellRoute('tasmeeh'))->name('tasmeeh');
    Route::get('/leaderboards', $appShellRoute('leaderboards'))->name('leaderboards');
    Route::get('/grade-items', $appShellRoute('grade-items'))->name('grade-items');

    // Standard Routes
    Route::view('/discipline', 'teacher.discipline')->name('discipline');
    Route::view('/quranic-discipline', 'teacher.quranic-discipline')->name('quranic-discipline');
    Route::view('/student-plans', 'teacher.student-plans')->name('student-plans');
    Route::view('/ode-plans', 'teacher.ode-plans')->name('ode-plans');
    Route::view('/exceeded-limits', 'teacher.exceeded-limits')->name('exceeded-limits');
    Route::view('/pairs', 'teacher.pairs')->name('pairs');
    Route::view('/student-exams', 'teacher.student-exams')->name('student-exams');
    Route::view('/messages', 'teacher.messages')->name('messages');
    Route::view('/forms', 'teacher.forms')->name('forms');
    Route::get('/forms/create', fn () => view('teacher.form-create'))->name('forms.create');
    Route::get('/forms/{id}/edit', fn ($id) => view('teacher.form-edit', ['formId' => $id]))->name('forms.edit');
    Route::get('/forms/{id}/responses', fn ($id) => view('teacher.form-responses', ['formId' => $id]))->name('forms.responses');
    Route::view('/guide', 'shared.guide')->name('guide');

    Route::get('/student-recitation-log/{studentId}', function ($studentId) {
        return view('teacher.student-recitation-log', ['studentId' => $studentId]);
    })->name('student-recitation-log');

    // The tasmeeh card fetches its days as JSON and renders them in the browser.
    Route::get('/tasmeeh/{student}/days', [TasmeehDataController::class, 'days'])->name('tasmeeh.days');
    Route::get('/tasmeeh/{student}/text', [TasmeehDataController::class, 'text'])->name('tasmeeh.text');

    // Grading page route will be mapped to a view wrapper soon, for now just use view
    Route::get('/leaderboards/{id}/grade', function ($id) {
        return view('teacher.leaderboards-grade', ['leaderboardId' => $id]);
    })->name('leaderboards.grade');

    Route::get('/leaderboards/{id}/report', function ($id) {
        return view('teacher.leaderboards-report', ['leaderboardId' => $id]);
    })->name('leaderboards.report');

    Route::get('/student-plans/{id}/print', function ($id) {
        $plan = StudentPlan::with([
            'student.circle',
            'days.fromAyah.surah',
            'days.toAyah.surah',
            'days.reviewFromAyah.surah',
            'days.reviewToAyah.surah',
        ])->findOrFail($id);

        if (! auth()->guard('teacher')->user()->circles->contains($plan->student->circle_id)) {
            abort(403);
        }

        return view('teacher.print-plan', compact('plan'));
    })->name('print-plan');

    Route::get('/student-plans/{id}/download-pdf', function ($id) {
        $plan = StudentPlan::with([
            'student.circle',
            'days.fromAyah.surah',
            'days.toAyah.surah',
            'days.reviewFromAyah.surah',
            'days.reviewToAyah.surah',
        ])->findOrFail($id);

        if (! auth()->guard('teacher')->user()->circles->contains($plan->student->circle_id)) {
            abort(403);
        }

        $pdf = LaravelMpdf::loadView('pdf.student-plan', compact('plan'), [], [
            'format' => 'A4',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'useSubstitutions' => true,
            'useAdobeCJK' => true,
        ]);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, 'plan_'.$plan->student->name.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    })->name('download-plan-pdf');
});
Route::middleware(['auth:student', 'approved', 'page.enabled', 'surveys.required'])->prefix('student')->name('student.')->group(function () {
    Route::get('/dashboard', fn () => view('student.dashboard'))->name('dashboard');
    Route::view('/plan', 'student.my-plan')->name('plan');
    Route::view('/plan/create', 'student.plan-creator')->name('plan-creator');
    Route::view('/plan/show/{id}', 'student.show-plan')->name('show-plan');

    // A printable plan the student can read and hand in, with each graded day
    // carrying the colour of its grade.
    Route::get('/plan/{kind}/{id}/print', [StudentPlanPrintController::class, 'show'])
        ->whereIn('kind', ['quran', 'ode', 'hadith'])
        ->name('plan.print');
    Route::view('/attendance', 'student.attendance')->name('attendance');
    Route::view('/hifz', 'student.hifz')->name('hifz');
    Route::view('/review', 'student.review')->name('review');
    Route::view('/exams', 'student.exams')->name('exams');
    Route::view('/calendar', 'student.calendar')->name('calendar');
    Route::view('/reports', 'student.reports')->name('reports');
    Route::view('/messages', 'student.messages')->name('messages');
    Route::view('/guide', 'shared.guide')->name('guide');
    Route::get('/settings', function () {
        return view('student.settings-page');
    })->name('settings');
    Route::post('/logout', function (Request $request) {
        auth()->guard('student')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    })->name('logout');
});
Route::view('/student/complete-profile', 'student.complete-profile')->middleware(['auth:student'])->name('student.complete-profile');
Route::view('/teacher/complete-profile', 'teacher.complete-profile')->middleware(['auth:teacher'])->name('teacher.complete-profile');
Route::middleware(['auth:guardian', 'approved', 'page.enabled', 'surveys.required'])->prefix('parent')->name('guardian.')->group(function () {
    Route::get('/dashboard', fn () => view('guardian.dashboard'))->name('dashboard');
    Route::get('/student/{id}', fn ($id) => view('guardian.student', ['studentId' => $id]))->name('student');
    Route::get('/student/{id}/attendance', fn ($id) => view('guardian.student-attendance', ['studentId' => $id]))->name('student.attendance');
    Route::get('/challenges', fn () => view('guardian.challenges'))->name('challenges');
    Route::get('/student/{id}/challenge/create', fn ($id) => view('guardian.create-challenge', ['studentId' => $id]))->name('student.challenge.create');
    Route::view('/messages', 'guardian.messages')->name('messages');
    Route::view('/guide', 'shared.guide')->name('guide');
});

Route::middleware(['auth:staff', 'approved', 'page.enabled', 'surveys.required'])->prefix('staff')->name('staff.')->group(function () {
    Route::view('/dashboard', 'staff.dashboard')->name('dashboard');
    Route::view('/messages', 'staff.messages')->name('messages');
    Route::view('/guide', 'shared.guide')->name('guide');
});

// Magic Link Routes
Route::get('/magic/{token}', function ($token) {
    $student = Student::findByAccessToken($token) ?? abort(404);

    auth()->guard('student')->login($student);

    return redirect()->route('student.dashboard');
})->name('magic-link');

Route::get('/teacher-magic/{token}', function ($token) {
    $teacher = Teacher::findByAccessToken($token) ?? abort(404);

    auth()->guard('teacher')->login($teacher);

    if (! $teacher->is_data_completed) {
        return redirect()->route('teacher.complete-profile');
    }

    if (request()->has('redirect')) {
        return redirect()->to(request()->query('redirect'));
    }

    return redirect()->route('teacher.dashboard');
})->name('teacher.magic-link');

Route::get('/supervisor-magic/{token}', function ($token) {
    $supervisor = Supervisor::findByAccessToken($token) ?? abort(404);

    auth()->guard('supervisor')->login($supervisor);

    return redirect()->route('supervisor.dashboard');
})->name('supervisor.magic-link');

Route::get('/guardian-magic/{token}', function ($token) {
    $guardian = Guardian::findByAccessToken($token) ?? abort(404);

    auth()->guard('guardian')->login($guardian);

    // If you add a complete profile step for guardians later, handle it here.
    return redirect()->route('guardian.dashboard');
})->name('guardian.magic-link');

Route::get('/magic/{token}/login-as', function ($token) {
    if (! auth()->guard('teacher')->check()) {
        abort(403);
    }

    $student = Student::findByAccessToken($token) ?? abort(404);
    auth()->guard('student')->login($student);

    return redirect()->route('student.dashboard');
})->name('magic-link.login-as');

Route::get('/quran-json', function () {
    return response()->json(Surah::with('ayahs')->get(), 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
});

Route::get('/test', function () {})->name('test');

Route::get('/f/{slug}', FormSubmit::class)->name('forms.submit');
Route::get('/f/{slug}/{token}', FormReport::class)->name('forms.report');

// Where the survey gate sends anyone who owes a blocking survey. Reachable from
// behind the gate by design — it is the way through it.
Route::get('/surveys/required', function () {
    $user = collect(['manager', 'supervisor', 'teacher', 'student', 'guardian', 'staff'])
        ->map(fn ($guard) => auth()->guard($guard)->user())
        ->first(fn ($candidate) => $candidate !== null);

    abort_unless($user, 403);

    $assignments = FormAssignment::owedBy($user->id)
        ->with('form')
        ->get()
        ->filter(fn ($a) => $a->form?->blocksTheApp())
        ->values();

    // Nothing owed any more — the gate is open, so do not sit on it.
    if ($assignments->isEmpty()) {
        return redirect('/');
    }

    return view('surveys.required', ['assignments' => $assignments]);
})->middleware('auth:manager,supervisor,teacher,student,guardian,staff')->name('surveys.required');
Route::get('/r/circle-report', PublicCircleReport::class)->name('reports.circle')->middleware('signed');
Route::get('/r/coin-redemption', PublicCoinRedemption::class)->name('redemption.circle')->middleware('signed');
Route::get('/r/results-display', PublicResultsDisplay::class)->name('results.display')->middleware('signed');

require __DIR__.'/settings.php';
