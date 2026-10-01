<?php

namespace App\Livewire\Shared;

use App\Jobs\SendGuardianWhatsappJob;
use App\Models\Circle;
use App\Models\Student;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Bulk-send magic login links over WhatsApp from the WhatsApp settings page.
 * Three send types: the guardian's own link to the guardian, the student's
 * link to the guardian, or the student's link to the student himself. Before
 * anything is sent, a full report shows which students qualify and exactly
 * why the others do not (non-active status, missing guardian, missing phone),
 * so the sender knows who will be reached. Messages go one-by-one through the
 * queued gateway job which already paces sends at a human-like rate.
 */
class WhatsappBulkLinks extends Component
{
    #[Locked]
    public string $clientId;

    public string $sendType = 'guardian_link_to_guardian';

    public bool $reportVisible = false;

    public const SEND_TYPES = [
        'guardian_link_to_guardian' => 'إرسال رابط ولي الأمر إلى ولي الأمر',
        'student_link_to_guardian' => 'إرسال رابط الطالب إلى ولي الأمر',
        'student_link_to_student' => 'إرسال رابط الطالب إلى الطالب',
    ];

    protected const STATUS_LABELS = [
        'active' => 'مشارك',
        'registering' => 'تحت التسجيل',
        'suspended' => 'موقوف',
        'left' => 'غادر الحلقات',
    ];

    public function mount(string $clientId): void
    {
        $this->clientId = $clientId;
    }

    public function updatedSendType(): void
    {
        $this->reportVisible = false;
    }

    public function previewReport(): void
    {
        $this->reportVisible = true;
    }

    public function cancelReport(): void
    {
        $this->reportVisible = false;
    }

    /**
     * Approved students in scope: everyone for a manager, only the
     * supervisor's stages' circles when a supervisor is signed in.
     *
     * @return Collection<int, Student>
     */
    protected function scopedStudents(): Collection
    {
        $query = Student::with(['guardian', 'circle'])
            ->whereRoleState(fn ($q) => $q->where('is_approved', true));

        if (auth()->guard('supervisor')->check()) {
            $stageIds = auth()->guard('supervisor')->user()->stages()->pluck('stages.id');
            $query->whereIn('circle_id', Circle::whereIn('stage_id', $stageIds)->pluck('id'));
        }

        return $query->orderBy('name')->get();
    }

    protected function targetsGuardian(): bool
    {
        return in_array($this->sendType, ['guardian_link_to_guardian', 'student_link_to_guardian'], true);
    }

    /**
     * Classify every student in scope for the chosen send type.
     *
     * @return array{qualified: list<array{student: Student, recipient: string, phone: string}>, unqualified: list<array{student: Student, reasons: list<string>}>, messages_count: int}
     */
    public function buildReport(): array
    {
        $qualified = [];
        $unqualified = [];
        $guardianIds = [];

        foreach ($this->scopedStudents() as $student) {
            $reasons = [];

            if ($student->status !== 'active') {
                $label = self::STATUS_LABELS[$student->status] ?? $student->status;
                $reasons[] = "حالة الطالب \"{$label}\" وليست \"مشارك\"";
            }

            if ($this->targetsGuardian()) {
                if (! $student->guardian) {
                    $reasons[] = 'لا يوجد ولي أمر مرتبط بالطالب';
                } elseif (blank($student->guardian->phone)) {
                    $reasons[] = 'لا يوجد رقم هاتف لولي الأمر';
                }
            } else {
                if (blank($student->phone)) {
                    $reasons[] = 'لا يوجد رقم هاتف للطالب';
                }
            }

            if ($reasons !== []) {
                $unqualified[] = ['student' => $student, 'reasons' => $reasons];

                continue;
            }

            $qualified[] = [
                'student' => $student,
                'recipient' => $this->targetsGuardian() ? 'ولي الأمر: '.$student->guardian->name : 'الطالب نفسه',
                'phone' => $this->targetsGuardian() ? $student->guardian->phone : $student->phone,
            ];

            $guardianIds[] = $this->targetsGuardian() ? $student->guardian->id : null;
        }

        // The guardian's own link is sent once per guardian, even when several
        // of their children qualify — count actual messages accordingly.
        $messagesCount = $this->sendType === 'guardian_link_to_guardian'
            ? count(array_unique(array_filter($guardianIds)))
            : count($qualified);

        return [
            'qualified' => $qualified,
            'unqualified' => $unqualified,
            'messages_count' => $messagesCount,
        ];
    }

    public function send(): void
    {
        $report = $this->buildReport();

        if ($report['qualified'] === []) {
            Flux::toast(__('لا يوجد أي مستلم مؤهل للإرسال'), variant: 'warning');

            return;
        }

        $sentGuardianIds = [];
        $count = 0;

        foreach ($report['qualified'] as $row) {
            $student = $row['student'];

            if ($this->sendType === 'guardian_link_to_guardian') {
                $guardian = $student->guardian;

                if (isset($sentGuardianIds[$guardian->id])) {
                    continue;
                }
                $sentGuardianIds[$guardian->id] = true;

                $url = route('guardian.magic-link', $this->ensureAccessToken($guardian));
                $message = "السلام عليكم ورحمة الله وبركاته\n"
                    ."هذا رابط الدخول الخاص بكم لمتابعة أبنائكم في التطبيق:\n{$url}\n\n"
                    .'هذا الرابط خاص بكم، نرجو عدم مشاركته مع أي أحد.';
            } elseif ($this->sendType === 'student_link_to_guardian') {
                $url = route('magic-link', $this->ensureAccessToken($student));
                $message = "السلام عليكم ورحمة الله وبركاته\n"
                    ."هذا رابط دخول ابنكم الطالب {$student->name} إلى التطبيق:\n{$url}\n\n"
                    .'هذا الرابط خاص بحساب الطالب، نرجو عدم مشاركته مع أي أحد.';
            } else {
                $url = route('magic-link', $this->ensureAccessToken($student));
                $message = "السلام عليكم ورحمة الله وبركاته\n"
                    ."هذا رابط دخولك الخاص إلى التطبيق:\n{$url}\n\n"
                    .'هذا الرابط خاص بك، نرجو عدم مشاركته مع أي أحد.';
            }

            SendGuardianWhatsappJob::dispatch($row['phone'], $message, $this->clientId);
            $count++;
        }

        $this->reportVisible = false;
        Flux::toast(__('تمت جدولة إرسال :count رسالة عبر الواتساب، وسترسَل تباعاً خلال دقائق', ['count' => $count]), variant: 'success');
    }

    /**
     * Magic links need an access token; generate one for accounts that were
     * never issued a login link before.
     */
    protected function ensureAccessToken($user): string
    {
        if (blank($user->access_token)) {
            $user->access_token = Str::random(32);
            $user->save();
        }

        return $user->access_token;
    }

    public function render()
    {
        return view('livewire.shared.whatsapp-bulk-links', [
            'report' => $this->reportVisible ? $this->buildReport() : null,
            'sendTypes' => self::SEND_TYPES,
        ]);
    }
}
