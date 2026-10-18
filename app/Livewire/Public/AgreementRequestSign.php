<?php

namespace App\Livewire\Public;

use App\Models\AgreementRequest;
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
 * One send of a template agreement, opened from its own link: the teacher's
 * note, then the text it was sent with, a topic at a step — and the answer,
 * given once. A later send of the same template is another link and answer.
 *
 * As the student's link, it signs nobody in; a guardian coming through it
 * gives their name, one signed in as the student's guardian their account's.
 */
class AgreementRequestSign extends Component
{
    #[Locked]
    public int $requestId;

    public function mount(int $request): void
    {
        $this->requestId = AgreementRequest::findOrFail($request)->id;
    }

    #[Computed]
    public function request(): AgreementRequest
    {
        return AgreementRequest::with(['version', 'student.circle:id,name', 'sender:id,name'])->findOrFail($this->requestId);
    }

    private function signedIn(): ?User
    {
        $student = $this->request->student;

        if ($this->request->party === 'guardian') {
            $guardian = auth()->guard('guardian')->user();

            return $guardian && (int) $student->guardian_id === (int) $guardian->id ? $guardian : null;
        }

        $signed = auth()->guard('student')->user();

        return $signed && (int) $signed->id === (int) $student->id ? $signed : null;
    }

    /**
     * Record the answer once every topic was agreed to in the browser; the
     * page is not drawn again.
     *
     * @return array{ok: bool, signedBy?: string, signedAt?: string, message?: string, name?: bool, reload?: bool}
     */
    #[Renderless]
    public function sign(int $requestId, int $version, ?string $name = null): array
    {
        $key = 'agreement-sign:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 30)) {
            return ['ok' => false, 'message' => 'محاولات كثيرة، أعد المحاولة بعد دقائق.'];
        }

        RateLimiter::hit($key, 600);

        $request = $this->request;

        if ($requestId !== $request->id || $request->cancelled_at !== null) {
            return ['ok' => false, 'message' => 'أُلغي هذا الطلب، ولم يعد مطلوباً.', 'reload' => true];
        }

        if ($request->signed_at === null) {
            $signedIn = $this->signedIn();
            $name = $request->party === 'student' ? $request->student->name : ($signedIn?->name ?? trim((string) $name));

            if (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
                return ['ok' => false, 'message' => 'اكتب اسمك كاملاً قبل الإقرار.', 'name' => true];
            }

            Agreements::answer($request, $name, $signedIn?->id, $signedIn ? 'account' : 'link', request()->ip(), request()->userAgent());
        }

        return ['ok' => true, 'signedBy' => $request->signer_name, 'signedAt' => HijriDate::withWeekday($request->signed_at->setTimezone('Asia/Riyadh'))];
    }

    public function render()
    {
        $request = $this->request;
        $signedIn = $this->signedIn();

        $agreements = $request->cancelled_at !== null ? [] : [[
            'id' => $request->id,
            'version' => $request->version->number,
            'title' => (string) AgreementText::format($request->version->title),
            'type' => Agreements::TYPES[$request->type] ?? $request->type,
            'topics' => array_map(fn (array $topic) => [
                'title' => (string) AgreementText::format($topic['title']),
                'clauses' => array_map(fn (string $clause) => (string) AgreementText::format($clause), $topic['clauses']),
            ], Agreements::topicsOf($request->version->topics)),
            'signature' => $request->signed_at ? [
                'by' => $request->signer_name,
                'at' => HijriDate::withWeekday($request->signed_at->setTimezone('Asia/Riyadh')),
            ] : null,
        ]];

        return view('livewire.public.agreement-request-sign', [
            'request' => $request,
            'student' => $request->student,
            'agreements' => $agreements,
            'needsName' => $request->party === 'guardian' && ! $signedIn,
            'signedIn' => $signedIn,
        ])->layout('layouts.blank')->title('إقرار من الحلقة');
    }
}
