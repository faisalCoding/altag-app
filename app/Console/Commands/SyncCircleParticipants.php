<?php

namespace App\Console\Commands;

use App\Models\Circle;
use App\Models\Student;
use App\Services\StudentStatusService;
use App\Support\StudentStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Set one circle's roll: the named students participate, everyone else has left.
 *
 * Written for the case where a circle's membership is known from outside the
 * system — a list handed over on paper — and correcting it by hand is twenty
 * separate status changes with twenty chances to mistype a date.
 */
class SyncCircleParticipants extends Command
{
    protected $signature = 'circle:participants
                            {circle : اسم الحلقة أو رقمها}
                            {--file= : ملف فيه اسم كل مشارك في سطر}
                            {--names= : الأسماء مفصولة بفاصلة، بديلاً عن الملف}
                            {--since= : تاريخ سريان الحالة (Y-m-d). الافتراضي: قبل أسبوع}
                            {--apply : نفّذ فعلاً. بدونه يعرض ما سيحدث ولا يغيّر شيئاً}';

    protected $description = 'جعل طلاب قائمة معيّنة مشاركين في حلقة، وكل من سواهم مغادراً';

    public function handle(): int
    {
        $circle = $this->resolveCircle();

        if (! $circle) {
            return self::FAILURE;
        }

        $wanted = $this->readNames();

        if ($wanted === null) {
            return self::FAILURE;
        }

        $since = $this->option('since') ?: now('Asia/Riyadh')->subWeek()->format('Y-m-d');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->components->error("تاريخ غير صالح: {$since}. الصيغة المطلوبة Y-m-d.");

            return self::FAILURE;
        }

        $roll = Student::where('circle_id', $circle->id)->orderBy('name')->get();

        if ($roll->isEmpty()) {
            $this->components->error("لا طلاب في حلقة «{$circle->name}».");

            return self::FAILURE;
        }

        $matched = $this->match($wanted, $roll);

        if ($matched === null) {
            return self::FAILURE;
        }

        $leaving = $roll->reject(fn (Student $s) => $matched->contains('id', $s->id))->values();

        $this->report($circle, $matched, $leaving, $since);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->components->warn('عرض فقط — لم يتغيّر شيء. أضف --apply للتنفيذ.');

            return self::SUCCESS;
        }

        return $this->apply($matched, $leaving, $since);
    }

    /**
     * Match every listed name to exactly one student on the roll.
     *
     * Refuses the whole run on the first name it cannot place, rather than
     * quietly carrying on: a name missed here is a student marked as having left
     * a circle they attend, and nobody would notice until they could not be
     * marked present.
     *
     * @param  Collection<int, string>  $wanted
     * @param  Collection<int, Student>  $roll
     * @return Collection<int, Student>|null
     */
    private function match(Collection $wanted, Collection $roll): ?Collection
    {
        $byName = $roll->groupBy(fn (Student $s) => $this->normalise($s->name));

        $matched = collect();
        $problems = [];

        foreach ($wanted as $name) {
            $candidates = $byName->get($this->normalise($name), collect());

            if ($candidates->count() === 1) {
                $matched->push($candidates->first());

                continue;
            }

            $problems[] = $candidates->isEmpty()
                ? "«{$name}» لا يطابق أي طالب في الحلقة.".$this->suggest($name, $roll)
                : "«{$name}» يطابق أكثر من طالب: ".$candidates->map(fn ($s) => "#{$s->id}")->implode('، ');
        }

        if ($problems !== []) {
            $this->components->error('تعذّر مطابقة '.count($problems).' اسماً، فلم يُنفَّذ شيء:');

            foreach ($problems as $problem) {
                $this->line('  • '.$problem);
            }

            return null;
        }

        return $matched;
    }

    /**
     * The nearest names on the roll, to turn "not found" into something fixable.
     *
     * @param  Collection<int, Student>  $roll
     */
    private function suggest(string $name, Collection $roll): string
    {
        $near = $roll
            ->map(fn (Student $s) => ['name' => $s->name, 'distance' => levenshtein($this->normalise($name), $this->normalise($s->name))])
            ->sortBy('distance')
            ->take(2)
            ->filter(fn ($row) => $row['distance'] <= 6)
            ->pluck('name');

        return $near->isEmpty() ? '' : ' الأقرب: '.$near->implode('، ');
    }

    /**
     * Names here carry invisible bidi marks, stray spaces, tatweel and alef
     * spelled four ways. Compared without them, "عبدالله" and "عبد الله" are the
     * same person — which is how a handwritten list spells him either way.
     */
    private function normalise(string $name): string
    {
        $name = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{0640}]/u', '', $name);
        $name = strtr($name, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي']);
        $name = preg_replace('/[\x{064B}-\x{0652}]/u', '', $name);

        return preg_replace('/\s+/u', '', trim($name));
    }

    /**
     * @return Collection<int, string>|null
     */
    private function readNames(): ?Collection
    {
        $raw = null;

        if ($file = $this->option('file')) {
            if (! is_readable($file)) {
                $this->components->error("تعذّر قراءة الملف: {$file}");

                return null;
            }

            $raw = file_get_contents($file);
        } elseif ($names = $this->option('names')) {
            $raw = str_replace(',', "\n", $names);
        }

        if ($raw === null) {
            $this->components->error('مرّر الأسماء عبر --file أو --names.');

            return null;
        }

        $names = collect(preg_split('/\R/u', $raw))
            // A pasted list keeps its numbering, in Arabic-Indic digits or Latin.
            ->map(fn (string $line) => trim(preg_replace('/^[\s\x{0660}-\x{0669}0-9]+[.)\-–]\s*/u', '', trim($line))))
            ->filter()
            ->values();

        if ($names->isEmpty()) {
            $this->components->error('القائمة فارغة.');

            return null;
        }

        $duplicates = $names->groupBy(fn ($n) => $this->normalise($n))->filter(fn ($g) => $g->count() > 1);

        if ($duplicates->isNotEmpty()) {
            $this->components->error('أسماء مكرّرة في القائمة: '.$duplicates->map(fn ($g) => $g->first())->implode('، '));

            return null;
        }

        return $names;
    }

    private function resolveCircle(): ?Circle
    {
        $needle = (string) $this->argument('circle');

        $matches = Circle::when(
            ctype_digit($needle),
            fn ($q) => $q->whereKey((int) $needle),
            fn ($q) => $q->where('name', 'like', '%'.$needle.'%'),
        )->get();

        if ($matches->isEmpty()) {
            $this->components->error("لا توجد حلقة بهذا الاسم أو الرقم: {$needle}");

            return null;
        }

        if ($matches->count() > 1) {
            $this->components->error('أكثر من حلقة تطابق: '.$matches->pluck('name')->implode('، ').'. استخدم الرقم.');

            return null;
        }

        return $matches->first();
    }

    /**
     * @param  Collection<int, Student>  $staying
     * @param  Collection<int, Student>  $leaving
     */
    private function report(Circle $circle, Collection $staying, Collection $leaving, string $since): void
    {
        $this->newLine();
        $this->components->info("حلقة «{$circle->name}» — تاريخ السريان {$since}");

        $this->components->twoColumnDetail(
            '<fg=green>سيصيرون «'.StudentStatus::label('active').'»</>',
            (string) $staying->count().' طالباً',
        );

        foreach ($staying as $student) {
            $this->components->twoColumnDetail("  {$student->name}", 'الآن: '.StudentStatus::label($student->status));
        }

        $this->newLine();
        $this->components->twoColumnDetail(
            '<fg=red>سيصيرون «'.StudentStatus::label('left').'»</>',
            (string) $leaving->count().' طالباً',
        );

        foreach ($leaving as $student) {
            $this->components->twoColumnDetail("  {$student->name}", 'الآن: '.StudentStatus::label($student->status));
        }
    }

    /**
     * All of it or none of it: a half-applied roll is worse than an untouched one,
     * because nobody can tell by looking which half went through.
     *
     * @param  Collection<int, Student>  $staying
     * @param  Collection<int, Student>  $leaving
     */
    private function apply(Collection $staying, Collection $leaving, string $since): int
    {
        try {
            DB::transaction(function () use ($staying, $leaving, $since) {
                foreach ($staying as $student) {
                    StudentStatusService::changeStatus($student, 'active', $since, 'ضبط قائمة المشاركين');
                }

                foreach ($leaving as $student) {
                    StudentStatusService::changeStatus($student, 'left', $since, 'ضبط قائمة المشاركين');
                }
            });
        } catch (\Throwable $e) {
            $this->newLine();
            $this->components->error('فشل التنفيذ ولم يتغيّر شيء: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info("تم: {$staying->count()} مشاركاً و{$leaving->count()} مغادراً، اعتباراً من {$since}.");

        return self::SUCCESS;
    }
}
