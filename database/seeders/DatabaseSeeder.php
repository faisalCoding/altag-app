<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Manager User',
            'email' => 'manager@test.com',
            'role' => User::ROLE_MANAGER,
        ]);

        User::factory()->create([
            'name' => 'Supervisor User',
            'email' => 'supervisor@test.com',
            'role' => User::ROLE_SUPERVISOR,
        ]);

        User::factory()->create([
            'name' => 'Teacher User',
            'email' => 'teacher@test.com',
            'role' => User::ROLE_TEACHER,
        ]);

        User::factory()->create([
            'name' => 'Student User',
            'email' => 'student@test.com',
            'role' => User::ROLE_STUDENT,
        ]);

        User::factory()->create([
            'name' => 'Parent User',
            'email' => 'parent@test.com',
            'role' => User::ROLE_PARENT,
        ]);

        User::factory(10)->create(['role' => User::ROLE_STUDENT]);
    }
}
