<?php

namespace App\Ai\Tools;

use App\Models\Supervisor;
use App\Models\Teacher;
use App\Support\ArabicSearch;
use App\Support\CircleRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Who has given their word on the circles' rules (أنظمة الحلقات) and who
 * still owes it, read from the same standings the manager's follow-up page
 * shows, with each person's stages so the question can be asked of one.
 */
class getRulesAcknowledgements implements Tool
{
    private const ROLES = ['teacher' => 'معلم', 'supervisor' => 'مشرف'];

    private const CHANNELS = ['account' => 'من حسابه', 'public_link' => 'من الرابط العام'];

    public function description(): Stringable|string
    {
        return 'Get the acknowledgement of the circles\' rules (الإقرار بأنظمة الحلقات): the version in force, how many teachers and '
            .'supervisors have acknowledged it and who still owes it, each with their stages and, for those who did, when and how. '
            .'Narrow with "stage" or "role" (teacher or supervisor).';
    }

    public function handle(Request $request): Stringable|string
    {
        $binding = CircleRules::binding();

        if (! $binding) {
            return json_encode(['binding_version' => null, 'note' => 'No published version of the rules asks for acknowledgement yet.'], JSON_UNESCAPED_UNICODE);
        }

        $standings = CircleRules::standings();
        $ids = $standings->map(fn (array $standing) => $standing['person']->id)->all();
        $teacherStages = Teacher::with('circles.stage:id,name')->whereKey($ids)->get()
            ->mapWithKeys(fn (Teacher $teacher) => [$teacher->id => $teacher->circles->pluck('stage.name')->filter()->all()]);
        $supervisorStages = Supervisor::with('stages:id,name')->whereKey($ids)->get()
            ->mapWithKeys(fn (Supervisor $supervisor) => [$supervisor->id => $supervisor->stages->pluck('name')->all()]);

        $rows = $standings->map(function (array $standing) use ($teacherStages, $supervisorStages) {
            $id = $standing['person']->id;

            return [
                'name' => trim((string) $standing['person']->name),
                'roles' => array_map(fn ($role) => self::ROLES[$role] ?? $role, $standing['roles']),
                'role_keys' => $standing['roles'],
                'stages' => array_values(array_unique([...($teacherStages[$id] ?? []), ...($supervisorStages[$id] ?? [])])),
                'owes' => array_map(fn ($role) => self::ROLES[$role] ?? $role, $standing['owed']),
                'acknowledged_at' => $standing['acknowledgement']?->acknowledged_at?->format('Y-m-d H:i'),
                'channel' => self::CHANNELS[$standing['acknowledgement']?->channel] ?? $standing['acknowledgement']?->channel,
            ];
        });

        if ($role = ($request['role'] ?? null)) {
            $rows = $rows->filter(fn (array $row) => in_array($role, $row['role_keys'], true));
        }

        if ($stage = ($request['stage'] ?? null)) {
            $wanted = ArabicSearch::fold($stage);
            $rows = $rows->filter(fn (array $row) => collect($row['stages'])->contains(fn ($name) => str_contains(ArabicSearch::fold((string) $name), $wanted)));
        }

        $rows = $rows->map(fn (array $row) => array_diff_key($row, ['role_keys' => true]))->values();
        $owing = $rows->filter(fn (array $row) => $row['owes'] !== []);

        return json_encode([
            'binding_version' => [
                'number' => $binding->number,
                'published_at' => $binding->published_at?->format('Y-m-d'),
            ],
            'people' => $rows->count(),
            'acknowledged' => $rows->count() - $owing->count(),
            'owing' => $owing->values()->all(),
            'acknowledged_people' => $rows->reject(fn (array $row) => $row['owes'] !== [])->values()->all(),
        ], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'stage' => $schema->string()->description('Stage name (مرحلة), or part of it.'),
            'role' => $schema->string()->enum(['teacher', 'supervisor'])->description('Only teachers, or only supervisors.'),
        ];
    }
}
