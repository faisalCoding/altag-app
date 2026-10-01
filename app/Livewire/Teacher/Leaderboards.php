<?php

namespace App\Livewire\Teacher;

use App\Models\Leaderboard;
use App\Models\LeaderboardCriterion;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Leaderboards extends Component
{
    #[Locked]
    public $circleId;

    public $leaderboards = [];

    public $supervisorCompetitions = [];

    // Modal state
    public $isEditing = false;

    public $showModal = false;

    #[Locked]
    public $leaderboardId = null;

    // Form fields
    public $title = '';

    public $start_date = '';

    public $end_date = '';

    public $is_active = true;

    // Settings
    public $hifz_enabled = true;

    public $hifz_excellent = 10;

    public $hifz_good = 7;

    public $hifz_acceptable = 4;

    public $review_enabled = true;

    public $review_excellent = 5;

    public $review_good = 3;

    public $attendance_enabled = true;

    public $attendance_present = 4;

    public $attendance_late = 2;

    public $extra_points_enabled = true;

    // Custom Criteria
    public $criteria = [];

    protected function rules()
    {
        return [
            'title' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'boolean',
            'hifz_excellent' => 'numeric|min:0',
            'hifz_good' => 'numeric|min:0',
            'hifz_acceptable' => 'numeric|min:0',
            'review_excellent' => 'numeric|min:0',
            'review_good' => 'numeric|min:0',
            'attendance_present' => 'numeric|min:0',
            'attendance_late' => 'numeric|min:0',
            'criteria.*.name' => 'required|string|max:255',
            'criteria.*.points' => 'required|numeric|min:0',
        ];
    }

    #[Locked]
    public $circleIds = [];

    public function mount()
    {
        $teacher = Auth::guard('teacher')->user();
        $this->circleIds = $teacher->circles()->pluck('circles.id')->all();

        if (! empty($this->circleIds)) {
            $this->circleId = $this->circleIds[0];
            $this->loadLeaderboards();
        }
    }

    /**
     * A competition this teacher set up for one of their own circles — the
     * only kind the list offers to edit, pause or delete. A supervisor's
     * competition is never one, though it may include the same circles.
     */
    private function ownLeaderboard($id): Leaderboard
    {
        return Leaderboard::whereIn('circle_id', $this->circleIds)
            ->whereNull('supervisor_id')
            ->findOrFail($id);
    }

    public function loadLeaderboards()
    {
        $this->leaderboards = Leaderboard::whereIn('circle_id', $this->circleIds)
            ->whereNull('supervisor_id')      // Teacher-created only
            ->withCount('criteria')
            ->orderBy('id', 'desc')
            ->get();

        // Supervisor competitions that include any of this teacher's circles
        $this->supervisorCompetitions = Leaderboard::whereHas('circles', function ($q) {
            $q->whereIn('circles.id', $this->circleIds);
        })
            ->whereNotNull('supervisor_id')
            ->withCount('criteria')
            ->with('supervisor')
            ->orderByDesc('is_active')  // Active ones first
            ->orderByDesc('created_at')
            ->get();
    }

    public function create()
    {
        $this->resetValidation();
        $this->reset('title', 'end_date', 'leaderboardId');

        $this->start_date = now()->format('Y-m-d');
        $this->is_active = true;

        $this->hifz_enabled = true;
        $this->hifz_excellent = 10;
        $this->hifz_good = 7;
        $this->hifz_acceptable = 4;

        $this->review_enabled = true;
        $this->review_excellent = 5;
        $this->review_good = 3;

        $this->attendance_enabled = true;
        $this->attendance_present = 4;
        $this->attendance_late = 2;

        $this->extra_points_enabled = true;

        $this->criteria = [];
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function edit($id)
    {
        $this->resetValidation();
        $leaderboard = $this->ownLeaderboard($id)->load('criteria');

        $this->leaderboardId = $leaderboard->id;
        $this->title = $leaderboard->title;
        $this->start_date = $leaderboard->start_date->format('Y-m-d');
        $this->end_date = $leaderboard->end_date ? $leaderboard->end_date->format('Y-m-d') : null;
        $this->is_active = $leaderboard->is_active;

        $settings = $leaderboard->settings ?? [];
        $this->hifz_enabled = $settings['hifz_enabled'] ?? true;
        $this->hifz_excellent = $settings['hifz_excellent'] ?? 10;
        $this->hifz_good = $settings['hifz_good'] ?? 7;
        $this->hifz_acceptable = $settings['hifz_acceptable'] ?? 4;

        $this->review_enabled = $settings['review_enabled'] ?? true;
        $this->review_excellent = $settings['review_excellent'] ?? 5;
        $this->review_good = $settings['review_good'] ?? 3;

        $this->attendance_enabled = $settings['attendance_enabled'] ?? true;
        $this->attendance_present = $settings['attendance_present'] ?? 4;
        $this->attendance_late = $settings['attendance_late'] ?? 2;

        $this->extra_points_enabled = $settings['extra_points_enabled'] ?? true;

        $this->criteria = $leaderboard->criteria->map(function ($criterion) {
            return [
                'id' => $criterion->id,
                'name' => $criterion->name,
                'points' => $criterion->points,
            ];
        })->toArray();

        $this->isEditing = true;
        $this->showModal = true;
    }

    public function addCriterion()
    {
        $this->criteria[] = ['name' => '', 'points' => 5];
    }

    public function removeCriterion($index)
    {
        unset($this->criteria[$index]);
        $this->criteria = array_values($this->criteria);
    }

    public function toggleActive($id)
    {
        $board = $this->ownLeaderboard($id);
        $board->is_active = ! $board->is_active;
        $board->save();
        $this->loadLeaderboards();
    }

    public function toggleActiveForGrading($id)
    {
        $board = Leaderboard::findOrFail($id);

        if (! $board->is_active_for_grading) {
            // Unset for all other teacher's leaderboards across all their circles
            Leaderboard::whereIn('circle_id', $this->circleIds)
                ->whereNull('supervisor_id')
                ->where('id', '!=', $id)
                ->update(['is_active_for_grading' => false]);
        }

        $board->is_active_for_grading = ! $board->is_active_for_grading;
        $board->save();
        $this->loadLeaderboards();
    }

    public function save()
    {
        $this->validate();

        $settings = [
            'hifz_enabled' => $this->hifz_enabled,
            'hifz_excellent' => (int) $this->hifz_excellent,
            'hifz_good' => (int) $this->hifz_good,
            'hifz_acceptable' => (int) $this->hifz_acceptable,

            'review_enabled' => $this->review_enabled,
            'review_excellent' => (int) $this->review_excellent,
            'review_good' => (int) $this->review_good,

            'attendance_enabled' => $this->attendance_enabled,
            'attendance_present' => (int) $this->attendance_present,
            'attendance_late' => (int) $this->attendance_late,

            'extra_points_enabled' => $this->extra_points_enabled,
        ];

        $leaderboard = Leaderboard::updateOrCreate(
            ['id' => $this->leaderboardId],
            [
                'circle_id' => $this->circleId,
                'title' => $this->title,
                'start_date' => $this->start_date,
                'end_date' => $this->end_date ?: null,
                'is_active' => $this->is_active,
                'settings' => $settings,
            ]
        );

        // Sync Criteria
        $existingCriteriaIds = collect($this->criteria)->pluck('id')->filter()->toArray();
        LeaderboardCriterion::where('leaderboard_id', $leaderboard->id)
            ->whereNotIn('id', $existingCriteriaIds)
            ->delete();

        foreach ($this->criteria as $criterionConfig) {
            LeaderboardCriterion::updateOrCreate(
                [
                    'id' => $criterionConfig['id'] ?? null,
                    'leaderboard_id' => $leaderboard->id,
                ],
                [
                    'name' => $criterionConfig['name'],
                    'points' => $criterionConfig['points'],
                ]
            );
        }

        $this->showModal = false;
        $this->loadLeaderboards();
    }

    public function deleteLeaderboard($id)
    {
        $this->ownLeaderboard($id)->delete();
        $this->loadLeaderboards();
    }

    public function render()
    {
        return view('livewire.teacher.leaderboards');
    }
}
