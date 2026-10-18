<?php

namespace App\Support;

use App\Models\Agreement;
use App\Models\AgreementCircleType;
use App\Models\AgreementSignature;
use App\Models\AgreementVersion;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * الإقرارات: the agreements a supervisor writes for stages of theirs, and the
 * word students and guardians give on them.
 *
 * - An agreement binds the students of its stages — the stage the student
 *   studies in, by their circle — and asks its audience: the student, the
 *   guardian, or both, each giving their own word.
 * - Publishing freezes the draft as a version. The supervisor says whether
 *   an edit asks for the word again; a wording fix leaves earlier words
 *   standing. A word counts while it was given on the latest version that
 *   asked for it, or a later one.
 * - Each student has a link of their own, and one for their guardian; a
 *   student or guardian signed in gives their word from their account.
 * - A teacher may call an agreement by another kind for their circle.
 */
final class Agreements
{
    public const TYPES = ['pledge' => 'تعهد', 'policies' => 'سياسات', 'principles' => 'مبادئ', 'guidelines' => 'توجيهات'];

    public const AUDIENCES = ['student' => 'الطالب', 'guardian' => 'ولي الأمر', 'both' => 'الطالب وولي الأمر'];

    public const PARTIES = ['student' => 'الطالب', 'guardian' => 'ولي الأمر'];

    /**
     * Who an audience asks for their word.
     *
     * @return list<string>
     */
    public static function partiesOf(string $audience): array
    {
        return $audience === 'both' ? ['student', 'guardian'] : [$audience];
    }

    /**
     * The published agreements a student's stage holds — those asking the
     * party given, when one is — oldest first, with their current version.
     *
     * @return Collection<int, Agreement>
     */
    public static function forStudent(Student $student, ?string $party = null): Collection
    {
        $stageId = $student->effective_stage_id;

        if ($stageId === null) {
            return collect();
        }

        return Agreement::where('status', 'published')
            ->whereHas('stages', fn ($q) => $q->whereKey($stageId))
            ->with('currentVersion')
            ->orderBy('id')
            ->get()
            ->filter(fn (Agreement $agreement) => $agreement->currentVersion !== null
                && ($party === null || in_array($party, self::partiesOf($agreement->currentVersion->audience), true)))
            ->values();
    }

    /**
     * The latest version that asked for the word: a word given on it, or on
     * a later one, still stands.
     */
    public static function binding(Agreement $agreement): ?AgreementVersion
    {
        return $agreement->versions()->where('requires_acknowledgement', true)->orderByDesc('number')->first();
    }

    /**
     * The word that stands for the student's party on the agreement, if any.
     */
    public static function signatureFor(Agreement $agreement, Student $student, string $party): ?AgreementSignature
    {
        $binding = self::binding($agreement);

        if ($binding === null) {
            return null;
        }

        return AgreementSignature::where('agreement_signatures.agreement_id', $agreement->id)
            ->where('student_id', $student->id)
            ->where('party', $party)
            ->join('agreement_versions', 'agreement_versions.id', '=', 'agreement_signatures.agreement_version_id')
            ->where('agreement_versions.number', '>=', $binding->number)
            ->orderByDesc('agreement_signatures.signed_at')
            ->select('agreement_signatures.*')
            ->first();
    }

    public static function owes(Agreement $agreement, Student $student, string $party): bool
    {
        return self::signatureFor($agreement, $student, $party) === null;
    }

    /**
     * The agreements the student's party still owes their word on.
     *
     * @return Collection<int, Agreement>
     */
    public static function pending(Student $student, string $party): Collection
    {
        return self::forStudent($student, $party)
            ->filter(fn (Agreement $agreement) => self::owes($agreement, $student, $party))
            ->values();
    }

    /**
     * The kind the agreement goes by in the circle: the teacher's, else the supervisor's.
     */
    public static function typeFor(Agreement $agreement, ?int $circleId): string
    {
        $own = $circleId === null ? null : AgreementCircleType::where('agreement_id', $agreement->id)->where('circle_id', $circleId)->value('type');

        return $own ?? $agreement->currentVersion?->type ?? $agreement->type;
    }

    /**
     * The text a signer reads: the version's, under the kind of their circle.
     *
     * @return array{title: string, type: string, clauses: list<string>}
     */
    public static function textFor(AgreementVersion $version, string $type): array
    {
        return ['title' => $version->title, 'type' => $type, 'clauses' => array_values($version->clauses)];
    }

    /**
     * @param  array{title: string, type: string, clauses: list<string>}  $text
     */
    public static function hashOf(array $text): string
    {
        return hash('sha256', json_encode($text, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Record the word of the student's party on the agreement as it reads
     * now. Giving it twice leaves the first.
     */
    public static function sign(Agreement $agreement, Student $student, string $party, string $name, ?int $signerId, string $channel, ?string $ip, ?string $userAgent): AgreementSignature
    {
        $version = $agreement->currentVersion;
        $type = self::typeFor($agreement, $student->circle_id);

        return AgreementSignature::firstOrCreate(
            ['agreement_version_id' => $version->id, 'student_id' => $student->id, 'party' => $party],
            [
                'agreement_id' => $agreement->id,
                'signer_name' => $name,
                'signer_id' => $signerId,
                'channel' => $channel,
                'type' => $type,
                'content_hash' => self::hashOf(self::textFor($version, $type)),
                'ip' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
                'signed_at' => now(),
            ],
        );
    }

    /**
     * Publish the agreement's draft as its next version. The first always
     * asks for the word; a later one asks when the supervisor says so. A
     * draft that reads as the version on show publishes nothing new.
     */
    public static function publish(Agreement $agreement, ?User $publisher, bool $requiresAcknowledgement): AgreementVersion
    {
        return DB::transaction(function () use ($agreement, $publisher, $requiresAcknowledgement) {
            $current = $agreement->versions()->orderByDesc('number')->lockForUpdate()->first();
            $draft = ['title' => $agreement->title, 'type' => $agreement->type, 'audience' => $agreement->audience, 'clauses' => array_values($agreement->clauses)];

            $agreement->update(['status' => 'published']);

            if ($current && ['title' => $current->title, 'type' => $current->type, 'audience' => $current->audience, 'clauses' => $current->clauses] === $draft) {
                return $current;
            }

            $version = $agreement->versions()->create($draft + [
                'number' => ($current?->number ?? 0) + 1,
                'requires_acknowledgement' => $current === null || $requiresAcknowledgement,
                'published_by' => $publisher?->id,
                'published_at' => now(),
            ]);

            $agreement->unsetRelation('currentVersion');

            return $version;
        });
    }

    /**
     * The student's own link, or their guardian's: it opens what the party
     * owes without signing anyone in, so it can be passed on safely.
     */
    public static function link(Student $student, string $party): string
    {
        return URL::signedRoute('agreements.sign', ['student' => $student->id, 'party' => $party]);
    }

    /**
     * The wa.me address that opens the message to the party; null when no
     * phone is on record for them.
     */
    public static function whatsappUrl(Student $student, string $party): ?string
    {
        $phone = $party === 'student' ? $student->phone : $student->guardian?->phone;

        if (! $phone) {
            return null;
        }

        return 'https://wa.me/'.RollCallReminder::phone($phone).'?text='.urlencode(self::message($student, $party));
    }

    public static function message(Student $student, string $party): string
    {
        $link = self::link($student, $party);

        if ($party === 'guardian') {
            return "السلام عليكم ورحمة الله وبركاته\n"
                ."ولي أمر الطالب {$student->name} الكريم\n"
                ."نرجو التكرم بالاطلاع على إقرارات الحلقة والإقرار بها عبر الرابط\n"
                .$link;
        }

        $firstName = explode(' ', trim($student->name))[0];

        return "السلام عليكم ورحمة الله وبركاته\n"
            ."عزيزي الطالب {$firstName}\n"
            ."نرجو الاطلاع على إقرارات الحلقة والإقرار بها عبر الرابط\n"
            .$link;
    }

    /**
     * The active students of the stages — by their circle's stage, or their
     * own when they have no circle yet — within the circles given, if any.
     *
     * @param  array<int, int>  $stageIds
     * @param  array<int, int>|null  $circleIds
     * @return EloquentCollection<int, Student>
     */
    public static function studentsOf(array $stageIds, ?array $circleIds = null): EloquentCollection
    {
        return Student::query()
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->whereHas('circle', fn ($circle) => $circle->whereIn('stage_id', $stageIds))
                ->orWhere(fn ($query) => $query->whereNull('circle_id')->whereIn('stage_id', $stageIds)))
            ->when($circleIds !== null, fn ($query) => $query->whereIn('circle_id', $circleIds))
            ->with(['circle:id,name,stage_id', 'guardian:id,name,phone'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Each student's standing on the agreement party by party, with the
     * links to send: the table the supervisor and the teacher follow up by.
     *
     * @param  EloquentCollection<int, Student>  $students
     * @return list<array{student: Student, parties: array<string, array{signature: ?AgreementSignature, link: string, whatsapp: ?string}>}>
     */
    public static function rows(Agreement $agreement, EloquentCollection $students): array
    {
        $standings = self::standings($agreement, $students->modelKeys());

        return $students->map(fn (Student $student) => [
            'student' => $student,
            'parties' => collect($standings[$student->id] ?? [])->map(fn (?AgreementSignature $signature, string $party) => [
                'signature' => $signature,
                'link' => self::link($student, $party),
                'whatsapp' => $signature ? null : self::whatsappUrl($student, $party),
            ])->all(),
        ])->values()->all();
    }

    /**
     * What the teacher app's student card shows of the agreements: for each
     * of the students, every agreement their stage asks of them — its title
     * and kind as their circle calls it — and, for each party it asks, when
     * they gave their word and the link to send them while they have not.
     *
     * @param  EloquentCollection<int, Student>  $students
     * @return list<array{student_id: int, agreement_id: int, title: string, type: string, parties: array<string, array{signed_at: ?string, signed_by: ?string, link: string}>}>
     */
    public static function forApp(EloquentCollection $students): array
    {
        $students->loadMissing('circle:id,stage_id');
        $byStage = $students->groupBy(fn (Student $student) => $student->effective_stage_id);
        $stageIds = $byStage->keys()->filter()->values()->all();

        if ($stageIds === []) {
            return [];
        }

        $agreements = Agreement::where('status', 'published')
            ->whereHas('stages', fn ($q) => $q->whereIn('stages.id', $stageIds))
            ->with(['currentVersion', 'stages:id', 'circleTypes'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Agreement $agreement) => $agreement->currentVersion !== null);

        $entries = [];

        foreach ($agreements as $agreement) {
            $bound = $agreement->stages->modelKeys();
            $asked = $students->filter(fn (Student $student) => in_array($student->effective_stage_id, $bound, true));
            $standings = self::standings($agreement, $asked->modelKeys());

            foreach ($asked as $student) {
                $type = $agreement->circleTypes->firstWhere('circle_id', $student->circle_id)?->type ?? $agreement->currentVersion->type;

                $entries[] = [
                    'student_id' => $student->id,
                    'agreement_id' => $agreement->id,
                    'title' => $agreement->currentVersion->title,
                    'type' => $type,
                    'parties' => collect($standings[$student->id] ?? [])->map(fn (?AgreementSignature $signature, string $party) => [
                        'signed_at' => $signature?->signed_at?->toISOString(),
                        'signed_by' => $signature?->signer_name,
                        'link' => self::link($student, $party),
                    ])->all(),
                ];
            }
        }

        return $entries;
    }

    /**
     * The word that stands for every student and party of an agreement, read
     * at once: student id → party → signature, null for one still owed.
     *
     * @param  array<int, int>  $studentIds
     * @return array<int, array<string, ?AgreementSignature>>
     */
    public static function standings(Agreement $agreement, array $studentIds): array
    {
        $parties = self::partiesOf($agreement->currentVersion?->audience ?? $agreement->audience);
        $binding = self::binding($agreement);
        $signatures = $binding === null ? collect() : AgreementSignature::where('agreement_signatures.agreement_id', $agreement->id)
            ->whereIn('student_id', $studentIds)
            ->join('agreement_versions', 'agreement_versions.id', '=', 'agreement_signatures.agreement_version_id')
            ->where('agreement_versions.number', '>=', $binding->number)
            ->orderBy('agreement_signatures.signed_at')
            ->select('agreement_signatures.*')
            ->get()
            ->keyBy(fn (AgreementSignature $signature) => $signature->student_id.'|'.$signature->party);

        $standings = [];

        foreach ($studentIds as $studentId) {
            foreach ($parties as $party) {
                $standings[$studentId][$party] = $signatures->get($studentId.'|'.$party);
            }
        }

        return $standings;
    }
}
