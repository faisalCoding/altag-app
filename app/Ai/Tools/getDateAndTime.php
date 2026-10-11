<?php

namespace App\Ai\Tools;

use App\Models\AcademicCalendarEvent;
use App\Support\HijriDate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * "Today" as the academy reads it: Riyadh's date and time, the Hijri date the
 * site shows, the week and month that hold it, and the attendance periods
 * running — everything the model needs to turn «هذا الأسبوع» or «الشهر
 * الماضي» into dates for the other tools, without working them out itself.
 */
class getDateAndTime implements Tool
{
    public function description(): Stringable|string
    {
        return 'Get today in Riyadh: the date and time, the weekday, the Hijri date, the bounds of this week (Sunday to Saturday), '
            .'last week, this month and last month as Y-m-d dates, and the attendance periods (فترات الدوام) running today. '
            .'Call it before reasoning about "today", "this week", "last month" or any relative date, and pass its dates to the other tools.';
    }

    public function handle(Request $request): Stringable|string
    {
        $now = now('Asia/Riyadh');
        $today = $now->toDateString();
        $weekStart = $now->copy()->startOfDay()->subDays($now->dayOfWeek);

        $periods = AcademicCalendarEvent::query()
            ->where('is_attendance_period', true)
            ->whereDate('start_date', '<=', $today)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->orderBy('start_date')
            ->get(['event_name', 'start_date', 'end_date']);

        return json_encode([
            'now' => $now->format('Y-m-d H:i'),
            'today' => $today,
            'weekday' => HijriDate::weekday($today),
            'hijri' => HijriDate::withWeekday($today),
            'this_week' => ['from' => $weekStart->toDateString(), 'to' => $weekStart->copy()->addDays(6)->toDateString()],
            'last_week' => ['from' => $weekStart->copy()->subWeek()->toDateString(), 'to' => $weekStart->copy()->subDay()->toDateString()],
            'this_month' => ['from' => $now->copy()->startOfMonth()->toDateString(), 'to' => $now->copy()->endOfMonth()->toDateString()],
            'last_month' => [
                'from' => $now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'to' => $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            'attendance_periods_today' => $periods->map(fn ($period) => [
                'name' => $period->event_name,
                'from' => $period->start_date?->format('Y-m-d'),
                'to' => $period->end_date?->format('Y-m-d'),
            ])->all(),
        ], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
