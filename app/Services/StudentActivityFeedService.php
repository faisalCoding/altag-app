<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\GamificationNews;
use App\Models\Leaderboard;
use App\Models\Student;
use App\Models\StudentExam;
use App\Models\StudentPlanDay;
use Illuminate\Support\Carbon;

class StudentActivityFeedService
{
    /**
     * The student's own recent activity, merged from grading, attendance, exams,
     * and (when an active leaderboard exists) their own gamification news —
     * sorted newest-first and capped to $limit. No single source of truth exists
     * for this, so it is assembled here.
     *
     * @return array<int, array{type: string, icon: string, title: string, date: Carbon}>
     */
    public static function recentActivity(Student $student, ?Leaderboard $leaderboard = null, int $limit = 8): array
    {
        $items = collect();

        StudentPlanDay::with(['fromAyah.surah', 'toAyah.surah', 'reviewFromAyah.surah', 'reviewToAyah.surah'])
            ->whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
            // Only recited parts: a «لم يسمع» (0) is graded but nothing was memorised or reviewed.
            ->where(function ($q) {
                $q->where(fn ($q) => $q->whereNotNull('hifz_graded_at')->recited('hifz'))
                    ->orWhere(fn ($q) => $q->whereNotNull('review_graded_at')->recited('review'));
            })
            ->orderByDesc('hifz_graded_at')
            ->orderByDesc('review_graded_at')
            ->limit($limit)
            ->get()
            ->each(function (StudentPlanDay $day) use ($items) {
                if ($day->hifz_graded_at && $day->isRecited('hifz')) {
                    $items->push([
                        'type' => 'hifz',
                        'icon' => 'book-open',
                        'title' => 'حفظت '.($day->formatRange('hifz') ?? 'مقطعاً جديداً'),
                        'date' => Carbon::parse($day->hifz_graded_at),
                    ]);
                }
                if ($day->review_graded_at && $day->isRecited('review')) {
                    $items->push([
                        'type' => 'review',
                        'icon' => 'arrow-path',
                        'title' => 'راجعت '.($day->formatRange('review') ?? 'مقطعاً'),
                        'date' => Carbon::parse($day->review_graded_at),
                    ]);
                }
            });

        Attendance::where('student_id', $student->id)
            ->where('status', 'present')
            ->orderByDesc('date')
            ->limit($limit)
            ->get(['date'])
            ->each(fn (Attendance $a) => $items->push([
                'type' => 'attendance',
                'icon' => 'check-circle',
                'title' => 'حضرت الحلقة',
                'date' => Carbon::parse($a->date),
            ]));

        StudentExam::where('student_id', $student->id)
            ->where('status', 'completed')
            ->orderByDesc('date_time')
            ->limit($limit)
            ->get(['date_time'])
            ->each(fn (StudentExam $exam) => $items->push([
                'type' => 'exam',
                'icon' => 'academic-cap',
                'title' => 'أنهيت اختباراً',
                'date' => Carbon::parse($exam->date_time),
            ]));

        if ($leaderboard) {
            GamificationNews::where('leaderboard_id', $leaderboard->id)
                ->whereIn('type', ['badge', 'level_up'])
                ->orderByDesc('created_at')
                ->limit($limit * 2)
                ->get()
                ->filter(fn (GamificationNews $news) => (int) ($news->data['student_id'] ?? 0) === $student->id)
                ->each(fn (GamificationNews $news) => $items->push([
                    'type' => $news->type,
                    'icon' => $news->type === 'badge' ? 'trophy' : 'sparkles',
                    'title' => $news->type === 'badge'
                        ? 'حصلت على وسام '.($news->data['badge_name'] ?? '')
                        : 'وصلت إلى '.($news->data['level_name'] ?? 'مستوى جديد'),
                    'date' => Carbon::parse($news->created_at),
                ]));
        }

        return $items
            ->sortByDesc(fn ($item) => $item['date']->timestamp)
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Real events from the student's circle-mates within the same active
     * leaderboard, for the "community" section — not a general social feed.
     *
     * @return array<int, array{title: string, date: Carbon}>
     */
    public static function circleNews(Student $student, ?Leaderboard $leaderboard, int $limit = 6): array
    {
        if (! $leaderboard) {
            return [];
        }

        return GamificationNews::where('leaderboard_id', $leaderboard->id)
            ->whereIn('type', ['badge', 'level_up'])
            ->where('data->student_id', '!=', $student->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (GamificationNews $news) => [
                'student_name' => $news->data['student_name'] ?? '',
                'title' => $news->type === 'badge'
                    ? 'حصل على وسام '.($news->data['badge_name'] ?? '')
                    : 'وصل إلى '.($news->data['level_name'] ?? 'مستوى جديد'),
                'icon' => $news->type === 'badge' ? '🏆' : '⭐',
                'date' => Carbon::parse($news->created_at),
            ])
            ->all();
    }
}
