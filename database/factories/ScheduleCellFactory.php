<?php

namespace Database\Factories;

use App\Models\ScheduleActivity;
use App\Models\ScheduleCell;
use App\Models\ScheduleWeek;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleCell>
 */
class ScheduleCellFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'schedule_week_id' => ScheduleWeek::factory(),
            'weekday' => 0,
            'position' => 0,
            'span' => 1,
            'schedule_activity_id' => ScheduleActivity::factory(),
        ];
    }
}
