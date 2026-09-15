<?php

namespace App\Console\Commands;

use App\Models\Circle;
use App\Models\Student;
use App\Services\StudentStatusService;
use App\Support\StudentStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                            {--create : أنشئ حساباً لمن ليس له حساب، وانقل من كان في حلقة أخرى}
                            {--rename-near : اعتبر الاسم القريب هو نفسه، وصحّح تهجئة الطالب الموجود إليه}
                            {--force : أنشئ حتى لو كان الاسم قريباً من طالب موجود (خطر التكرار)}
                            {--email-domain=altag.local : نطاق البريد المولَّد للحسابات الجديدة}
                            {--credentials= : اكتب بيانات الدخول المولَّدة في هذا الملف بدل طباعتها}
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

        $plan = $this->plan($wanted, $roll, $circle);

        if ($plan === null) {
            return self::FAILURE;
        }

        $this->report($circle, $plan, $since);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->components->warn('عرض فقط — لم يتغيّر شيء. أضف --apply للتنفيذ.');

            return self::SUCCESS;
        }

        return $this->apply($plan, $circle, $since);
    }

    /**
     * Work out what each listed name means, without doing any of it yet.
     *
     * A name is one of four things: already on this roll, on the books but in
     * another circle, nobody the system knows, or too ambiguous to act on. Only
     * the last stops the run — and it stops all of it, because a name skipped
     * here marks a present student as gone and nobody finds out until the day
     * they cannot be marked.
     *
     * @param  Collection<int, string>  $wanted
     * @param  Collection<int, Student>  $roll
     * @return array{staying: Collection<int, Student>, moving: Collection<int, Student>, renaming: Collection<int, array{student: Student, to: string}>, creating: Collection<int, string>, leaving: Collection<int, Student>}|null
     */
    private function plan(Collection $wanted, Collection $roll, Circle $circle): ?array
    {
        $onRoll = $roll->groupBy(fn (Student $s) => $this->normalise($s->name));

        $others = Student::where(fn ($q) => $q->whereNull('circle_id')->orWhere('circle_id', '!=', $circle->id))
            ->get()
            ->groupBy(fn (Student $s) => $this->normalise($s->name));

        $staying = collect();
        $moving = collect();
        $creating = collect();
        $renaming = collect();
        $problems = [];

        foreach ($wanted as $name) {
            $key = $this->normalise($name);

            $here = $onRoll->get($key, collect());

            if ($here->count() === 1) {
                $staying->push($here->first());

                continue;
            }

            if ($here->count() > 1) {
                $problems[] = "«{$name}» يطابق أكثر من طالب في الحلقة: ".$here->map(fn ($s) => "#{$s->id}")->implode('، ');

                continue;
            }

            $elsewhere = $others->get($key, collect());

            if ($elsewhere->count() > 1) {
                $problems[] = "«{$name}» يطابق أكثر من طالب خارج الحلقة: ".$elsewhere->map(fn ($s) => "#{$s->id}")->implode('، ');

                continue;
            }

            if (! $this->option('create')) {
                $problems[] = $elsewhere->count() === 1
                    ? "«{$name}» موجود في حلقة أخرى (#{$elsewhere->first()->id}). أضف --create لنقله."
                    : "«{$name}» لا يطابق أي طالب.".$this->suggest($name, $roll).' أضف --create لإنشاء حساب له.';

                continue;
            }

            if ($elsewhere->count() === 1) {
                $moving->push($elsewhere->first());

                continue;
            }

            // Creating is the one path that cannot be undone by re-running, so a
            // name close to somebody real is treated as the same person, spelled
            // differently, until the operator says otherwise.
            $near = $this->nearMatches($name, $roll->merge($others->flatten()));

            if ($near->count() === 1 && $this->option('rename-near')) {
                $renaming->push(['student' => $near->first(), 'to' => trim($name)]);

                continue;
            }

            if ($near->isNotEmpty() && ! $this->option('force')) {
                $problems[] = "«{$name}» قريب جداً من «".$near->pluck('name')->implode('» و«')
                    .'» — قد يكون نفس الطالب. أضف --rename-near ليُعتمد ويُصحَّح اسمه، أو --force لإنشاء حساب جديد رغم ذلك.';

                continue;
            }

            $creating->push(trim($name));
        }

        $keep = $staying->pluck('id')->merge($renaming->pluck('student.id'));

        $plan = [
            'staying' => $staying,
            'moving' => $moving,
            'renaming' => $renaming,
            'creating' => $creating,
            'leaving' => $roll->reject(fn (Student $s) => $keep->contains($s->id))->values(),
        ];

        if ($problems !== []) {
            // Shown before the refusal, so the unresolved names can be read in the
            // context of everything else the run had already worked out.
            $this->report($circle, $plan, '—');

            $this->newLine();
            $this->components->error('تعذّر حسم '.count($problems).' اسماً، فلم يُنفَّذ شيء:');

            foreach ($problems as $problem) {
                $this->line('  • '.$problem);
            }

            return null;
        }

        return $plan;
    }

    /**
     * Students who are probably the person this name refers to, spelled otherwise.
     *
     * Two kinds of near miss, and edit distance only catches one. "عامر" for
     * "عمر" is a slip of a letter. But a middle name dropped — "عبدالله شلبي"
     * for "عبدالله أحمد شلبي" — is eight edits apart while being obviously the
     * same person, so names are also compared as sets of words: when one is
     * contained in the other and they share at least two, that is a match.
     *
     * @param  Collection<int, Student>  $pool
     * @return Collection<int, Student>
     */
    private function nearMatches(string $name, Collection $pool): Collection
    {
        $needle = $this->normalise($name);
        $needleWords = $this->words($name);

        return $pool->filter(function (Student $student) use ($needle, $needleWords) {
            $other = $this->normalise($student->name);

            if ($other === $needle) {
                return false;
            }

            if (levenshtein($needle, $other) <= 3) {
                return true;
            }

            $otherWords = $this->words($student->name);
            $shared = array_intersect($needleWords, $otherWords);

            return count($shared) >= 2
                && (count($shared) === count($needleWords) || count($shared) === count($otherWords));
        })->values();
    }

    /**
     * A name as its normalised words, for comparing people rather than strings.
     *
     * @return array<int, string>
     */
    private function words(string $name): array
    {
        $cleaned = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{0640}\x{064B}-\x{0652}]/u', '', $name);
        $cleaned = strtr($cleaned, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي']);

        return array_values(array_unique(array_filter(preg_split('/\s+/u', trim($cleaned)))));
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
     * @param  array{staying: Collection, moving: Collection, renaming: Collection, creating: Collection, leaving: Collection}  $plan
     */
    private function report(Circle $circle, array $plan, string $since): void
    {
        $this->newLine();
        $this->components->info("حلقة «{$circle->name}» — تاريخ السريان {$since}");

        $this->section('<fg=green>يبقون مشاركين</>', $plan['staying'], fn (Student $s) => 'الآن: '.StudentStatus::label($s->status));

        $this->section('<fg=cyan>يُنقلون إلى هذه الحلقة</>', $plan['moving'],
            fn (Student $s) => 'من: '.($s->circle?->name ?? 'بلا حلقة'));

        $this->section(
            '<fg=magenta>تُصحَّح أسماؤهم</>',
            $plan['renaming']->map(fn (array $row) => $row['student']->name.'  ←  '.$row['to']),
            fn () => 'تصحيح تهجئة',
        );

        $this->section('<fg=yellow>حسابات ستُنشأ</>', $plan['creating'], fn (string $name) => 'جديد');

        $this->section('<fg=red>سيصيرون «'.StudentStatus::label('left').'»</>', $plan['leaving'],
            fn (Student $s) => 'الآن: '.StudentStatus::label($s->status));
    }

    /**
     * @param  Collection<int, mixed>  $rows
     */
    private function section(string $title, Collection $rows, callable $detail): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->twoColumnDetail($title, $rows->count().' طالباً');

        foreach ($rows as $row) {
            $name = $row instanceof Student ? $row->name : (string) $row;
            $this->components->twoColumnDetail("  {$name}", $detail($row));
        }
    }

    /**
     * All of it or none of it: a half-applied roll is worse than an untouched one,
     * because nobody can tell by looking which half went through.
     *
     * @param  array{staying: Collection, moving: Collection, renaming: Collection, creating: Collection, leaving: Collection}  $plan
     */
    private function apply(array $plan, Circle $circle, string $since): int
    {
        $credentials = [];

        try {
            DB::transaction(function () use ($plan, $circle, $since, &$credentials) {
                foreach ($plan['creating'] as $name) {
                    $credentials[] = $this->createStudent($name, $circle, $since);
                }

                foreach ($plan['moving'] as $student) {
                    $student->update(['circle_id' => $circle->id]);
                }

                $renamed = $plan['renaming']->map(function (array $row) use ($circle) {
                    $row['student']->update(['name' => $row['to'], 'circle_id' => $circle->id]);

                    return $row['student'];
                });

                foreach ($plan['staying']->merge($plan['moving'])->merge($renamed) as $student) {
                    StudentStatusService::changeStatus($student, 'active', $since, 'ضبط قائمة المشاركين');
                }

                foreach ($plan['leaving'] as $student) {
                    StudentStatusService::changeStatus($student, 'left', $since, 'ضبط قائمة المشاركين');
                }
            });
        } catch (\Throwable $e) {
            $this->newLine();
            $this->components->error('فشل التنفيذ ولم يتغيّر شيء: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info(sprintf(
            'تم: %d مشاركاً (منهم %d منقول و%d مصحَّح الاسم و%d جديد) و%d مغادراً، اعتباراً من %s.',
            $plan['staying']->count() + $plan['moving']->count() + $plan['renaming']->count() + $plan['creating']->count(),
            $plan['moving']->count(),
            $plan['renaming']->count(),
            $plan['creating']->count(),
            $plan['leaving']->count(),
            $since,
        ));

        $this->handOverCredentials($credentials);

        return self::SUCCESS;
    }

    /**
     * A student who exists only as a name on a list.
     *
     * Approved on the way in, because an unapproved student does not appear on
     * the register at all — which would leave the teacher unable to mark the
     * very people this command was run to add. Joined on the effective date, so
     * the weeks before it stay closed rather than showing as unmarked absence.
     *
     * @return array{name: string, email: string, password: string}
     */
    private function createStudent(string $name, Circle $circle, string $since): array
    {
        $email = $this->freeEmail();
        $password = Str::password(12, symbols: false);

        Student::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'circle_id' => $circle->id,
            'status' => 'active',
            'joined_at' => $since,
            'is_approved' => true,
        ]);

        return ['name' => $name, 'email' => $email, 'password' => $password];
    }

    /**
     * An address nobody is using. Generated rather than derived from the name:
     * Arabic does not transliterate to something a person would want to type,
     * and a placeholder that is obviously a placeholder gets corrected sooner.
     */
    private function freeEmail(): string
    {
        $domain = (string) $this->option('email-domain');

        do {
            $email = 'student.'.Str::lower(Str::random(8)).'@'.$domain;
        } while (Student::withoutGlobalScopes()->where('email', $email)->exists());

        return $email;
    }

    /**
     * @param  array<int, array{name: string, email: string, password: string}>  $credentials
     */
    private function handOverCredentials(array $credentials): void
    {
        if ($credentials === []) {
            return;
        }

        $rows = collect($credentials)->map(fn ($row) => [$row['name'], $row['email'], $row['password']]);

        if ($path = $this->option('credentials')) {
            $handle = fopen($path, 'w');
            fputcsv($handle, ['name', 'email', 'password']);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
            @chmod($path, 0600);

            $this->components->info("بيانات دخول {$rows->count()} حساباً جديداً كُتبت في {$path} — احذفه بعد توزيعها.");

            return;
        }

        $this->newLine();
        $this->components->warn('بيانات دخول الحسابات الجديدة — تظهر مرة واحدة فقط:');
        $this->table(['الاسم', 'البريد', 'كلمة المرور'], $rows->all());
        $this->components->warn('البريد مولَّد ولا يستقبل رسائل. عدّله من لوحة المدير قبل أن يحتاج الطالب استعادة كلمته.');
    }
}
