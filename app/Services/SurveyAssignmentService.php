<?php

namespace App\Services;

use App\Jobs\SendGuardianWhatsappJob;
use App\Models\Circle;
use App\Models\Form;
use App\Models\FormAssignment;
use App\Models\Guardian;
use App\Models\Manager;
use App\Models\Student;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Models\User;
use App\Support\HijriDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turning a form's audience rule into the people who owe it.
 *
 * The rule is kept rather than only its result, so it can be re-run: a guardian
 * who joins after a survey went out is picked up on the next run without
 * everybody already asked being asked twice.
 *
 * The audience shape mirrors AcademicCalendarEvent::$shared_with, which the
 * academy already uses to aim announcements at roles, stages and circles.
 */
class SurveyAssignmentService
{
    /** The roles a form may be asked of. */
    public const ROLES = ['guardian', 'student', 'teacher', 'supervisor', 'manager'];

    /**
     * Create the missing assignments for a form and return how many were added.
     *
     * Safe to call repeatedly: the unique (form_id, user_id) key means a re-run
     * only ever adds, and completed rows are never disturbed.
     */
    public static function sync(Form $form): int
    {
        $targets = self::withinAuthorsReach($form, self::resolveAudience($form));

        if ($targets->isEmpty()) {
            return 0;
        }

        $existing = FormAssignment::where('form_id', $form->id)
            ->pluck('user_id')
            ->flip();

        $new = $targets->reject(fn (array $t) => $existing->has($t['user_id']))->values();

        if ($new->isEmpty()) {
            return 0;
        }

        $now = now();
        DB::table('form_assignments')->insert(
            $new->map(fn (array $t) => [
                'form_id' => $form->id,
                'user_id' => $t['user_id'],
                'role' => $t['role'],
                'status' => 'pending',
                'due_date' => $form->due_date?->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );

        return $new->count();
    }

    /**
     * Narrow a resolved audience to the people its author is entitled to ask.
     *
     * The audience rule is written in a browser and arrives as JSON, so a
     * teacher could otherwise name every guardian in the academy simply by
     * editing what they send. Reach is therefore decided here, from the form's
     * recorded author, and never from what the request claims:
     *
     *  - a manager speaks for the academy and reaches everyone;
     *  - a supervisor reaches the circles of their own stages;
     *  - a teacher reaches their own circles — their students and those
     *    students' guardians — and nobody else.
     *
     * Everyone may always ask themselves, so a survey a teacher writes for their
     * own reflection is never refused.
     *
     * A form with no recorded author predates the morph and is left alone rather
     * than silently emptied.
     *
     * @param  Collection<int, array{user_id: int, role: string}>  $targets
     * @return Collection<int, array{user_id: int, role: string}>
     */
    public static function withinAuthorsReach(Form $form, Collection $targets): Collection
    {
        $type = $form->created_by_type;
        $authorId = $form->created_by_id;

        if ($type === null || $authorId === null || $type === 'manager') {
            return $targets;
        }

        $circleIds = match ($type) {
            'supervisor' => Circle::whereIn(
                'stage_id',
                DB::table('stage_supervisor')->where('supervisor_id', $authorId)->pluck('stage_id')
            )->pluck('id'),
            'teacher' => DB::table('circle_teacher')->where('teacher_id', $authorId)->pluck('circle_id'),
            default => collect(),
        };

        if ($circleIds->isEmpty()) {
            // Nothing of their own to reach; they may still ask themselves.
            return $targets->filter(fn (array $t) => $t['user_id'] === $authorId)->values();
        }

        $reachable = Student::whereIn('circle_id', $circleIds)->pluck('id');
        $reachable = $reachable
            ->merge(Student::whereIn('circle_id', $circleIds)->whereNotNull('guardian_id')->pluck('guardian_id'))
            ->merge(DB::table('circle_teacher')->whereIn('circle_id', $circleIds)->pluck('teacher_id'))
            ->push($authorId)
            ->unique()
            ->flip();

        return $targets->filter(fn (array $t) => $reachable->has($t['user_id']))->values();
    }

    /**
     * The people a form's audience names, as user id and the role they were
     * asked in. A person holding two targeted roles is asked once — the unique
     * key would refuse the second anyway, and being asked twice for one form is
     * never what the rule meant.
     *
     * @return Collection<int, array{user_id: int, role: string}>
     */
    public static function resolveAudience(Form $form): Collection
    {
        $audience = $form->audience ?? [];
        $resolved = collect();

        foreach (self::ROLES as $role) {
            $ids = self::idsForRole($role, $audience);

            foreach ($ids as $id) {
                $resolved->push(['user_id' => (int) $id, 'role' => $role]);
            }
        }

        return $resolved->unique('user_id')->values();
    }

    /**
     * @param  array<string, mixed>  $audience
     * @return array<int, int>
     */
    private static function idsForRole(string $role, array $audience): array
    {
        $query = self::baseQueryFor($role);

        if ($query === null) {
            return [];
        }

        $all = (bool) ($audience["all_{$role}s"] ?? false);
        $explicit = array_map('intval', $audience["{$role}_ids"] ?? []);
        $stageIds = array_map('intval', $audience["stage_ids_for_{$role}s"] ?? []);
        $circleIds = array_map('intval', $audience["circle_ids_for_{$role}s"] ?? []);

        if (! $all && $explicit === [] && $stageIds === [] && $circleIds === []) {
            return [];
        }

        if ($all) {
            return $query->pluck('id')->all();
        }

        $query->where(function ($q) use ($role, $explicit, $stageIds, $circleIds) {
            if ($explicit !== []) {
                $q->orWhereIn('users.id', $explicit);
            }

            foreach (['stage' => $stageIds, 'circle' => $circleIds] as $scope => $ids) {
                if ($ids !== []) {
                    self::applyScope($q, $role, $scope, $ids);
                }
            }
        });

        return $query->pluck('id')->all();
    }

    /**
     * Only approved, active people are ever asked — a survey sent to a pending
     * registration or a departed student is noise in the response rate.
     *
     * @return Builder<covariant User>|null
     */
    private static function baseQueryFor(string $role)
    {
        $model = match ($role) {
            'guardian' => Guardian::class,
            'student' => Student::class,
            'teacher' => Teacher::class,
            'supervisor' => Supervisor::class,
            'manager' => Manager::class,
            default => null,
        };

        if ($model === null) {
            return null;
        }

        $query = $model::query()->whereRoleState(fn ($q) => $q->where('is_approved', true));

        // Students carry a lifecycle the other roles do not.
        if ($role === 'student') {
            $query->where('status', 'active');
        }

        return $query;
    }

    /**
     * Reach a role through the stage or circle it belongs to. A guardian has no
     * stage of their own, so they are reached through the children they hold —
     * which is how the academy actually thinks of "the guardians of this stage".
     *
     * @param  array<int, int>  $ids
     */
    private static function applyScope($query, string $role, string $scope, array $ids): void
    {
        $circleIds = $scope === 'circle'
            ? $ids
            : Circle::whereIn('stage_id', $ids)->pluck('id')->all();

        match ($role) {
            'student' => $query->orWhereIn('users.circle_id', $circleIds),

            'guardian' => $query->orWhereHas(
                'students',
                fn ($q) => $q->whereIn('circle_id', $circleIds)
            ),

            'teacher' => $query->orWhereHas(
                'circles',
                fn ($q) => $q->whereIn('circles.id', $circleIds)
            ),

            'supervisor' => $scope === 'stage'
                ? $query->orWhereHas('stages', fn ($q) => $q->whereIn('stages.id', $ids))
                : $query->orWhereHas('stages', fn ($q) => $q->whereIn('stages.id', Circle::whereIn('id', $circleIds)->pluck('stage_id'))),

            // A manager answers for the academy; neither scope narrows them.
            default => null,
        };
    }

    /**
     * Narrow an audience to the people its author is entitled to ask.
     *
     * A manager speaks for the academy and is left alone. A supervisor may ask
     * only within their own stages, and a teacher only within their own circles
     * — their students, those students' guardians, and themselves. Anything the
     * rule names beyond that reach is struck out here, on the server, because
     * the audience arrives from a form the author controls and a hidden field is
     * not a permission.
     *
     * @param  array<string, mixed>  $audience
     * @return array<string, mixed>
     */
    public static function clampToAuthor(array $audience, User $author, string $role): array
    {
        if ($role === 'manager') {
            return $audience;
        }

        if ($role === 'supervisor') {
            $stageIds = $author->stages()->pluck('stages.id')->all();
            $circleIds = Circle::whereIn('stage_id', $stageIds)->pluck('id')->all();
        } elseif ($role === 'teacher') {
            $circleIds = $author->circles()->pluck('circles.id')->all();
            $stageIds = Circle::whereIn('id', $circleIds)->pluck('stage_id')->unique()->values()->all();
        } else {
            return [];
        }

        $clamped = [];

        foreach (self::ROLES as $target) {
            // "All of a role" is an academy-wide reach, so it becomes the
            // author's own stages instead of being honoured or silently dropped.
            $wantsAll = (bool) ($audience["all_{$target}s"] ?? false);

            $stages = array_values(array_intersect(
                array_map('intval', $audience["stage_ids_for_{$target}s"] ?? []),
                $stageIds,
            ));

            $circles = array_values(array_intersect(
                array_map('intval', $audience["circle_ids_for_{$target}s"] ?? []),
                $circleIds,
            ));

            if ($wantsAll) {
                $role === 'teacher'
                    ? $circles = $circleIds
                    : $stages = $stageIds;
            }

            $named = self::reachableNamed(
                array_map('intval', $audience["{$target}_ids"] ?? []),
                $target,
                $circleIds,
                $stageIds,
                $author,
            );

            if ($stages !== []) {
                $clamped["stage_ids_for_{$target}s"] = $stages;
            }
            if ($circles !== []) {
                $clamped["circle_ids_for_{$target}s"] = $circles;
            }
            if ($named !== []) {
                $clamped["{$target}_ids"] = $named;
            }
        }

        return $clamped;
    }

    /**
     * Of the people named outright, those the author could have reached anyway.
     *
     * @param  array<int, int>  $ids
     * @param  array<int, int>  $circleIds
     * @param  array<int, int>  $stageIds
     * @return array<int, int>
     */
    private static function reachableNamed(array $ids, string $target, array $circleIds, array $stageIds, User $author): array
    {
        if ($ids === []) {
            return [];
        }

        // Asking yourself is always allowed.
        $self = in_array($author->id, $ids, true) ? [$author->id] : [];

        $query = self::baseQueryFor($target);

        if ($query === null) {
            return $self;
        }

        $reachable = $query->whereIn('users.id', $ids)
            ->where(function ($q) use ($target, $circleIds, $stageIds) {
                match ($target) {
                    'student' => $q->whereIn('users.circle_id', $circleIds),
                    'guardian' => $q->whereHas('students', fn ($sq) => $sq->whereIn('circle_id', $circleIds)),
                    'teacher' => $q->whereHas('circles', fn ($sq) => $sq->whereIn('circles.id', $circleIds)),
                    'supervisor' => $q->whereHas('stages', fn ($sq) => $sq->whereIn('stages.id', $stageIds)),
                    // A manager is nobody's to summon but their own.
                    default => $q->whereRaw('1 = 0'),
                };
            })
            ->pluck('id')
            ->all();

        return array_values(array_unique(array_merge($reachable, $self)));
    }

    /**
     * Tell everyone newly asked, in the app and — for guardians, who live
     * outside it — on WhatsApp. Called after sync() so a failing message can
     * never cost the assignment it was announcing.
     */
    public static function notifyPending(Form $form): int
    {
        $pending = FormAssignment::where('form_id', $form->id)
            ->whereNull('notified_at')
            ->with('user')
            ->get();

        $url = route('forms.submit', $form->slug);
        $due = $form->due_date ? ' قبل '.HijriDate::full($form->due_date) : '';
        $sent = 0;

        foreach ($pending as $assignment) {
            NotificationService::notify(
                $assignment->role,
                $assignment->user_id,
                'survey',
                'استبانة مطلوبة منك',
                "{$form->title}{$due}",
                $url,
                ['form_id' => $form->id, 'assignment_id' => $assignment->id],
            );

            if ($assignment->role === 'guardian') {
                self::pushWhatsappTo($assignment, $form, $url, $due);
            }

            $assignment->update(['notified_at' => now()]);
            $sent++;
        }

        return $sent;
    }

    /**
     * Guardians are the one audience that mostly lives outside the app, so the
     * link goes to them on WhatsApp as well.
     *
     * A guardian with no phone, or a circle with no sending session configured,
     * simply gets the in-app notification — the survey is still theirs to answer,
     * and a missing gateway must never cost them the assignment.
     */
    private static function pushWhatsappTo(FormAssignment $assignment, Form $form, string $url, string $due): void
    {
        $guardian = $assignment->user;

        if (! $guardian?->phone) {
            return;
        }

        // The sender session hangs off a circle, so it is resolved through one of
        // the guardian's children rather than the guardian, who has no circle.
        $child = Student::where('guardian_id', $guardian->id)
            ->whereNotNull('circle_id')
            ->first();

        $sender = $child ? GuardianNotificationService::resolveWhatsappSender($child) : null;

        if (! $sender) {
            return;
        }

        $message = "السلام عليكم ورحمة الله وبركاته\n"
            ."مطلوب تعبئة استبانة: {$form->title}{$due}\n"
            .$url;

        SendGuardianWhatsappJob::dispatch($guardian->phone, $message, $sender);
    }

    /**
     * Mark a person's assignment done when their response lands, so the app
     * reopens for them and the response rate moves.
     */
    public static function completeFor(Form $form, int $userId, ?int $responseId = null): void
    {
        FormAssignment::where('form_id', $form->id)
            ->where('user_id', $userId)
            ->pending()
            ->each(fn (FormAssignment $a) => $a->markCompleted($responseId));
    }
}
