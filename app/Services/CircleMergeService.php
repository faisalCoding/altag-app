<?php

namespace App\Services;

use App\Models\Circle;
use App\Models\PromotionRun;
use App\Models\PromotionRunItem;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * Folding one circle's students into another.
 *
 * Recorded as a run so it can be undone the same way a promotion can: the
 * circle each student came from is written down before they leave it, which is
 * the only thing that distinguishes a merge from the students having always
 * been there.
 *
 * The emptied circle is deliberately left standing. Its attendance register,
 * its revisions and its competitions all hang off it with a cascading delete,
 * so removing it would take years of history with it — a merge empties a
 * circle, it does not erase one.
 */
class CircleMergeService
{
    /**
     * Move every student of $source into $target.
     *
     * @param  array<int, int>|null  $allowedCircleIds  when given, both circles must be inside it
     */
    public function merge(Circle $source, Circle $target, ?int $byUserId = null, ?array $allowedCircleIds = null): PromotionRun
    {
        if ($source->id === $target->id) {
            throw new \RuntimeException('لا تُدمج الحلقة في نفسها.');
        }

        if ($allowedCircleIds !== null
            && (! in_array($source->id, $allowedCircleIds, true) || ! in_array($target->id, $allowedCircleIds, true))) {
            throw new \RuntimeException('إحدى الحلقتين خارج نطاق صلاحياتك.');
        }

        return DB::transaction(function () use ($source, $target, $byUserId) {
            $students = Student::where('circle_id', $source->id)->get();

            $run = PromotionRun::create([
                'name' => "دمج «{$source->name}» في «{$target->name}»",
                'type' => PromotionRun::MERGE,
                'status' => PromotionRun::APPLIED,
                'created_by_id' => $byUserId,
                'applied_at' => now(),
            ]);

            foreach ($students as $student) {
                $run->items()->create([
                    'student_id' => $student->id,
                    'from_circle_id' => $source->id,
                    'to_circle_id' => $target->id,
                    'from_status' => $student->status,
                    'action' => PromotionRunItem::PROMOTE,
                ]);
            }

            Student::where('circle_id', $source->id)->update(['circle_id' => $target->id]);

            return $run;
        });
    }

    /**
     * What the person pressing the button ought to know first.
     *
     * @return array{students: int, attendances: int, competitions: int}
     */
    public function preview(Circle $source): array
    {
        return [
            'students' => $source->students()->count(),
            'attendances' => $source->attendances()->count(),
            'competitions' => $source->leaderboards()->count(),
        ];
    }
}
