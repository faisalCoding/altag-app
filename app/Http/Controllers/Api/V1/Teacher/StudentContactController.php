<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SyncStudentResource;
use App\Models\Guardian;
use App\Models\Student;
use App\Rules\SaudiPhone;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * How the teacher app reaches a student's family: the student's own number,
 * and the guardian — found by their number and linked, or, when the academy
 * has none with it, given an account from a name and the number.
 *
 * Online only, unlike grading: an account is made or found on the server, and
 * a number is checked against the guardians there as it is typed.
 */
class StudentContactController extends Controller
{
    /** Set or clear the student's own number. */
    public function phone(Request $request, int $student): JsonResponse
    {
        $student = $this->student($request, $student);
        $phone = $this->number($request, required: false);

        $student->update(['phone' => SaudiPhone::format($phone)]);

        return $this->present($student);
    }

    /** The guardians already holding a number, for the teacher to link one. */
    public function lookupGuardian(Request $request, int $student): JsonResponse
    {
        $this->student($request, $student);

        return response()->json([
            'data' => [
                'guardians' => self::guardiansWithNumber($this->number($request))
                    ->map(fn (Guardian $guardian) => ['id' => $guardian->id, 'name' => $guardian->name])
                    ->values(),
            ],
        ]);
    }

    /**
     * Link the student to the guardian found by the number, or — the academy
     * holding none with it — to a new guardian account in that name.
     */
    public function saveGuardian(Request $request, int $student): JsonResponse
    {
        $student = $this->student($request, $student);
        $phone = $this->number($request);
        $validated = $request->validate([
            'guardian_id' => ['nullable', 'integer'],
            'name' => ['required_without:guardian_id', 'nullable', 'string', 'max:255'],
        ], [
            'name.required_without' => 'اكتب اسم ولي الأمر لإنشاء حسابه.',
        ]);

        $found = self::guardiansWithNumber($phone);

        if ($validated['guardian_id'] ?? null) {
            // Only a guardian holding the number typed: an id alone could name anyone's.
            $guardian = $found->firstWhere('id', (int) $validated['guardian_id'])
                ?? $this->refuse('guardian_unavailable', 'لا يوجد ولي أمر بهذا الرقم.');
        } else {
            if ($found->isNotEmpty()) {
                $this->refuse('guardian_exists', 'يوجد ولي أمر بهذا الرقم، اربطه به بدلاً من إنشاء حساب جديد.');
            }

            // Signs in by the magic link the academy sends; the address and
            // the password are placeholders no one types.
            $guardian = Guardian::create([
                'name' => trim($validated['name']),
                'phone' => SaudiPhone::format($phone),
                'email' => 'guardian_'.Str::random(10).'@uncompleted.altag.app',
                'password' => Hash::make(Str::random(32)),
                'access_token' => Str::random(32),
                'is_approved' => true,
            ]);
        }

        $student->update(['guardian_id' => $guardian->id]);

        return $this->present($student);
    }

    /**
     * Correct the linked guardian's number, which every child of theirs
     * shares.
     */
    public function guardianPhone(Request $request, int $student): JsonResponse
    {
        $student = $this->student($request, $student);
        $guardian = $student->guardian ?? $this->refuse('no_guardian', 'لا يوجد ولي أمر مرتبط بالطالب.');

        $guardian->update(['phone' => SaudiPhone::format($this->number($request))]);

        return $this->present($student);
    }

    /**
     * Guardians whose number is this one, however it was stored: the academy
     * keeps both 05XXXXXXXX and 9665XXXXXXXX, so the last nine digits decide.
     *
     * @return Collection<int, Guardian>
     */
    private static function guardiansWithNumber(string $phone): Collection
    {
        $local = substr($phone, -9);

        return Guardian::where('phone', 'like', "%{$local}")
            ->orderBy('id')
            ->get()
            ->filter(fn (Guardian $guardian) => substr((string) SaudiPhone::digits($guardian->phone), -9) === $local)
            ->values();
    }

    /** The number sent, as Saudi mobile digits. */
    private function number(Request $request, bool $required = true): ?string
    {
        $request->merge(['phone' => SaudiPhone::digits($request->input('phone'))]);

        return $request->validate([
            'phone' => [$required ? 'required' : 'nullable', 'string', new SaudiPhone],
        ], [
            'phone.required' => 'اكتب رقم الجوال.',
        ])['phone'] ?? null;
    }

    /** A student of the teacher's circles, or a refusal the app shows as it is. */
    private function student(Request $request, int $id): Student
    {
        $student = Student::whereKey($id)
            ->whereIn('circle_id', $request->user()->circles()->pluck('circles.id'))
            ->first();

        return $student ?? $this->refuse('student_unavailable', 'لم يعد هذا الطالب ضمن حلقاتك.', 403);
    }

    /** The student as the app stores them, with the numbers just written. */
    private function present(Student $student): JsonResponse
    {
        return response()->json([
            'data' => ['student' => new SyncStudentResource($student->fresh(['statusHistories', 'guardian:id,name,phone']))],
        ]);
    }

    private function refuse(string $code, string $message, int $status = 422): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
