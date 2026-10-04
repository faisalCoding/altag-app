<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Guardian;
use App\Models\StudentPlanDay;
use App\Services\GuardianNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('guardian:weekly-digest')]
#[Description('Record a weekly progress digest notification for every guardian with linked children.')]
class GuardianWeeklyDigest extends Command
{
    public function handle(): int
    {
        $weekStart = now()->startOfWeek(Carbon::SATURDAY);
        $count = 0;

        Guardian::with('students')->whereHas('students')->chunkById(100, function ($guardians) use ($weekStart, &$count) {
            foreach ($guardians as $guardian) {
                $studentIds = $guardian->students->pluck('id')->all();

                // Batched lookups for all of this guardian's children.
                $attendance = Attendance::whereIn('student_id', $studentIds)
                    ->whereBetween('date', [$weekStart, now()])
                    ->get()
                    ->groupBy('student_id');

                $lastScored = StudentPlanDay::query()
                    ->select('student_plan_days.*', 'student_plans.student_id as guardian_sid')
                    ->join('student_plans', 'student_plan_days.student_plan_id', '=', 'student_plans.id')
                    ->whereIn('student_plans.student_id', $studentIds)
                    ->whereNotNull('student_plan_days.hifz_achievement')
                    ->orderByDesc('student_plan_days.date')
                    ->orderByDesc('student_plan_days.id')
                    ->get()
                    ->unique('guardian_sid')
                    ->keyBy('guardian_sid');

                $lines = [];
                $sender = null;

                foreach ($guardian->students as $student) {
                    $sender ??= GuardianNotificationService::resolveWhatsappSender($student);

                    $weekAttend = $attendance->get($student->id) ?? collect();
                    $present = $weekAttend->whereIn('status', ['present', 'late'])->count();
                    $total = $weekAttend->count();

                    $scoreLabel = match ($lastScored->get($student->id)?->hifz_achievement) {
                        3 => 'ممتاز',
                        2 => 'جيد',
                        1 => 'مقبول',
                        0 => 'لم يسمع',
                        default => 'لا يوجد',
                    };

                    $pages = $student->memorizedPagesCount();

                    $lines[] = "• {$student->name}: الحضور {$present}/{$total} هذا الأسبوع، آخر تقييم: {$scoreLabel}، المحفوظ: {$pages} صفحة.";
                }

                GuardianNotificationService::record(
                    guardianId: $guardian->id,
                    type: 'weekly_digest',
                    title: 'الملخّص الأسبوعي لأبنائكم',
                    body: implode("\n", $lines),
                    data: ['week_start' => $weekStart->toDateString()],
                    senderClientId: $sender,
                );

                $count++;
            }
        });

        $this->info("Recorded weekly digest for {$count} guardian(s).");

        return self::SUCCESS;
    }
}
