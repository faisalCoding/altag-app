<?php

namespace App\Livewire\Public;

use App\Models\Agreement;
use App\Models\Student;
use App\Models\User;
use App\Support\Agreements;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The agreements a student, or their guardian, owes their word on — opened
 * from the link that is theirs alone, or from their own account. Each is read
 * and agreed to on its own; one already agreed to shows who gave the word and
 * when.
 *
 * The link signs nobody in: passed on, it opens this page and nothing else.
 * A guardian coming through it gives their name with their word; one signed
 * in as the student's guardian, or the student signed in, gives it from
 * their account.
 */
class AgreementSign extends Component
{
    #[Locked]
    public int $studentId;

    #[Locked]
    public string $party;

    /** The guardian's name, given with their word through the link. */
    public string $signerName = '';

    /** @var array<int|string, bool> agreement id → the box ticked */
    public array $ticked = [];

    public function mount(int $student, string $party): void
    {
        $this->studentId = Student::findOrFail($student)->id;
        $this->party = $party;
        $this->signerName = $this->signedIn()?->name ?? '';
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

    public function sign(int $agreementId): void
    {
        $this->resetErrorBag();
        $key = 'agreement-sign:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 30)) {
            $this->addError("ticked.{$agreementId}", 'محاولات كثيرة، أعد المحاولة بعد دقائق.');

            return;
        }

        RateLimiter::hit($key, 600);

        $agreement = Agreements::forStudent($this->student, $this->party)->firstWhere('id', $agreementId);

        if (! $agreement) {
            $this->addError("ticked.{$agreementId}", 'لم يعد هذا الإقرار مطلوباً.');

            return;
        }

        if (! ($this->ticked[$agreementId] ?? false)) {
            $this->addError("ticked.{$agreementId}", 'ضع علامة الإقرار أولاً.');

            return;
        }

        $signedIn = $this->signedIn();

        if ($this->party === 'guardian' && ! $signedIn) {
            $this->signerName = trim($this->signerName);
            $this->validate(
                ['signerName' => 'required|string|min:3|max:100'],
                ['signerName.required' => 'اكتب اسمك قبل الإقرار.', 'signerName.min' => 'اكتب اسمك كاملاً.'],
            );
        }

        Agreements::sign(
            $agreement,
            $this->student,
            $this->party,
            $this->party === 'student' ? $this->student->name : ($signedIn?->name ?? $this->signerName),
            $signedIn?->id,
            $signedIn ? 'account' : 'link',
            request()->ip(),
            request()->userAgent(),
        );
    }

    public function render()
    {
        $student = $this->student;
        $agreements = Agreements::forStudent($student, $this->party)->map(fn (Agreement $agreement) => [
            'agreement' => $agreement,
            'type' => Agreements::typeFor($agreement, $student->circle_id),
            'signature' => Agreements::signatureFor($agreement, $student, $this->party),
        ]);

        return view('livewire.public.agreement-sign', [
            'student' => $student,
            'agreements' => $agreements,
            'signedIn' => $this->signedIn(),
        ])->layout('layouts.blank')->title('إقرارات الحلقة');
    }
}
