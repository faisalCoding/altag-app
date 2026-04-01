<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;
use App\Models\Stage;
use App\Models\Circle;
use App\Models\Teacher;
use App\Models\Student;
use App\Models\Guardian;
use App\Models\Manager;
use Illuminate\Support\Facades\DB;

class ImportDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-data {path=data.json}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import Data from a JSON file to the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path = $this->argument('path');

        if (!File::exists($path)) {
            $this->error("File {$path} not found.");
            return;
        }

        $jsonData = json_decode(File::get($path), true);

        if (!$jsonData) {
            $this->error("Invalid JSON data.");
            return;
        }

        $this->info("Importing data...");

        DB::beginTransaction();
        try {

            // Parsing Data Arrays
            $configDetails = null;
            $stages = [];
            $circles = [];
            $teachers = [];
            $students = [];
            $guardians = [];
            
            // Loop through JSON to categorize
            foreach ($jsonData as $item) {
                if (isset($item['all_password'])) {
                    $configDetails = $item;
                } elseif (isset($item['المرحلة']) && !isset($item['الحلقة']) && !isset($item['المعلم'])) {
                    $stages[] = $item;
                } elseif (isset($item['الحلقة']) && isset($item['المرحلة']) && !isset($item['المعلم'])) {
                    $circles[] = $item;
                } elseif (isset($item['المعلم'])) {
                    $teachers[] = $item;
                } elseif (isset($item['االطالب'])) {
                    $students[] = $item;
                } elseif (isset($item['ولي الامر'])) {
                    $guardians[] = $item;
                }
            }

            // 1. Config Details (Manager)
            if ($configDetails) {
                Manager::updateOrCreate(
                    ['email' => $configDetails['manager_emails']],
                    [
                        'name' => $configDetails['manager_name'],
                        'password' => Hash::make($configDetails['manager_password']),
                        'is_approved' => true,
                    ]
                );
                $this->info("Manager imported.");
                $defaultPassword = Hash::make($configDetails['all_password']);
            } else {
                $defaultPassword = Hash::make('password');
            }

            // 2. Stages
            foreach ($stages as $stageData) {
                Stage::updateOrCreate(
                    ['id' => $stageData['id']],
                    ['name' => $stageData['المرحلة']]
                );
            }
            $this->info("Stages imported.");

            // 3. Circles
            foreach ($circles as $circleData) {
                Circle::updateOrCreate(
                    ['id' => $circleData['id']],
                    [
                        'name' => $circleData['الحلقة'], 
                        'stage_id' => $circleData['المرحلة']
                    ]
                );
            }
            $this->info("Circles imported.");

            // 4. Teachers
            foreach ($teachers as $teacherData) {
                // Email wasn't provided, generate one
                $email = "teacher" . $teacherData['id'] . "@example.com";
                $teacher = Teacher::updateOrCreate(
                    ['id' => $teacherData['id']],
                    [
                        'name' => $teacherData['المعلم'],
                        'email' => $email,
                        'password' => $defaultPassword,
                        'is_approved' => true,
                    ]
                );
                
                // Attach to circle
                if (isset($teacherData['الحلقة'])) {
                    $teacher->circles()->syncWithoutDetaching([$teacherData['الحلقة']]);
                }
            }
            $this->info("Teachers imported.");

            // 5. Students
            foreach ($students as $studentData) {
                $email = "student" . $studentData['id'] . "@example.com";
                Student::updateOrCreate(
                    ['id' => $studentData['id']],
                    [
                        'name' => $studentData['االطالب'],
                        'email' => $email,
                        'circle_id' => $studentData['الحلقة'],
                        'password' => $defaultPassword,
                        'is_approved' => true,
                    ]
                );
            }
            $this->info("Students imported.");

            // 6. Guardians
            foreach ($guardians as $guardIdx => $guardianData) {
                $email = "guardian" . ($guardIdx + 1) . "@example.com";
                
                // Try format phone
                $phone = $guardianData['رقم الجوال'];
                // Some logic allows standardizing phone if needed, we insert as is
                $guardian = Guardian::updateOrCreate(
                    ['phone' => $phone],
                    [
                        'name' => $guardianData['ولي الامر'],
                        'email' => $email,
                        'password' => $defaultPassword,
                        'is_approved' => true,
                    ]
                );

                // Map to students
                if (isset($guardianData['الطلاب']) && is_array($guardianData['الطلاب'])) {
                    foreach ($guardianData['الطلاب'] as $stuNode) {
                        if (isset($stuNode['id'])) {
                            Student::where('id', $stuNode['id'])->update(['guardian_id' => $guardian->id]);
                        }
                    }
                }
            }
            $this->info("Guardians imported.");

            DB::commit();
            $this->info("Data imported successfully!");

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Failed to import data: " . $e->getMessage());
        }
    }
}
