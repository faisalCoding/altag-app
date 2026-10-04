<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Circle;
use App\Models\Hadith;
use App\Models\OdeVerse;
use App\Models\Student;
use App\Models\StudentHadithAchievement;
use App\Models\StudentOdeAchievement;
use App\Models\StudentPlan;
use App\Support\TasmeehPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The tasmeeh card's data, as JSON.
 *
 * The card used to arrive as server-rendered HTML carrying every day of every
 * plan at once — 2.4 MB for one student, two thirds of it the hadith texts
 * embedded in each day's modal. The same days as JSON are a few kilobytes,
 * so the browser fetches the days and renders them itself, and the long texts
 * are only fetched when a teacher actually opens one.
 *
 * Range wording and grade values stay server-side and travel pre-formatted, so
 * the client never reimplements how a range reads.
 */
class TasmeehDataController extends Controller
{
    /**
     * Every plan day of one student, across Quran, odes and mutun.
     */
    public function days(Student $student): JsonResponse
    {
        $this->authorizeStudent($student);

        return response()->json([
            'student' => ['id' => $student->id, 'name' => $student->name],
            'quran_plans' => $this->quranPlans($student),
            'ode_days' => $this->odeDays($student),
            'hadith_days' => $this->hadithDays($student),
        ]);
    }

    /**
     * How much of what came before a day travels with it. The ode modal already
     * capped this at five; the hadith modal capped nothing and carried every
     * preceding hadith in the book, so a day late in a text weighed far more
     * than a day early in it.
     */
    private const PREVIOUS_LIMIT = 5;

    /**
     * The full text behind a day, fetched only when the teacher opens it.
     */
    public function text(Request $request, Student $student): JsonResponse
    {
        $this->authorizeStudent($student);

        // "id" is a path day id, the same identifier the grading actions use.
        $validated = $request->validate([
            'kind' => 'required|in:ode,hadith',
            'id' => 'required|integer',
            'part' => 'required|in:hifz,review',
        ]);

        return response()->json(
            $validated['kind'] === 'ode'
                ? $this->odeText($student, (int) $validated['id'], $validated['part'])
                : $this->hadithText($student, (int) $validated['id'], $validated['part'])
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function quranPlans(Student $student): array
    {
        return StudentPlan::where('student_id', $student->id)
            ->with(['days' => fn ($q) => $q->orderBy('date')])
            ->with('days.fromAyah.surah', 'days.toAyah.surah', 'days.reviewFromAyah.surah', 'days.reviewToAyah.surah', 'days.hifzRecitedFromAyah.surah', 'days.hifzRecitedToAyah.surah', 'days.reviewRecitedFromAyah.surah', 'days.reviewRecitedToAyah.surah')
            ->latest()
            ->get()
            ->map(fn (StudentPlan $plan) => [
                'id' => $plan->id,
                'type' => $plan->plan_type,
                'start_date' => $plan->start_date?->format('Y-m-d'),
                'days' => TasmeehPayload::quranDays($plan->days),
            ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function odeDays(Student $student): array
    {
        return StudentOdeAchievement::whereHas('plan', fn ($q) => $q->where('student_id', $student->id)->where('status', 'active'))
            ->with('pathDay')
            ->get()
            ->sortBy(fn ($a) => $a->pathDay?->day_number)
            ->map(fn (StudentOdeAchievement $achievement) => [
                'id' => $achievement->ode_path_day_id,
                'achievement_id' => $achievement->id,
                'day_number' => $achievement->pathDay?->day_number,
                'date' => $achievement->pathDay?->date?->format('Y-m-d'),
                'day_name' => $achievement->pathDay?->day_name,
                'hifz' => [
                    'range' => $achievement->formatOdeRange('hifz'),
                    'achievement' => $achievement->hifz_achievement,
                ],
                'review' => [
                    'range' => $achievement->formatOdeRange('review'),
                    'achievement' => $achievement->review_achievement,
                ],
            ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function hadithDays(Student $student): array
    {
        return StudentHadithAchievement::whereHas('plan', fn ($q) => $q->where('student_id', $student->id)->where('status', 'active'))
            ->with('pathDay')
            ->get()
            ->sortBy(fn ($a) => $a->pathDay?->day_number)
            ->map(fn (StudentHadithAchievement $achievement) => [
                'id' => $achievement->hadith_path_day_id,
                'achievement_id' => $achievement->id,
                'day_number' => $achievement->pathDay?->day_number,
                'date' => $achievement->pathDay?->date?->format('Y-m-d'),
                'day_name' => $achievement->pathDay?->day_name,
                'hifz' => [
                    'range' => $achievement->formatHadithRange('hifz'),
                    'achievement' => $achievement->hifz_achievement,
                ],
                'review' => [
                    'range' => $achievement->formatHadithRange('review'),
                    'achievement' => $achievement->review_achievement,
                ],
            ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function odeText(Student $student, int $pathDayId, string $part): array
    {
        $achievement = StudentOdeAchievement::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
            ->where('ode_path_day_id', $pathDayId)
            ->with('pathDay', 'plan.path')
            ->firstOrFail();

        $day = $achievement->pathDay;
        $from = $part === 'review' ? $day?->review_from_verse_number : $day?->from_verse_number;
        $to = $part === 'review' ? $day?->review_to_verse_number : $day?->to_verse_number;

        $verses = ($from && $to)
            ? OdeVerse::where('ode_id', $achievement->plan?->path?->ode_id)
                ->whereBetween('verse_number', [min($from, $to), max($from, $to)])
                ->orderBy('verse_number')
                ->get(['verse_number', 'sadr', 'ajuz'])
            : collect();

        $previous = $from > 1
            ? OdeVerse::where('ode_id', $achievement->plan?->path?->ode_id)
                ->where('verse_number', '<', $from)
                ->orderByDesc('verse_number')
                ->limit(self::PREVIOUS_LIMIT)
                ->get(['verse_number', 'sadr', 'ajuz'])
                ->sortBy('verse_number')
                ->values()
            : collect();

        $shape = fn ($v) => ['number' => $v->verse_number, 'sadr' => $v->sadr, 'ajuz' => $v->ajuz];

        return [
            'title' => $achievement->formatOdeRange($part),
            'verses' => $verses->map($shape)->values()->all(),
            'previous' => $previous->map($shape)->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hadithText(Student $student, int $pathDayId, string $part): array
    {
        $achievement = StudentHadithAchievement::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))
            ->where('hadith_path_day_id', $pathDayId)
            ->with('pathDay', 'plan.path')
            ->firstOrFail();

        $day = $achievement->pathDay;
        $fromId = $part === 'review' ? $day?->review_from_hadith_id : $day?->from_hadith_id;
        $toId = $part === 'review' ? $day?->review_to_hadith_id : $day?->to_hadith_id;
        $fromLine = $part === 'review' ? $day?->review_from_line_number : $day?->from_line_number;
        $toLine = $part === 'review' ? $day?->review_to_line_number : $day?->to_line_number;

        $hadiths = collect();
        $previous = collect();

        if ($fromId) {
            $textId = $achievement->plan?->path?->hadith_text_id;

            $ordered = Hadith::with('lines')
                ->where(function ($query) use ($textId) {
                    $query->where('hadith_text_id', $textId)
                        ->orWhereHas('chapter', fn ($q) => $q->where('hadith_text_id', $textId));
                })
                ->orderBy('hadith_chapter_id')
                ->orderBy('id')
                ->get();

            $ids = $ordered->pluck('id')->values();
            $start = $ids->search($fromId);
            $end = $toId ? $ids->search($toId) : $start;

            if ($start !== false) {
                $end = $end === false ? $start : $end;
                $hadiths = $ordered->slice(min($start, $end), abs($end - $start) + 1);
                $previous = $ordered->slice(max(0, $start - self::PREVIOUS_LIMIT), min($start, self::PREVIOUS_LIMIT));
            }
        }

        $shape = fn (Hadith $hadith) => [
            'name' => $hadith->name,
            'sanad' => $hadith->sanad,
            'ruling' => $hadith->ruling,
            'lines' => $hadith->lines
                ->when($fromLine && $toLine && $hadith->id === $fromId,
                    fn ($lines) => $lines->whereBetween('line_number', [$fromLine, $toLine]))
                ->sortBy('line_number')
                ->map(fn ($line) => ['number' => $line->line_number, 'text' => $line->text])
                ->values()->all(),
        ];

        return [
            'title' => $achievement->formatHadithRange($part),
            'hadiths' => $hadiths->map($shape)->values()->all(),
            'previous' => $previous->map($shape)->values()->all(),
        ];
    }

    /**
     * A teacher may only reach students in their own circles.
     */
    private function authorizeStudent(Student $student): void
    {
        $teacher = Auth::guard('teacher')->user();

        abort_unless(
            $teacher
            && $student->circle_id
            && Circle::where('id', $student->circle_id)
                ->whereHas('teachers', fn ($q) => $q->whereKey($teacher->id))
                ->exists(),
            403,
        );
    }
}
