<?php

namespace App\Console\Commands;

use App\Models\FreeRecitation;
use App\Models\GamificationTransaction;
use App\Models\Student;
use App\Models\StudentHadithAchievement;
use App\Models\StudentOdeAchievement;
use App\Models\StudentPlanDay;
use App\Services\GamificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gamification:diagnose-student-points {student : Student id}')]
#[Description('Explain why a student is or is not earning hifz/review gamification points.')]
class DiagnoseStudentPoints extends Command
{
    public function handle(): int
    {
        $student = Student::find($this->argument('student'));

        if (! $student) {
            $this->error('الطالب غير موجود.');

            return self::FAILURE;
        }

        $this->info("الطالب: {$student->name} (#{$student->id})");
        $this->line('الحلقة: '.($student->circle_id ?? 'بلا حلقة').' | المرحلة المباشرة: '.($student->stage_id ?? '—'));

        if (! $student->circle_id) {
            $this->warn('✗ الطالب بلا حلقة → لا يُطابق أي مسار تلعيب. النقاط تُمنح فقط لطلاب الحلقات.');

            return self::SUCCESS;
        }

        $boards = GamificationService::getActiveLeaderboards($student);
        $this->line('مسارات تلعيب نشطة اليوم تغطّي حلقته: '.$boards->count());

        if ($boards->isEmpty()) {
            $this->warn('✗ لا يوجد مسار تلعيب نشط يغطّي حلقة الطالب اليوم (تحقّق من is_active/التواريخ/ضمّ الحلقة للمسار).');

            return self::SUCCESS;
        }

        foreach ($boards as $board) {
            $s = $board->settings ?? [];
            $hifz = ! empty($s['hifz_enabled']);
            $review = ! empty($s['review_enabled']);
            $manual = ! empty($s['manual_claim_enabled']);
            $this->line(" - مسار #{$board->id} «{$board->title}»: "
                .'حفظ مُفعّل='.($hifz ? 'نعم' : 'لا')
                .' | مراجعة مُفعّلة='.($review ? 'نعم' : 'لا')
                .' | مطالبة يدوية='.($manual ? 'نعم' : 'لا'));

            if (! $hifz && ! $review) {
                $this->warn('   ✗ الحفظ والمراجعة غير مُفعّلين في إعدادات هذا المسار → لا نقاط تسميع.');
            }
        }

        // Break down every memorization source: hifz/review, odes, hadith and free
        // recitations. Quran sources count recited grades only: a «لم يسمع» (0)
        // earns nothing, so it must not look like a grading that lost its points.
        $graded = fn ($q) => $q->whereNotNull('hifz_achievement')->orWhereNotNull('review_achievement');
        $recited = fn ($q) => $q->where('hifz_achievement', '>=', 1)->orWhere('review_achievement', '>=', 1);
        $sources = [
            ['label' => 'الحفظ/المراجعة', 'class' => StudentPlanDay::class,
                'graded' => StudentPlanDay::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))->where($recited)->count()],
            ['label' => 'المنظومات', 'class' => StudentOdeAchievement::class,
                'graded' => StudentOdeAchievement::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))->where($graded)->count()],
            ['label' => 'الأحاديث', 'class' => StudentHadithAchievement::class,
                'graded' => StudentHadithAchievement::whereHas('plan', fn ($q) => $q->where('student_id', $student->id))->where($graded)->count()],
            ['label' => 'تسميع حر', 'class' => FreeRecitation::class,
                'graded' => FreeRecitation::where('student_id', $student->id)->recited()->count()],
        ];

        $totalGraded = 0;
        $totalTx = 0;
        $totalPending = 0;
        foreach ($sources as $source) {
            $tx = GamificationTransaction::where('student_id', $student->id)->where('reference_type', $source['class']);
            $cnt = (clone $tx)->count();
            $pending = (clone $tx)->whereNull('claimed_at')->count();
            $totalGraded += $source['graded'];
            $totalTx += $cnt;
            $totalPending += $pending;
            $this->line("- {$source['label']}: مُقيَّم {$source['graded']} | معاملات نقاط {$cnt} (بانتظار المطالبة {$pending})");
        }

        $this->newLine();
        if ($totalGraded > 0 && $totalTx === 0) {
            $this->warn('الخلاصة: توجد تقييمات بلا معاملات — تواريخ التقييم خارج نطاق المسابقة، أو لم يُعَد الاحتساب. شغّل: php artisan gamification:recompute-plan-day-points');
        } elseif ($totalPending > 0) {
            $this->warn("الخلاصة: {$totalPending} معاملة «بانتظار المطالبة» (المطالبة اليدوية مفعّلة) — على الطالب الضغط على «مطالبة» لتُضاف لرصيده، أو عطّل المطالبة اليدوية من إعدادات المسار.");
        } elseif ($totalTx > 0) {
            $this->info('الخلاصة: النقاط محتسبة ومُطالَب بها بشكل صحيح لهذا الطالب.');
        } else {
            $this->warn('الخلاصة: لا توجد تقييمات لهذا الطالب بعد.');
        }

        return self::SUCCESS;
    }
}
