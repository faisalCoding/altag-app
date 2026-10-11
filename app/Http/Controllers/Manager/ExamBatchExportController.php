<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\ExamBatch;
use App\Models\StudentExam;
use App\Services\ExamReadiness;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A batch's names off the screen: a sheet to print for the day of the exam,
 * and a CSV that opens in Excel.
 */
class ExamBatchExportController extends Controller
{
    public function print(ExamBatch $batch): View
    {
        return view('manager.exam-batch-print', [
            'batch' => $batch,
            'when' => ExamReadiness::when($batch),
            'roster' => $batch->roster(),
        ]);
    }

    public function csv(ExamBatch $batch): StreamedResponse
    {
        $roster = $batch->roster();

        return response()->streamDownload(function () use ($roster) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders Arabic correctly.
            fputcsv($out, ['م', 'اسم الطالب', 'المرحلة', 'الحلقة', 'مستوى الاختبار', 'المعلم المرشِّح', 'الحالة', 'الدرجة']);

            foreach ($roster as $index => $exam) {
                fputcsv($out, array_map(fn ($value) => is_string($value) ? trim($value) : $value, [
                    $index + 1,
                    $exam->student?->name,
                    $exam->student?->circle?->stage?->name,
                    $exam->student?->circle?->name,
                    $exam->examLevel?->name,
                    $exam->nominator?->name,
                    StudentExam::STATUS_LABELS[$exam->status] ?? $exam->status,
                    $exam->score_percentage,
                ]));
            }

            fclose($out);
        }, 'exam-batch-'.$batch->date_time->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
