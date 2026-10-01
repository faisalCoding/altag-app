<?php

namespace App\Livewire\Supervisor;

use App\Models\Circle;
use App\Models\Leaderboard;
use App\Models\LeaderboardCriterion;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class Competitions extends Component
{
    use WithFileUploads;

    public $coin_image_file = null;

    public $xp_image_file = null;

    public $team_image_file = null;

    public $competitions;

    // Form
    public bool $showModal = false;

    public bool $isEditing = false;

    #[Locked]
    public $editingId = null;

    public int $currentStep = 1;

    public string $title = '';

    public string $competition_type = 'normal';

    public string $custom_theme_name = 'فرسان الحفظ';

    public string $custom_theme_color = '#4f46e5';

    public string $custom_theme_currency = 'جوهرة';

    public string $custom_theme_currency_emoji = '💎';

    public string $custom_theme_xp = 'طاقة الخبرة';

    public string $custom_theme_xp_emoji = '✨';

    public string $custom_theme_team = 'كتيبة';

    public string $custom_theme_team_plural = 'كتائب';

    public string $custom_theme_team_emoji = '🛡️';

    public string $custom_theme_team_possessive_your = 'كتيبتك';

    public string $custom_theme_team_possessive_my = 'كتيبتي';

    public string $start_date = '';

    public string $end_date = '';

    public bool $is_active = true;

    public array $selectedCircles = [];

    // Settings (mirrors teacher leaderboard settings)
    public bool $hifz_enabled = true;

    public int $hifz_excellent = 10;

    public int $hifz_good = 7;

    public int $hifz_acceptable = 4;

    public bool $review_enabled = true;

    public int $review_excellent = 5;

    public int $review_good = 3;

    public bool $attendance_enabled = true;

    public int $attendance_present = 4;

    public int $attendance_late = 2;

    public bool $extra_points_enabled = true;

    public bool $ode_hifz_enabled = false;

    public int $ode_hifz_excellent = 10;

    public int $ode_hifz_good = 7;

    public int $ode_hifz_acceptable = 4;

    public bool $ode_review_enabled = false;

    public int $ode_review_excellent = 5;

    public int $ode_review_good = 3;

    public bool $hadith_hifz_enabled = false;

    public int $hadith_hifz_excellent = 10;

    public int $hadith_hifz_good = 7;

    public int $hadith_hifz_acceptable = 4;

    public bool $hadith_review_enabled = false;

    public int $hadith_review_excellent = 5;

    public int $hadith_review_good = 3;

    public array $criteria = [];

    public $circlesList = [];

    protected function rules(): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'boolean',
            'selectedCircles' => 'required|array|min:1',
            'selectedCircles.*' => 'exists:circles,id',
            'criteria.*.name' => 'required|string|max:255',
            'criteria.*.points' => 'required|numeric|min:0',
            'competition_type' => 'required|in:normal,gamification',
        ];

        if ($this->competition_type === 'gamification') {
            $rules['custom_theme_name'] = 'required|string|max:255';
            $rules['custom_theme_color'] = 'required|string|max:7';
            $rules['custom_theme_currency'] = 'required|string|max:255';
            $rules['custom_theme_currency_emoji'] = 'required|string|max:255';
            $rules['custom_theme_xp'] = 'required|string|max:255';
            $rules['custom_theme_team'] = 'required|string|max:255';
            $rules['custom_theme_team_plural'] = 'required|string|max:255';
            $rules['custom_theme_team_emoji'] = 'required|string|max:255';
            $rules['custom_theme_team_possessive_your'] = 'required|string|max:255';
            $rules['custom_theme_team_possessive_my'] = 'required|string|max:255';

            $rules['coin_image_file'] = 'nullable|image|max:10240';
            $rules['team_image_file'] = 'nullable|image|max:10240';
        }

        return $rules;
    }

    protected $messages = [
        'selectedCircles.required' => 'يجب اختيار حلقة واحدة على الأقل.',
        'selectedCircles.min' => 'يجب اختيار حلقة واحدة على الأقل.',
    ];

    public function mount(): void
    {
        $this->loadData();

        if (request()->query('create_gamification') == '1') {
            $this->create();
            $this->competition_type = 'gamification';
        }
    }

    private function getSupervisorCircleIds(): array
    {
        $supervisor = auth()->guard('supervisor')->user();

        return Circle::whereIn('stage_id', $supervisor->stages()->pluck('stages.id'))->pluck('id')->toArray();
    }

    public function loadData(): void
    {
        $circleIds = $this->getSupervisorCircleIds();
        $supervisorId = auth()->guard('supervisor')->id();

        $this->circlesList = Circle::with('stage')->whereIn('id', $circleIds)->get();

        $this->competitions = Leaderboard::with(['circles.stage'])
            ->withCount('criteria')
            ->where('supervisor_id', $supervisorId)
            ->latest()
            ->get();
    }

    public function create(): void
    {
        $this->reset('title', 'end_date', 'editingId', 'selectedCircles', 'criteria');
        $this->coin_image_file = null;
        $this->xp_image_file = null;
        $this->team_image_file = null;
        $this->competition_type = 'normal';
        $this->custom_theme_name = 'فرسان الحفظ';
        $this->custom_theme_color = '#4f46e5';
        $this->custom_theme_currency = 'جوهرة';
        $this->custom_theme_currency_emoji = '💎';
        $this->custom_theme_xp = 'طاقة الخبرة';
        $this->custom_theme_xp_emoji = '✨';
        $this->custom_theme_team = 'كتيبة';
        $this->custom_theme_team_plural = 'كتائب';
        $this->custom_theme_team_emoji = '🛡️';
        $this->custom_theme_team_possessive_your = 'كتيبتك';
        $this->custom_theme_team_possessive_my = 'كتيبتي';
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
        $this->ode_hifz_enabled = false;
        $this->ode_hifz_excellent = 10;
        $this->ode_hifz_good = 7;
        $this->ode_hifz_acceptable = 4;
        $this->ode_review_enabled = false;
        $this->ode_review_excellent = 5;
        $this->ode_review_good = 3;
        $this->hadith_hifz_enabled = false;
        $this->hadith_hifz_excellent = 10;
        $this->hadith_hifz_good = 7;
        $this->hadith_hifz_acceptable = 4;
        $this->hadith_review_enabled = false;
        $this->hadith_review_excellent = 5;
        $this->hadith_review_good = 3;
        $this->isEditing = false;
        $this->currentStep = 1;
        $this->showModal = true;
    }

    public function nextStep(): void
    {
        if ($this->currentStep === 1) {
            $this->validateOnly('competition_type');
            if ($this->competition_type === 'gamification') {
                $this->validateOnly('custom_theme_name');
                $this->validateOnly('custom_theme_color');
                $this->validateOnly('custom_theme_currency');
                $this->validateOnly('custom_theme_currency_emoji');
                $this->validateOnly('custom_theme_xp');
                $this->validateOnly('custom_theme_xp_emoji');
                $this->validateOnly('custom_theme_team');
                $this->validateOnly('custom_theme_team_plural');
                $this->validateOnly('custom_theme_team_emoji');
                $this->validateOnly('custom_theme_team_possessive_your');
                $this->validateOnly('custom_theme_team_possessive_my');
            }
        } elseif ($this->currentStep === 2) {
            $this->validateOnly('title');
        } elseif ($this->currentStep === 3) {
            $this->validateOnly('start_date');
            $this->validateOnly('end_date');
        }

        $this->currentStep++;
    }

    public function prevStep(): void
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    public function edit($id): void
    {
        $supervisorId = auth()->guard('supervisor')->id();
        $competition = Leaderboard::with('criteria', 'circles')
            ->where('supervisor_id', $supervisorId)
            ->findOrFail($id);

        $this->editingId = $competition->id;
        $this->coin_image_file = null;
        $this->xp_image_file = null;
        $this->team_image_file = null;
        $this->competition_type = $competition->competition_type ?? 'normal';
        $this->title = $competition->title;
        $this->start_date = $competition->start_date->format('Y-m-d');
        $this->end_date = $competition->end_date ? $competition->end_date->format('Y-m-d') : '';
        $this->is_active = $competition->is_active;
        $this->selectedCircles = $competition->circles->pluck('id')->toArray();

        $settings = $competition->settings ?? [];
        if (isset($settings['theme'])) {
            $t = $settings['theme'];
            $this->custom_theme_name = $t['name'] ?? 'فرسان الحفظ';
            $this->custom_theme_color = $t['color'] ?? '#4f46e5';
            $this->custom_theme_currency = $t['currency_name'] ?? 'جوهرة';
            $this->custom_theme_currency_emoji = $t['coin_emoji'] ?? '💎';
            $this->custom_theme_xp = $t['xp_name'] ?? 'طاقة الخبرة';
            $this->custom_theme_xp_emoji = $t['xp_emoji'] ?? '✨';
            $this->custom_theme_team = $t['team_name'] ?? 'كتيبة';
            $this->custom_theme_team_plural = $t['team_plural'] ?? 'كتائب';
            $this->custom_theme_team_emoji = $t['team_emoji'] ?? '🛡️';
            $this->custom_theme_team_possessive_your = $t['team_possessive_your'] ?? 'كتيبتك';
            $this->custom_theme_team_possessive_my = $t['team_possessive_my'] ?? 'كتيبتي';
        } else {
            $this->custom_theme_name = 'فرسان الحفظ';
            $this->custom_theme_color = '#4f46e5';
            $this->custom_theme_currency = 'جوهرة';
            $this->custom_theme_currency_emoji = '💎';
            $this->custom_theme_xp = 'طاقة الخبرة';
            $this->custom_theme_xp_emoji = '✨';
            $this->custom_theme_team = 'كتيبة';
            $this->custom_theme_team_plural = 'كتائب';
            $this->custom_theme_team_emoji = '🛡️';
            $this->custom_theme_team_possessive_your = 'كتيبتك';
            $this->custom_theme_team_possessive_my = 'كتيبتي';
        }
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
        $this->ode_hifz_enabled = $settings['ode_hifz_enabled'] ?? false;
        $this->ode_hifz_excellent = $settings['ode_hifz_excellent'] ?? 10;
        $this->ode_hifz_good = $settings['ode_hifz_good'] ?? 7;
        $this->ode_hifz_acceptable = $settings['ode_hifz_acceptable'] ?? 4;
        $this->ode_review_enabled = $settings['ode_review_enabled'] ?? false;
        $this->ode_review_excellent = $settings['ode_review_excellent'] ?? 5;
        $this->ode_review_good = $settings['ode_review_good'] ?? 3;
        $this->hadith_hifz_enabled = $settings['hadith_hifz_enabled'] ?? false;
        $this->hadith_hifz_excellent = $settings['hadith_hifz_excellent'] ?? 10;
        $this->hadith_hifz_good = $settings['hadith_hifz_good'] ?? 7;
        $this->hadith_hifz_acceptable = $settings['hadith_hifz_acceptable'] ?? 4;
        $this->hadith_review_enabled = $settings['hadith_review_enabled'] ?? false;
        $this->hadith_review_excellent = $settings['hadith_review_excellent'] ?? 5;
        $this->hadith_review_good = $settings['hadith_review_good'] ?? 3;

        $this->criteria = $competition->criteria->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'points' => $c->points,
        ])->toArray();

        $this->isEditing = true;
        $this->showModal = true;
    }

    public function addCriterion(): void
    {
        $this->criteria[] = ['name' => '', 'points' => 5];
    }

    public function removeCriterion($index): void
    {
        unset($this->criteria[$index]);
        $this->criteria = array_values($this->criteria);
    }

    public function toggleActive($id): void
    {
        $supervisorId = auth()->guard('supervisor')->id();
        $competition = Leaderboard::where('supervisor_id', $supervisorId)->findOrFail($id);
        $competition->is_active = ! $competition->is_active;
        $competition->save();
        $this->loadData();
        Flux::toast($competition->is_active ? 'تم تنشيط المسابقة' : 'تم إيقاف المسابقة', variant: 'success');
    }

    public function toggleActiveForGrading($id): void
    {
        $supervisorId = auth()->guard('supervisor')->id();
        $competition = Leaderboard::where('supervisor_id', $supervisorId)->findOrFail($id);

        if (! $competition->is_active_for_grading) {
            Leaderboard::where('supervisor_id', $supervisorId)
                ->where('id', '!=', $id)
                ->update(['is_active_for_grading' => false]);
        }

        $competition->is_active_for_grading = ! $competition->is_active_for_grading;
        $competition->save();
        $this->loadData();
        Flux::toast($competition->is_active_for_grading ? 'تم تعيينها كالمسابقة الأساسية للتسجيل' : 'تم إلغاء التعيين', variant: 'success');
    }

    public function save(): void
    {
        $this->validate();

        // Process coin/currency icon file upload and convert to WebP
        if ($this->coin_image_file) {
            $manager = new ImageManager(new Driver);
            $tempPath = $this->coin_image_file->getRealPath();
            $image = $manager->decode($tempPath);
            $image->scale(height: 128);
            $webpData = $image->encode(new WebpEncoder(80))->toString();
            $filename = 'custom_themes/'.uniqid().'_coin.webp';
            Storage::disk('public')->put($filename, $webpData);
            $this->custom_theme_currency_emoji = $filename;
        }

        // Process Team icon file upload and convert to WebP
        if ($this->team_image_file) {
            $manager = new ImageManager(new Driver);
            $tempPath = $this->team_image_file->getRealPath();
            $image = $manager->decode($tempPath);
            $image->scale(height: 128);
            $webpData = $image->encode(new WebpEncoder(80))->toString();
            $filename = 'custom_themes/'.uniqid().'_team.webp';
            Storage::disk('public')->put($filename, $webpData);
            $this->custom_theme_team_emoji = $filename;
        }

        $supervisorId = auth()->guard('supervisor')->id();
        $allowedCircleIds = $this->getSupervisorCircleIds();

        // Ensure selected circles are within scope
        $validCircles = array_intersect($this->selectedCircles, $allowedCircleIds);
        $existingCompetition = $this->editingId ? Leaderboard::find($this->editingId) : null;
        $existingSettings = $existingCompetition ? ($existingCompetition->settings ?? []) : [];

        $settings = [
            'extra_points_enabled' => $this->extra_points_enabled,
            'enthusiasm_enabled' => $this->competition_type === 'gamification' || ($existingSettings['enthusiasm_enabled'] ?? false),
            'enthusiasm_type' => $existingSettings['enthusiasm_type'] ?? 'both',
            'enthusiasm_min_grade' => $existingSettings['enthusiasm_min_grade'] ?? 2,
            'team_purchase_voting_enabled' => $existingSettings['team_purchase_voting_enabled'] ?? true,
        ];

        if ($this->competition_type === 'gamification') {
            $settings['hifz_enabled'] = $existingSettings['hifz_enabled'] ?? true;
            $settings['hifz_excellent_xp'] = $existingSettings['hifz_excellent_xp'] ?? ($existingSettings['hifz_excellent'] ?? 10);
            $settings['hifz_excellent_coins'] = $existingSettings['hifz_excellent_coins'] ?? ($existingSettings['hifz_excellent'] ?? 10);
            $settings['hifz_good_xp'] = $existingSettings['hifz_good_xp'] ?? ($existingSettings['hifz_good'] ?? 7);
            $settings['hifz_good_coins'] = $existingSettings['hifz_good_coins'] ?? ($existingSettings['hifz_good'] ?? 7);
            $settings['hifz_acceptable_xp'] = $existingSettings['hifz_acceptable_xp'] ?? ($existingSettings['hifz_acceptable'] ?? 4);
            $settings['hifz_acceptable_coins'] = $existingSettings['hifz_acceptable_coins'] ?? ($existingSettings['hifz_acceptable'] ?? 4);
            $settings['hifz_enthusiasm_trigger'] = $existingSettings['hifz_enthusiasm_trigger'] ?? true;

            $settings['review_enabled'] = $existingSettings['review_enabled'] ?? true;
            $settings['review_excellent_xp'] = $existingSettings['review_excellent_xp'] ?? ($existingSettings['review_excellent'] ?? 5);
            $settings['review_excellent_coins'] = $existingSettings['review_excellent_coins'] ?? ($existingSettings['review_excellent'] ?? 5);
            $settings['review_good_xp'] = $existingSettings['review_good_xp'] ?? ($existingSettings['review_good'] ?? 3);
            $settings['review_good_coins'] = $existingSettings['review_good_coins'] ?? ($existingSettings['review_good'] ?? 3);
            $settings['review_enthusiasm_trigger'] = $existingSettings['review_enthusiasm_trigger'] ?? true;

            $settings['attendance_enabled'] = $existingSettings['attendance_enabled'] ?? true;
            $settings['attendance_present_xp'] = $existingSettings['attendance_present_xp'] ?? ($existingSettings['attendance_present'] ?? 4);
            $settings['attendance_present_coins'] = $existingSettings['attendance_present_coins'] ?? ($existingSettings['attendance_present'] ?? 4);
            $settings['attendance_late_xp'] = $existingSettings['attendance_late_xp'] ?? ($existingSettings['attendance_late'] ?? 2);
            $settings['attendance_late_coins'] = $existingSettings['attendance_late_coins'] ?? ($existingSettings['attendance_late'] ?? 2);
            $settings['attendance_enthusiasm_trigger'] = $existingSettings['attendance_enthusiasm_trigger'] ?? true;

            if (isset($existingSettings['streak_freeze_levels'])) {
                $settings['streak_freeze_levels'] = $existingSettings['streak_freeze_levels'];
            }

            $settings['theme'] = [
                'name' => $this->custom_theme_name,
                'color' => $this->custom_theme_color,
                'currency_name' => $this->custom_theme_currency,
                'coin_emoji' => $this->custom_theme_currency_emoji,
                'xp_name' => $this->custom_theme_xp,
                'xp_emoji' => $this->custom_theme_xp_emoji,
                'team_name' => $this->custom_theme_team,
                'team_plural' => $this->custom_theme_team_plural,
                'team_emoji' => $this->custom_theme_team_emoji,
                'team_possessive_your' => $this->custom_theme_team_possessive_your,
                'team_possessive_my' => $this->custom_theme_team_possessive_my,
            ];
        } else {
            $settings['hifz_enabled'] = $this->hifz_enabled;
            $settings['hifz_excellent'] = (int) $this->hifz_excellent;
            $settings['hifz_good'] = (int) $this->hifz_good;
            $settings['hifz_acceptable'] = (int) $this->hifz_acceptable;
            $settings['review_enabled'] = $this->review_enabled;
            $settings['review_excellent'] = (int) $this->review_excellent;
            $settings['review_good'] = (int) $this->review_good;
            $settings['attendance_enabled'] = $this->attendance_enabled;
            $settings['attendance_present'] = (int) $this->attendance_present;
            $settings['attendance_late'] = (int) $this->attendance_late;
            $settings['ode_hifz_enabled'] = $this->ode_hifz_enabled;
            $settings['ode_hifz_excellent'] = (int) $this->ode_hifz_excellent;
            $settings['ode_hifz_good'] = (int) $this->ode_hifz_good;
            $settings['ode_hifz_acceptable'] = (int) $this->ode_hifz_acceptable;
            $settings['ode_review_enabled'] = $this->ode_review_enabled;
            $settings['ode_review_excellent'] = (int) $this->ode_review_excellent;
            $settings['ode_review_good'] = (int) $this->ode_review_good;
            $settings['hadith_hifz_enabled'] = $this->hadith_hifz_enabled;
            $settings['hadith_hifz_excellent'] = (int) $this->hadith_hifz_excellent;
            $settings['hadith_hifz_good'] = (int) $this->hadith_hifz_good;
            $settings['hadith_hifz_acceptable'] = (int) $this->hadith_hifz_acceptable;
            $settings['hadith_review_enabled'] = $this->hadith_review_enabled;
            $settings['hadith_review_excellent'] = (int) $this->hadith_review_excellent;
            $settings['hadith_review_good'] = (int) $this->hadith_review_good;
        }

        $competition = Leaderboard::updateOrCreate(
            ['id' => $this->editingId, 'supervisor_id' => $supervisorId],
            [
                'supervisor_id' => $supervisorId,
                'circle_id' => $validCircles[0] ?? null, // primary circle (backward-compat)
                'title' => $this->title,
                'start_date' => $this->start_date,
                'end_date' => $this->end_date ?: null,
                'is_active' => $this->is_active,
                'competition_type' => $this->competition_type,
                'settings' => $settings,
            ]
        );

        // Sync participating circles
        $competition->circles()->sync($validCircles);

        // Sync custom criteria
        $existingIds = collect($this->criteria)->pluck('id')->filter()->toArray();
        LeaderboardCriterion::where('leaderboard_id', $competition->id)
            ->whereNotIn('id', $existingIds)
            ->delete();

        foreach ($this->criteria as $c) {
            LeaderboardCriterion::updateOrCreate(
                ['id' => $c['id'] ?? null, 'leaderboard_id' => $competition->id],
                ['name' => $c['name'], 'points' => $c['points']]
            );
        }

        Flux::toast($this->isEditing ? 'تم تحديث المسابقة بنجاح' : 'تم إنشاء المسابقة بنجاح', variant: 'success');
        $this->showModal = false;
        $this->loadData();
    }

    public function delete($id): void
    {
        $supervisorId = auth()->guard('supervisor')->id();
        Leaderboard::where('supervisor_id', $supervisorId)->findOrFail($id)->delete();
        $this->loadData();
        Flux::toast('تم حذف المسابقة', variant: 'success');
    }

    public function render()
    {
        return view('livewire.supervisor.competitions')
            ->layout('layouts.role-shell');
    }
}
