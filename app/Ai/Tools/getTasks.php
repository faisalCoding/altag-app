<?php

namespace App\Ai\Tools;

use App\Models\Task;
use App\Models\TaskCategory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class getTasks implements Tool
{
    private const MAX_LIMIT = 200;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Get the tasks (المهام) of the academy with their category, due date, status, who created them and who they are assigned to. '
            .'Filter with "status", "category", "assignee" (part of a person name), or the due-date range "due_from"/"due_to". '
            .'Set "overdue" to true to get only tasks past their due date that are not done yet. The task categories are listed alongside the tasks.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $limit = min(max((int) (($request['limit'] ?? null) ?: 100), 1), self::MAX_LIMIT);

        $query = Task::with(['category:id,name', 'createdBy', 'assignedTo', 'events:id,event_name']);

        if ($status = ($request['status'] ?? null)) {
            $query->where('status', $status);
        }

        if ($category = ($request['category'] ?? null)) {
            $query->whereHas('category', fn ($q) => $q->where('name', 'like', '%'.$category.'%'));
        }

        if ($dueFrom = ($request['due_from'] ?? null)) {
            $query->whereDate('due_date', '>=', $dueFrom);
        }

        if ($dueTo = ($request['due_to'] ?? null)) {
            $query->whereDate('due_date', '<=', $dueTo);
        }

        if (($request['overdue'] ?? null)) {
            $query->whereDate('due_date', '<', now('Asia/Riyadh')->toDateString())
                ->where('status', '!=', 'completed');
        }

        $tasks = $query->orderBy('due_date')->limit($limit)->get();

        if ($assignee = ($request['assignee'] ?? null)) {
            $tasks = $tasks->filter(
                fn (Task $task) => $task->assignedTo && str_contains((string) $task->assignedTo->name, (string) $assignee)
            )->values();
        }

        return json_encode([
            'returned' => $tasks->count(),
            'categories' => TaskCategory::orderBy('name')->pluck('name')->all(),
            'tasks' => $tasks->map(fn (Task $task) => [
                'title' => $task->title,
                'description' => $task->description,
                'category' => $task->category?->name,
                'status' => $task->status,
                'due_date' => $task->due_date?->format('Y-m-d'),
                'created_by' => $task->createdBy?->name,
                'assigned_to' => $task->assignedTo?->name,
                'assigned_to_role' => $this->roleLabel($task->assigned_to_type),
                'calendar_events' => $task->events->pluck('event_name')->all(),
            ])->all(),
        ], JSON_UNESCAPED_UNICODE);
    }

    private function roleLabel(?string $type): ?string
    {
        return match (class_basename($type ?? '')) {
            'Manager' => 'مدير',
            'Supervisor' => 'مشرف',
            'Teacher' => 'معلم',
            'Student' => 'طالب',
            'Guardian' => 'ولي أمر',
            default => null,
        };
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Task status, for example pending or completed.'),
            'category' => $schema->string()->description('Task category name, or part of it.'),
            'assignee' => $schema->string()->description('Part of the name of the person the task is assigned to.'),
            'due_from' => $schema->string()->description('Only tasks due on or after this date (Y-m-d).'),
            'due_to' => $schema->string()->description('Only tasks due on or before this date (Y-m-d).'),
            'overdue' => $schema->boolean()->description('Only tasks past their due date that are not completed.'),
            'limit' => $schema->integer()->description('Maximum tasks to return. Defaults to 100, maximum 200.'),
        ];
    }
}
