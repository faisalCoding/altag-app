<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SyncPeerPairResource;
use App\Models\Circle;
use App\Models\PeerPair;
use App\Models\Student;
use App\Services\PeerPairing;
use App\Services\TeacherSyncSnapshot;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Mutual recitation from the teacher app: the day's pairs made, students
 * swapped between them, and each recitation's outcome recorded. Online, like
 * the site's page: the pairs live on the server, shared by the circle's
 * teachers, and reach the phone with every sync.
 */
class PeerPairController extends Controller
{
    /** Pair the circle's students present on the day, in place of its pairs. */
    public function generate(Request $request): JsonResponse
    {
        [$circle, $date] = $this->day($request);

        return $this->present(PeerPairing::generate($circle, $date, $request->user()->id));
    }

    /** Move a student into another's place, or pair two left out. */
    public function swap(Request $request): JsonResponse
    {
        [$circle, $date] = $this->day($request);
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'different:with_id'],
            'with_id' => ['required', 'integer'],
        ]);

        $inCircle = Student::where('circle_id', $circle->id)
            ->whereKey([$validated['student_id'], $validated['with_id']])
            ->count();

        if ($inCircle !== 2) {
            $this->refuse('student_unavailable', 'الطالبان ليسا في هذه الحلقة.');
        }

        return $this->present(PeerPairing::swap($circle, $date, $validated['student_id'], $validated['with_id']));
    }

    /**
     * The mistakes counted in one student's recitation. Its grade goes with
     * the app's other grades, as a change to the review's session.
     */
    public function record(Request $request, PeerPair $pair): JsonResponse
    {
        if (! $request->user()->circles()->whereKey($pair->circle_id)->exists()) {
            $this->refuse('pair_unavailable', 'هذه الثنائية ليست في حلقاتك.', 403);
        }

        $validated = $request->validate([
            'place' => ['required', Rule::in(PeerPair::PLACES)],
            'mistakes' => ['present', 'nullable', 'integer', 'between:0,99'],
        ]);

        $pair = PeerPairing::recordMistakes($pair, $validated['place'], $validated['mistakes']);

        return response()->json(['data' => ['pair' => new SyncPeerPairResource($pair)]]);
    }

    /**
     * The circle, one of the teacher's, and the day, never one after today.
     *
     * @return array{0: Circle, 1: string}
     */
    private function day(Request $request): array
    {
        $validated = $request->validate([
            'circle_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $circle = $request->user()->circles()->whereKey($validated['circle_id'])->first()
            ?? $this->refuse('circle_unavailable', 'هذه الحلقة ليست من حلقاتك.', 403);

        return [$circle, min($validated['date'], TeacherSyncSnapshot::today())];
    }

    /**
     * @param  Collection<int, PeerPair>  $pairs
     */
    private function present(Collection $pairs): JsonResponse
    {
        return response()->json(['data' => ['pairs' => SyncPeerPairResource::collection($pairs)]]);
    }

    private function refuse(string $code, string $message, int $status = 422): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
