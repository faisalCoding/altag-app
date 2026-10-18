<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\AgreementRequest;
use App\Models\Student;
use App\Support\Agreements;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A template sent from the teacher app's student card, and a send called
 * off. Online only, unlike grading: each send's link is signed on the server,
 * and the teacher sends it on at once.
 */
class AgreementRequestController extends Controller
{
    /** Send a template to the student, their guardian or both. */
    public function store(Request $request, int $student): JsonResponse
    {
        $teacher = $request->user();
        $student = $this->student($request, $student);
        $stageId = $student->circle?->stage_id;

        $validated = $request->validate([
            'agreement_id' => ['required', 'integer'],
            'to' => ['required', Rule::in(array_keys(Agreements::AUDIENCES))],
            'type' => ['required', Rule::in(array_keys(Agreements::TYPES))],
            'note' => ['nullable', 'string', 'max:300'],
        ], [
            'note.max' => 'السبب طويل؛ اختصره في ٣٠٠ حرف.',
        ]);

        /** @var Agreement|null $template */
        $template = $stageId === null ? null : Agreements::templatesFor([$stageId])->firstWhere('id', (int) $validated['agreement_id']);

        if (! $template) {
            $this->refuse('template_unavailable', 'لم يعد هذا النموذج متاحاً لحلقة الطالب.');
        }

        $sent = Agreements::send($template, [$student], $validated['to'], $validated['type'], $validated['note'] ?? null, $teacher);

        return response()->json([
            'data' => ['requests' => $sent->map(fn (AgreementRequest $one) => Agreements::requestForApp($one->load(['version:id,title', 'sender:id,name'])))->values()],
        ]);
    }

    /** Call off a send not yet answered. */
    public function cancel(Request $request, int $agreementRequest): JsonResponse
    {
        $sent = AgreementRequest::with(['version:id,title', 'sender:id,name'])->find($agreementRequest);

        if (! $sent || ! $this->mayReach($request, $sent->student_id)) {
            $this->refuse('request_unavailable', 'لم يعد هذا الطلب ضمن حلقاتك.', 403);
        }

        if ($sent->signed_at !== null) {
            $this->refuse('request_answered', 'أُجيب هذا الطلب، فلا يُلغى.');
        }

        $sent->update(['cancelled_at' => $sent->cancelled_at ?? now()]);

        return response()->json(['data' => ['id' => $sent->id, 'cancelled' => true]]);
    }

    /** A student of the teacher's circles, or a refusal the app shows as it is. */
    private function student(Request $request, int $id): Student
    {
        $student = $this->mayReach($request, $id) ? Student::with(['circle:id,stage_id', 'guardian:id,name,phone'])->find($id) : null;

        return $student ?? $this->refuse('student_unavailable', 'لم يعد هذا الطالب ضمن حلقاتك.', 403);
    }

    private function mayReach(Request $request, int $studentId): bool
    {
        return Student::whereKey($studentId)
            ->whereIn('circle_id', $request->user()->workingCircleIds())
            ->exists();
    }

    private function refuse(string $code, string $message, int $status = 422): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
