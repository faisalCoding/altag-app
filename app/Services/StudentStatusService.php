<?php

namespace App\Services;

use App\Models\Student;
use App\Support\HijriDate;
use Illuminate\Support\Facades\Auth;

class StudentStatusService
{
    /**
     * Change a student's status effective from a given date (defaults to today),
     * keeping the status history consistent:
     * - the status the student already has changes nothing: no row is moved and
     *   none is added. Re-saving it used to move the current period's start to
     *   the chosen date — a form opened and saved untouched rewrote months of
     *   history, and the attendance reports read with it. A wrong date is
     *   corrected on its own row (editHistoryEntry);
     * - a different status closes the current period and opens a new one at the
     *   effective date (backdating supported, but never before the current
     *   period began: the timeline is append-only);
     * - scheduled future rows (a pending automatic return) are superseded by
     *   any new decision and removed first.
     *
     * Returns whether anything changed.
     */
    public static function changeStatus(Student $student, string $status, ?string $effectiveDate = null, ?string $notes = null): bool
    {
        $today = now('Asia/Riyadh')->format('Y-m-d');
        $effectiveDate = $effectiveDate ?: $today;

        $current = $student->statusHistories()
            ->whereDate('start_date', '<=', $today)
            ->reorder('start_date', 'desc')
            ->orderByDesc('id')
            ->first();

        if (($current?->status ?? $student->status) === $status) {
            if ($student->status !== $status) {
                $student->update(['status' => $status]);
            }

            return false;
        }

        if ($current && $effectiveDate < $current->start_date->format('Y-m-d')) {
            throw new \InvalidArgumentException(
                'التاريخ المختار يسبق بداية الحالة الحالية ('.HijriDate::full($current->start_date).'). لتاريخ أقدم صحّح سجل الحالات.'
            );
        }

        // A new decision supersedes any scheduled (future-dated) rows.
        $student->statusHistories()->whereDate('start_date', '>', $today)->delete();

        $student->update(['status' => $status]);

        if ($current) {
            $current->update(['end_date' => $effectiveDate]);
        }

        $student->statusHistories()->create(array_merge([
            'status' => $status,
            'start_date' => $effectiveDate,
            'notes' => $notes,
        ], self::changedByMeta()));

        return true;
    }

    /**
     * Suspend a student with an automatic return: a scheduled "active" row is
     * written at the return date, and the daily status sync flips the cached
     * column when that day arrives.
     */
    public static function suspendWithReturn(Student $student, string $effectiveDate, string $returnDate, ?string $notes = null): void
    {
        if ($returnDate <= $effectiveDate) {
            throw new \InvalidArgumentException('تاريخ العودة يجب أن يكون بعد تاريخ بداية الإيقاف.');
        }

        // Already suspended, this sets when the suspension ends: the return
        // scheduled before gives way to this one.
        if (! self::changeStatus($student, 'suspended', $effectiveDate, $notes)) {
            $student->statusHistories()->whereDate('start_date', '>', now('Asia/Riyadh')->format('Y-m-d'))->delete();
        }

        $suspension = $student->statusHistories()
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();
        $suspension->update(['end_date' => $returnDate]);

        $student->statusHistories()->create(array_merge([
            'status' => 'active',
            'start_date' => $returnDate,
            'notes' => 'عودة تلقائية بعد انتهاء الإيقاف',
        ], self::changedByMeta()));
    }

    /**
     * The student's status on a given date per the history timeline, falling
     * back to the current column for students without covering rows.
     */
    public static function statusOn(Student $student, string $date): string
    {
        $row = $student->statusHistories()
            ->whereDate('start_date', '<=', $date)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();

        return $row ? $row->status : ($student->status ?: 'active');
    }

    /**
     * Delete a wrong history entry and re-sync the student's current status to
     * the effective row for today (when one exists).
     */
    public static function deleteHistoryEntry(Student $student, int $historyId): void
    {
        $student->statusHistories()->whereKey($historyId)->delete();

        // The period before it now runs on to whatever follows.
        self::reseal($student);
        self::resyncCachedStatus($student);
    }

    /**
     * Correct a recorded period in place.
     *
     * The timeline is append-only for *new* decisions, which is right: a status
     * change is a thing that happened on a day. But a row entered wrongly is not
     * a thing that happened, and until now the only remedy was to delete it and
     * lose the rest of its detail — so a mistyped date left the student stuck,
     * refusing every later correction because the timeline would not run
     * backwards over it.
     */
    public static function editHistoryEntry(Student $student, int $historyId, string $status, string $startDate, ?string $notes = null): void
    {
        $entry = $student->statusHistories()->whereKey($historyId)->first();

        if (! $entry) {
            throw new \InvalidArgumentException('سجل الحالة غير موجود.');
        }

        if ($startDate > now('Asia/Riyadh')->format('Y-m-d')) {
            throw new \InvalidArgumentException('تاريخ السريان لا يمكن أن يكون في المستقبل.');
        }

        $entry->update(array_merge([
            'status' => $status,
            'start_date' => $startDate,
        ], $notes !== null ? ['notes' => $notes] : [], self::changedByMeta()));

        // Periods are bounded by whatever follows them, so moving one row's start
        // date re-cuts its neighbour rather than leaving the two overlapping.
        self::reseal($student);
        self::resyncCachedStatus($student);
    }

    /**
     * Make each period end where the next one begins, and leave the last open.
     */
    private static function reseal(Student $student): void
    {
        // reorder(), not orderBy(): the relation is declared newest-first, and an
        // added orderBy appends a second clause the first one outranks — which
        // walks this loop backwards and seals every period against the wrong
        // neighbour.
        $rows = $student->statusHistories()
            ->reorder('start_date')
            ->orderBy('id')
            ->get();

        foreach ($rows as $index => $row) {
            $next = $rows[$index + 1] ?? null;

            $row->update(['end_date' => $next?->start_date?->format('Y-m-d')]);
        }
    }

    /**
     * The cached `status` column follows whichever period covers today.
     */
    private static function resyncCachedStatus(Student $student): void
    {
        $today = now('Asia/Riyadh')->format('Y-m-d');

        $effectiveToday = $student->statusHistories()
            ->whereDate('start_date', '<=', $today)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();

        if ($effectiveToday) {
            $student->update(['status' => $effectiveToday->status]);
        }
    }

    /**
     * The acting user recorded on each history row (role + name snapshot).
     *
     * @return array{changed_by_role: ?string, changed_by_name: ?string}
     */
    protected static function changedByMeta(): array
    {
        foreach (['manager', 'supervisor', 'teacher'] as $role) {
            if ($user = Auth::guard($role)->user()) {
                return ['changed_by_role' => $role, 'changed_by_name' => $user->name];
            }
        }

        return ['changed_by_role' => null, 'changed_by_name' => null];
    }
}
