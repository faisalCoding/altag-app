<?php

namespace App\Support;

use App\Models\RuleAcknowledgement;
use App\Models\RuleSection;
use App\Models\RuleVersion;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The circles' rules: what each person reads, whether they owe their word on
 * it, and how a version comes to be.
 *
 * The rules speak to teachers and supervisors; each clause is meant for
 * everyone or for one of the two. A person reads the shared clauses and those
 * of every role they hold, and one acknowledgement covers them all — a
 * supervisor who also teaches gives their word once.
 *
 * Publishing freezes the manager's draft into a version. A version published
 * as needing it asks everyone afresh; a wording fix does not, and the word
 * given on an earlier version still stands. Owing it never locks anyone out:
 * the site reminds them, and the manager follows up.
 */
final class CircleRules
{
    /** The roles the rules speak to. */
    public const ROLES = ['teacher', 'supervisor'];

    public const ROLE_LABELS = ['teacher' => 'معلم', 'supervisor' => 'مشرف'];

    /** The version people read: the last published. */
    public static function current(): ?RuleVersion
    {
        return RuleVersion::orderByDesc('number')->first();
    }

    /** The version everyone must have given their word on: the last that asked for it. */
    public static function binding(): ?RuleVersion
    {
        return RuleVersion::where('requires_acknowledgement', true)->orderByDesc('number')->first();
    }

    /**
     * The roles of a person the rules speak to, as approved.
     *
     * @return list<string>
     */
    public static function rolesOf(User $user): array
    {
        $held = $user->roles()->where('is_approved', true)->pluck('role')->all();

        return array_values(array_intersect(self::ROLES, $held));
    }

    /**
     * The sections of a version a person with these roles reads, the clauses
     * numbered right through. Sections left with nothing for them are dropped.
     *
     * @param  list<string>  $roles
     * @return list<array{title: string, clauses: list<array{number: int, audience: string, body: string}>}>
     */
    public static function textFor(RuleVersion $version, array $roles): array
    {
        $number = 0;
        $sections = [];

        foreach ($version->content as $section) {
            $clauses = [];

            foreach ($section['clauses'] as $clause) {
                if ($clause['audience'] === 'shared' || in_array($clause['audience'], $roles, true)) {
                    $clauses[] = ['number' => ++$number, 'audience' => $clause['audience'], 'body' => $clause['body']];
                }
            }

            if ($clauses !== []) {
                $sections[] = ['title' => $section['title'], 'clauses' => $clauses];
            }
        }

        return $sections;
    }

    /**
     * A fingerprint of the text a person read.
     *
     * @param  list<array{title: string, clauses: list<array{number: int, audience: string, body: string}>}>  $text
     */
    public static function hashOf(array $text): string
    {
        return hash('sha256', json_encode($text, JSON_UNESCAPED_UNICODE));
    }

    /**
     * The person's latest word given on or after the binding version.
     */
    public static function standingAcknowledgement(User $user): ?RuleAcknowledgement
    {
        $binding = self::binding();

        if (! $binding) {
            return null;
        }

        return RuleAcknowledgement::where('user_id', $user->id)
            ->whereHas('version', fn ($q) => $q->where('number', '>=', $binding->number))
            ->with('version')
            ->latest('acknowledged_at')
            ->first();
    }

    /**
     * Where every teacher and supervisor stands on the binding version: their
     * roles, the roles they still owe their word for, and their latest
     * acknowledgement that counts. Empty while no version is binding.
     *
     * @return Collection<int, array{person: User, roles: array<int, string>, owed: array<int, string>, acknowledgement: ?RuleAcknowledgement}>
     */
    public static function standings(): Collection
    {
        $binding = self::binding();

        if (! $binding) {
            return collect();
        }

        $people = User::whereHas('roles', fn ($q) => $q->whereIn('role', self::ROLES)->where('is_approved', true))
            ->with(['roles' => fn ($q) => $q->where('is_approved', true)])
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'access_token']);

        $acknowledgements = RuleAcknowledgement::whereIn('user_id', $people->modelKeys())
            ->whereHas('version', fn ($q) => $q->where('number', '>=', $binding->number))
            ->with('version:id,number')
            ->latest('acknowledged_at')
            ->get()
            ->groupBy('user_id');

        return $people->map(function (User $person) use ($acknowledgements) {
            $roles = array_values(array_intersect(self::ROLES, $person->roles->pluck('role')->all()));
            $given = $acknowledgements->get($person->id, collect());
            $covered = $given->flatMap(fn (RuleAcknowledgement $acknowledgement) => $acknowledgement->roles)->unique()->all();

            return [
                'person' => $person,
                'roles' => $roles,
                'owed' => array_values(array_diff($roles, $covered)),
                'acknowledgement' => $given->first(),
            ];
        });
    }

    /**
     * Whether the person owes their word on the rules for this role: a
     * version asks for it and none they gave since covers the role.
     */
    public static function owes(User $user, string $role): bool
    {
        $binding = self::binding();

        if (! $binding) {
            return false;
        }

        return ! RuleAcknowledgement::where('user_id', $user->id)
            ->whereHas('version', fn ($q) => $q->where('number', '>=', $binding->number))
            ->get(['roles'])
            ->contains(fn (RuleAcknowledgement $acknowledgement) => in_array($role, $acknowledgement->roles, true));
    }

    /**
     * Record the person's word on a version, for every role they hold. Given
     * again on the same version — after gaining a role — it covers the new
     * role too, over the text they read this time.
     */
    public static function acknowledge(User $user, RuleVersion $version, ?string $ip, ?string $userAgent, string $channel = 'account'): RuleAcknowledgement
    {
        $roles = self::rolesOf($user);

        return RuleAcknowledgement::updateOrCreate(
            ['user_id' => $user->id, 'rule_version_id' => $version->id],
            [
                'roles' => $roles,
                'channel' => $channel,
                'content_hash' => self::hashOf(self::textFor($version, $roles)),
                'ip' => $ip,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
                'acknowledged_at' => now(),
            ],
        );
    }

    /**
     * The manager's draft as a version would hold it, empty clauses left out.
     *
     * @return list<array{title: string, clauses: list<array{audience: string, body: string}>}>
     */
    public static function draft(): array
    {
        return RuleSection::with('clauses')->orderBy('position')->orderBy('id')->get()
            ->map(fn (RuleSection $section) => [
                'title' => trim($section->title),
                'clauses' => $section->clauses
                    ->filter(fn ($clause) => trim($clause->body) !== '')
                    ->map(fn ($clause) => ['audience' => $clause->audience, 'body' => trim($clause->body)])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $section) => $section['clauses'] !== [])
            ->values()
            ->all();
    }

    /**
     * Freeze the draft into the next version. The first version always asks
     * for everyone's word; a later one asks only when told to, and then lets
     * every teacher and supervisor know.
     */
    public static function publish(?User $publisher, bool $requiresAcknowledgement, ?string $notes): RuleVersion
    {
        $version = DB::transaction(function () use ($publisher, $requiresAcknowledgement, $notes) {
            $last = RuleVersion::lockForUpdate()->max('number');

            return RuleVersion::create([
                'number' => ($last ?? 0) + 1,
                'content' => self::draft(),
                'requires_acknowledgement' => $last === null || $requiresAcknowledgement,
                'notes' => filled($notes) ? trim($notes) : null,
                'published_by' => $publisher?->id,
                'published_at' => now(),
            ]);
        });

        if ($version->requires_acknowledgement) {
            self::notifyEveryone($version);
        }

        return $version;
    }

    /**
     * Tell every approved teacher and supervisor that a version waits for
     * their word, on each role's own bell.
     */
    private static function notifyEveryone(RuleVersion $version): void
    {
        foreach (self::ROLES as $role) {
            $ids = User::whereHas('roles', fn ($q) => $q->where('role', $role)->where('is_approved', true))->pluck('id');

            foreach ($ids as $id) {
                NotificationService::notify(
                    $role,
                    $id,
                    'rules_published',
                    'أنظمة الحلقات',
                    'صدر الإصدار '.$version->number.' من أنظمة الحلقات، اطّلع عليه وأقرّ به.',
                    route("{$role}.rules"),
                );
            }
        }
    }
}
