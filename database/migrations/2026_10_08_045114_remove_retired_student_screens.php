<?php

use App\Models\Screen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The student's hifz, review, calendar and programme-schedule pages are gone:
 * what is due and what was done now sits atop the student's home page. Their
 * screens go with them (the role grants cascade), and the grading notices
 * already sent, which opened the hifz page, now open the home page.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $routes = ['student.hifz', 'student.review', 'student.calendar', 'student.schedule'];

    public function up(): void
    {
        Screen::whereIn('route_name', $this->routes)->delete();

        foreach (['/student/hifz', '/student/review', '/student/calendar', '/student/schedule'] as $path) {
            DB::table('app_notifications')
                ->where('url', 'like', '%'.$path)
                ->update(['url' => DB::raw("replace(url, '{$path}', '/student/dashboard')")]);
        }
    }

    public function down(): void
    {
        // Not meaningfully reversible: the pages themselves were removed.
    }
};
