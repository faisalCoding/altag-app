<?php

namespace App\Livewire\Teacher;

use App\Models\GamificationBadge;
use App\Models\GamificationTransaction;
use App\Models\Leaderboard;
use App\Models\LeaderboardCriterion;
use App\Models\LeaderboardScore;
use App\Models\Student;
use App\Services\GamificationService;
use App\Services\LeaderboardService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class LeaderboardGrade extends Component
{
    #[On('student-list-updated')]
    public function refreshStudents(): void
    {
        // Livewire automatically re-renders
    }

    #[Locked]
    public $leaderboardId;

    public $date;

    // Modal state kept for backward-compat but not used in inline flow
    public $showExtraPointsModal = false;

    public function mount($leaderboardId)
    {
        $this->leaderboardId = $leaderboardId;
        // The academy's day: on UTC it is still yesterday for the first three
        // hours of every Riyadh morning.
        $this->date = now('Asia/Riyadh')->format('Y-m-d');

        // The id comes from the address bar: grade only a competition that
        // runs in one of this teacher's circles.
        abort_unless($this->teachesInCompetition(), 404);

        $this->reconcileBadges();
    }

    /**
     * Reconcile badge awards so any student who already meets a requirement
     * (e.g. a badge added or edited after they qualified) gets a
     * pending-approval row and surfaces in the teacher's approval list.
     *
     * Ten-odd queries a student, so not on every opening of the page — which
     * now loads behind the others whenever the teacher's pages open: once an
     * hour for the teacher, and at once whenever the competition's badges
     * change.
     */
    private function reconcileBadges(): void
    {
        $badges = GamificationBadge::where('leaderboard_id', $this->leaderboardId);
        $version = $badges->count().'|'.$badges->max('updated_at');
        $key = 'leaderboard-grade:badges:'.$this->leaderboardId.':'.auth()->guard('teacher')->id().':'.md5($version);

        if (! Cache::add($key, true, now()->addHour())) {
            return;
        }

        foreach ($this->participatingStudents() as $student) {
            GamificationService::syncStudentBadges($student->id, $this->leaderboardId);
        }
    }

    private function teachesInCompetition(): bool
    {
        $leaderboard = Leaderboard::with('circles')->find($this->leaderboardId);
        $teacher = auth()->guard('teacher')->user();

        if (! $leaderboard || ! $teacher) {
            return false;
        }

        $leaderboardCircleIds = $leaderboard->circles->pluck('id')->push($leaderboard->circle_id)->filter();

        // Their own circles, and any they stand in for today.
        return collect($teacher->workingCircleIds())->intersect($leaderboardCircleIds)->isNotEmpty();
    }

    /**
     * Every action that names a student by id is held to the students this
     * page lists, so an edited request cannot score someone else's student.
     */
    private function ensureParticipant(int $studentId): void
    {
        abort_unless($this->participatingStudents()->contains('id', $studentId), 404);
    }

    /**
     * A score is written for the date on screen: any date for a student of
     * the teacher's own circles, today's alone for one of a circle they stand
     * in for.
     */
    private function mayScore(int $studentId): bool
    {
        $student = $this->participatingStudents()->firstWhere('id', $studentId);

        if ($student && auth()->guard('teacher')->user()->mayWorkOn((int) $student->circle_id, Carbon::parse($this->date)->toDateString())) {
            return true;
        }

        Flux::toast('تنوب في هذه الحلقة اليوم، فلا ترصد إلا لتاريخ اليوم.', variant: 'warning');

        return false;
    }

    /**
     * The active students in the teacher's circles that participate in this
     * competition.
     *
     * @return Collection<int, Student>
     */
    private function participatingStudents(): Collection
    {
        $leaderboard = Leaderboard::with('circles')->findOrFail($this->leaderboardId);

        $teacher = auth()->guard('teacher')->user();
        $teacherCircleIds = $teacher ? collect($teacher->workingCircleIds()) : collect();
        $leaderboardCircleIds = $leaderboard->circles->pluck('id');
        if ($leaderboard->circle_id) {
            $leaderboardCircleIds->push($leaderboard->circle_id);
        }

        // All of the teacher's circles that participate in this competition; when
        // the intersection is empty fall back to whichever side is known.
        $circleIds = $teacherCircleIds->intersect($leaderboardCircleIds)->values();
        if ($circleIds->isEmpty()) {
            $circleIds = $teacherCircleIds->isNotEmpty() ? $teacherCircleIds : $leaderboardCircleIds->unique()->values();
        }

        return Student::whereIn('circle_id', $circleIds)
            ->where('status', 'active')
            ->with('circle:id,name')
            ->orderBy('name')
            ->get();
    }

    public function toggleScore($studentId, $criterionId, $points)
    {
        $this->ensureParticipant((int) $studentId);

        if (! $this->mayScore((int) $studentId)) {
            return;
        }
        abort_unless(LeaderboardCriterion::where('leaderboard_id', $this->leaderboardId)->whereKey($criterionId)->exists(), 404);

        $score = LeaderboardScore::where('leaderboard_id', $this->leaderboardId)
            ->where('student_id', $studentId)
            ->where('leaderboard_criterion_id', $criterionId)
            ->whereDate('date', Carbon::parse($this->date))
            ->first();

        if ($score) {
            $scoreId = $score->id;
            $score->delete(); // Untoggle

            // Delete corresponding gamification transaction if any
            GamificationTransaction::where('reference_type', LeaderboardScore::class)
                ->where('reference_id', $scoreId)
                ->delete();
            GamificationService::recalculateStudentState($studentId, $this->leaderboardId);
            GamificationService::syncStudentBadges($studentId, $this->leaderboardId);
        } else {
            $newScore = LeaderboardScore::create([
                'leaderboard_id' => $this->leaderboardId,
                'student_id' => $studentId,
                'leaderboard_criterion_id' => $criterionId,
                'date' => $this->date,
            ]);

            GamificationService::syncStudentCustomCriterionXP($newScore);
        }
    }

    public function saveExtraPoints(int $studentId, int|float $amount, string $notes): void
    {
        $this->ensureParticipant($studentId);

        if (! $this->mayScore($studentId)) {
            return;
        }

        $this->validate([
            'date' => 'required|date',
        ]);

        if (! $amount || ! $notes) {
            return;
        }

        $id = DB::table('leaderboard_extra_points')->insertGetId([
            'leaderboard_id' => $this->leaderboardId,
            'student_id' => $studentId,
            'date' => $this->date,
            'points' => $amount,
            'notes' => $notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        GamificationService::syncStudentExtraPointsXP($id);

        Flux::toast('تم حفظ النقاط الإضافية بنجاح', variant: 'success');
    }

    public function deleteExtraPoints($id)
    {
        $row = DB::table('leaderboard_extra_points')
            ->where('leaderboard_id', $this->leaderboardId)
            ->whereIn('student_id', $this->participatingStudents()->pluck('id'))
            ->where('id', $id)
            ->first();

        if (! $row) {
            return;
        }

        // Points of a day the teacher may not write — another day of a circle
        // they only stand in for — stay where they are.
        $student = $this->participatingStudents()->firstWhere('id', $row->student_id);

        if (! auth()->guard('teacher')->user()->mayWorkOn((int) $student->circle_id, substr((string) $row->date, 0, 10))) {
            Flux::toast('تنوب في هذه الحلقة اليوم، فلا تعدّل إلا رصد اليوم.', variant: 'warning');

            return;
        }

        DB::table('leaderboard_extra_points')->where('id', $row->id)->delete();
        GamificationService::syncStudentExtraPointsXP($id);
    }

    public function approveBadge(int $badgeId, int $studentId): void
    {
        $this->ensureCompetitionBadge($badgeId, $studentId);

        DB::table('gamification_badge_student')
            ->where('badge_id', $badgeId)
            ->where('student_id', $studentId)
            ->update([
                'status' => 'approved',
                'updated_at' => now(),
            ]);

        Flux::toast('تم اعتماد منح الوسام للطالب بنجاح', variant: 'success');
    }

    public function rejectBadge(int $badgeId, int $studentId): void
    {
        $this->ensureCompetitionBadge($badgeId, $studentId);

        DB::table('gamification_badge_student')
            ->where('badge_id', $badgeId)
            ->where('student_id', $studentId)
            ->delete();

        Flux::toast('تم رفض منح الوسام', variant: 'neutral');
    }

    private function ensureCompetitionBadge(int $badgeId, int $studentId): void
    {
        $this->ensureParticipant($studentId);

        // A badge is the student's own teacher's to grant, not a substitute's.
        $student = $this->participatingStudents()->firstWhere('id', $studentId);
        abort_unless(auth()->guard('teacher')->user()->circles()->whereKey($student->circle_id)->exists(), 403);
        abort_unless(GamificationBadge::where('leaderboard_id', $this->leaderboardId)->whereKey($badgeId)->exists(), 404);
    }

    public function render()
    {
        $leaderboard = Leaderboard::with('criteria', 'circles')->findOrFail($this->leaderboardId);

        $students = $this->participatingStudents();

        $scores = LeaderboardScore::where('leaderboard_id', $this->leaderboardId)
            ->whereDate('date', \Carbon\Carbon::parse($this->date))
            ->get()
            ->groupBy('student_id')
            ->map(function ($studentScores) {
                return $studentScores->pluck('leaderboard_criterion_id')->toArray();
            });

        $extraPointsMap = DB::table('leaderboard_extra_points')
            ->where('leaderboard_id', $this->leaderboardId)
            ->whereDate('date', \Carbon\Carbon::parse($this->date))
            ->get()
            ->groupBy('student_id');

        $service = new LeaderboardService;
        // Only the students on the page: the competition may span circles the teacher does not teach.
        $dailyScores = $service->getDailyScores($leaderboard, \Carbon\Carbon::parse($this->date)->format('Y-m-d'), $students->modelKeys());

        $pendingBadges = DB::table('gamification_badge_student')
            ->join('gamification_badges', 'gamification_badges.id', '=', 'gamification_badge_student.badge_id')
            ->join('users as students', 'students.id', '=', 'gamification_badge_student.student_id')
            ->where('gamification_badges.leaderboard_id', $this->leaderboardId)
            ->where('gamification_badge_student.status', 'pending_approval')
            ->select('gamification_badge_student.*', 'gamification_badges.name as badge_name', 'gamification_badges.icon as badge_icon', 'students.name as student_name')
            ->get();

        return view('livewire.teacher.leaderboard-grade', [
            'leaderboard' => $leaderboard,
            'students' => $students,
            'scoresMap' => $scores,
            'extraPointsMap' => $extraPointsMap,
            'dailyScores' => $dailyScores,
            'pendingBadges' => $pendingBadges,
        ]);
    }
}
