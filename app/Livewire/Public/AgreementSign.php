<?php

namespace App\Livewire\Public;

use App\Models\Agreement;
use App\Models\Student;
use App\Models\User;
use App\Support\Agreements;
use App\Support\AgreementText;
use App\Support\HijriDate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * The agreements a student, or their guardian, owes their word on — opened
 * from the link that is theirs alone, or from their own account — read and
 * agreed to topic by topic, a step each.
 *
 * Every step comes with the page and moves in the browser at once; the word
 * on an agreement goes to the server once, when its last topic is agreed to,
 * and the page is never drawn again. Topics agreed to on an agreement not yet
 * sent are kept in the browser, so a page closed halfway resumes there.
 *
 * The link signs nobody in: passed on, it opens this page and nothing else.
 * A guardian coming through it gives their name first; one signed in as the
 * student's guardian, or the student signed in, gives it from their account.
 */
class AgreementSign extends Component
{
    #[Locked]
    public int $studentId;

    #[Locked]
    public string $party;

    public function mount(int $student, string $party): void
    {
        $this->studentId = Student::findOrFail($student)->id;
        $this->party = $party;
    }

    #[Computed]
    public function student(): Student
    {
        return Student::with(['circle:id,name,stage_id', 'guardian:id,name,phone'])->findOrFail($this->studentId);
    }

    /**
     * The person signed in as this party of the student — the student, or
     * their guardian — if any.
     */
    private function signedIn(): ?User
    {
        if ($this->party === 'guardian') {
            $guardian = auth()->guard('guardian')->user();

            return $guardian && (int) $this->student->guardian_id === (int) $guardian->id ? $guardian : null;
        }

        $student = auth()->guard('student')->user();

        return $student && (int) $student->id === $this->studentId ? $student : null;
    }

    /**
     * Record the word on an agreement whose every topic was agreed to in the
     * browser — on the version read, else nothing: the page answers with what
     * to show, and is not drawn again.
     *
     * @return array{ok: bool, signedBy?: string, signedAt?: string, message?: string, name?: bool, reload?: bool}
     */
    #[Renderless]
    public function sign(int $agreementId, int $version, ?string $name = null): array
    {
        $key = 'agreement-sign:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 30)) {
            return ['ok' => false, 'message' => 'محاولات كثيرة، أعد المحاولة بعد دقائق.'];
        }

        RateLimiter::hit($key, 600);

        $agreement = Agreements::forStudent($this->student, $this->party)->firstWhere('id', $agreementId);

        if (! $agreement) {
            return ['ok' => false, 'message' => 'لم يعد هذا الإقرار مطلوباً، أعد فتح الرابط.', 'reload' => true];
        }

        if ($agreement->currentVersion->number !== $version) {
            return ['ok' => false, 'message' => 'عُدّل هذا الإقرار بعد فتح الصفحة، أعد فتحها لقراءة نصه الجديد.', 'reload' => true];
        }

        $signedIn = $this->signedIn();
        $name = $this->party === 'student' ? $this->student->name : ($signedIn?->name ?? trim((string) $name));

        if (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
            return ['ok' => false, 'message' => 'اكتب اسمك كاملاً قبل الإقرار.', 'name' => true];
        }

        $signature = Agreements::sign(
            $agreement, $this->student, $this->party, $name, $signedIn?->id,
            $signedIn ? 'account' : 'link', request()->ip(), request()->userAgent(),
        );

        return ['ok' => true, 'signedBy' => $signature->signer_name, 'signedAt' => HijriDate::withWeekday($signature->signed_at->setTimezone('Asia/Riyadh'))];
    }

    /**
     * Everything the steps show, read once with the page: each agreement with
     * its version, kind and topics — their text already formatted — and the
     * word that stands on it, if any.
     *
     * @return list<array{id: int, version: int, title: string, type: string, topics: list<array{title: string, clauses: list<string>}>, signature: ?array{by: string, at: string}}>
     */
    private function agreements(): array
    {
        $student = $this->student;

        return Agreements::forStudent($student, $this->party)->map(function (Agreement $agreement) use ($student) {
            $signature = Agreements::signatureFor($agreement, $student, $this->party);
            $type = Agreements::typeFor($agreement, $student->circle_id);

            return [
                'id' => $agreement->id,
                'version' => $agreement->currentVersion->number,
                'title' => (string) AgreementText::format($agreement->currentVersion->title),
                'type' => Agreements::TYPES[$type] ?? $type,
                'topics' => array_map(fn (array $topic) => [
                    'title' => (string) AgreementText::format($topic['title']),
                    'clauses' => array_map(fn (string $clause) => (string) AgreementText::format($clause), $topic['clauses']),
                ], Agreements::topicsOf($agreement->currentVersion->topics)),
                'signature' => $signature ? [
                    'by' => $signature->signer_name,
                    'at' => HijriDate::withWeekday($signature->signed_at->setTimezone('Asia/Riyadh')),
                ] : null,
            ];
        })->values()->all();
    }

    public function render()
    {
        return view('livewire.public.agreement-sign', [
            'student' => $this->student,
            'agreements' => $this->agreements(),
            'signedIn' => $this->signedIn(),
        ])->layout('layouts.blank')->title('إقرارات الحلقة');
    }
}
