<?php

namespace Database\Factories;

use App\Models\ScheduleActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleActivity>
 */
class ScheduleActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'icon' => 'book',
            'color' => 'teal',
            'is_routine' => false,
            'sort_order' => 0,
        ];
    }

    public function routine(): static
    {
        return $this->state(fn () => ['is_routine' => true]);
    }
}
