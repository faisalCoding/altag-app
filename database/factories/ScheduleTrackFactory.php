<?php

namespace Database\Factories;

use App\Models\ScheduleTrack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleTrack>
 */
class ScheduleTrackFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'المرحلة '.fake()->word(),
            'columns' => 5,
            'sort_order' => 0,
        ];
    }
}
