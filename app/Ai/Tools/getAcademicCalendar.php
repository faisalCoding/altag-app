<?php

namespace App\Ai\Tools;

use App\Models\AcademicCalendarEvent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class getAcademicCalendar implements Tool
{
    private const MAX_LIMIT = 200;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Get the academic calendar (التقويم الأكاديمي): the events, terms, holidays and attendance periods with their dates. '
            .'An event flagged as an attendance period (فترة تحضير) is a span where attendance is recorded on the listed weekdays. '
            .'Filter with "from" and "to" (Y-m-d) to limit the range; without them the whole calendar is returned. '
            .'Call getDateAndTime first if you need to reason about "today" or a relative date.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $limit = min(max((int) (($request['limit'] ?? null) ?: 100), 1), self::MAX_LIMIT);

        $query = AcademicCalendarEvent::with('taskCategories:id,name,event_id')->withCount('tasks');

        // Dates are stored with a time: whereDate keeps an event on the bound's own day in.
        if ($from = ($request['from'] ?? null)) {
            $query->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from));
        }

        if ($to = ($request['to'] ?? null)) {
            $query->whereDate('start_date', '<=', $to);
        }

        $events = $query->orderBy('start_date')->limit($limit)->get();

        return json_encode([
            'returned' => $events->count(),
            'events' => $events->map(fn (AcademicCalendarEvent $event) => [
                'name' => $event->event_name,
                'description' => $event->description,
                'start_date' => $event->start_date?->format('Y-m-d'),
                'end_date' => $event->end_date?->format('Y-m-d'),
                'day_count' => $event->day_count,
                'is_attendance_period' => (bool) $event->is_attendance_period,
                'weekdays' => $event->weekdays,
                'stages' => $event->is_attendance_period ? $event->stageNames()->all() : null,
                'extra_days' => $event->extra_dates,
                'excluded_days' => $event->excluded_dates,
                'sessions' => $event->sessions,
                'is_visible' => (bool) $event->is_visible,
                'has_tasks' => (bool) $event->has_tasks,
                'tasks_count' => $event->tasks_count,
                'task_categories' => $event->taskCategories->pluck('name')->all(),
            ])->all(),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Only events ending on or after this date (Y-m-d).'),
            'to' => $schema->string()->description('Only events starting on or before this date (Y-m-d).'),
            'limit' => $schema->integer()->description('Maximum events to return. Defaults to 100, maximum 200.'),
        ];
    }
}
