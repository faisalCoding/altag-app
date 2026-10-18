<?php

namespace App\Livewire\Supervisor;

use App\Models\Circle;
use App\Models\Stage;
use App\Models\Student;
use App\Services\RecitationRecord;
use App\Services\StudentRecordGrid;
use App\Support\HijriDate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Json;
use Livewire\Component;

/**
 * سجل الطلاب: every student of the supervisor's circles day by day, as the
 * teacher app's student card draws a student's attendance — each day in its
 * colour with the portions recited on it — with a report under the days and
 * beside the students. A day opens what was graded on it, without the table
 * being drawn again.
 */
class StudentRecord extends Component
{
    /** The longest stretch at once: a quarter of a year, a column a day. */
    public const MAX_DAYS = 93;

    public string $from = '';

    public string $to = '';

    /** One circle of the supervisor's, or '' for all of them. */
    public string $circle = '';

    /** What the squares count: '' for every recitation, else a key of RecitationRecord::FILTERS. */
    public string $filter = '';

    public function mount(): void
    {
        $this->to = $this->today();
        $this->from = $this->defaultFrom();
    }

    /**
     * The Hijri month so far — the one before too, in its first week, so
     * the table never opens on a day or two.
     */
    private function defaultFrom(): string
    {
        $today = $this->today();
        $start = HijriDate::months($today, 1)[0]['first_day'];

        if (Carbon::parse($start)->diffInDays($today) < 7) {
            return HijriDate::months(Carbon::parse($start)->subDay()->toDateString(), 1)[0]['first_day'];
        }

        return $start;
    }

    private function today(): string
    {
        return now('Asia/Riyadh')->toDateString();
    }

    public function updatedFrom(): void
    {
        $this->settleDates(movedFrom: true);
    }

    public function updatedTo(): void
    {
        $this->settleDates(movedFrom: false);
    }

    /**
     * Days of the past, the first not after the last, and no more than a
     * quarter of a year apart: the end left alone follows the one moved.
     */
    private function settleDates(bool $movedFrom): void
    {
        $today = $this->today();
        $this->to = $this->isDay($this->to) ? min($this->to, $today) : $today;
        $this->from = $this->isDay($this->from) ? min($this->from, $this->to) : $this->defaultFrom();

        if (Carbon::parse($this->from)->diffInDays($this->to) + 1 > self::MAX_DAYS) {
            if ($movedFrom) {
                $this->to = min(Carbon::parse($this->from)->addDays(self::MAX_DAYS - 1)->toDateString(), $today);
            } else {
                $this->from = Carbon::parse($this->to)->subDays(self::MAX_DAYS - 1)->toDateString();
            }
        }
    }

    public function updatedCircle(): void
    {
        if (! $this->circles->contains('id', (int) $this->circle)) {
            $this->circle = '';
        }
    }

    private function isDay(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    /**
     * The circles of the supervisor's stages, as the filter lists them.
     *
     * @return Collection<int, Circle>
     */
    #[Computed]
    public function circles(): Collection
    {
        $stageIds = auth()->guard('supervisor')->user()->stages()->pluck('stages.id');

        return Circle::whereIn('stage_id', $stageIds)->orderBy('name')->get(['id', 'name', 'stage_id']);
    }

    /**
     * What one day held for one student: the roll call's mark and every
     * portion graded on it that the filter picks, «لم يسمع» among them — read
     * when a day is opened.
     *
     * @return array{title: string, status: ?string, recitations: list<array{title: string, range: ?string, grade: int, by: ?string}>}
     */
    #[Json]
    public function day(int $studentId, string $date): array
    {
        $student = Student::whereIn('circle_id', $this->circles->modelKeys())->find($studentId);
        abort_unless($student && $this->isDay($date), 404);

        return [
            'title' => $student->name.' — '.HijriDate::withWeekday($date),
            'status' => $student->attendances()->whereDate('date', $date)->value('status'),
            'recitations' => RecitationRecord::between([$student->id], $date, $date)
                ->filter(fn (array $entry) => RecitationRecord::matches($entry, $this->filter))
                ->map(fn (array $entry) => [
                    'title' => RecitationRecord::title($entry),
                    'range' => RecitationRecord::text($entry),
                    'grade' => $entry['grade'],
                    'by' => $entry['by'],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * What the record can be narrowed to: the Quran's hifz and review, and
     * the mutun and the odes where a stage of the supervisor's memorises them.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function filters(): array
    {
        $stages = Stage::whereIn('id', $this->circles->pluck('stage_id')->filter()->unique())->get(['id', 'hadith_enabled', 'odes_enabled']);

        return collect(RecitationRecord::FILTERS)
            ->filter(fn (string $label, string $key) => match ($key) {
                'hadith' => $stages->contains('hadith_enabled', true),
                'ode' => $stages->contains('odes_enabled', true),
                default => true,
            })
            ->all();
    }

    public function updatedFilter(): void
    {
        if (! array_key_exists($this->filter, $this->filters)) {
            $this->filter = '';
        }
    }

    public function render()
    {
        $circleIds = $this->circle !== '' ? [(int) $this->circle] : $this->circles->modelKeys();

        return view('livewire.supervisor.student-record', [
            'grid' => StudentRecordGrid::build($circleIds, $this->from, $this->to, $this->filter ?: null),
            'today' => $this->today(),
        ]);
    }
}
