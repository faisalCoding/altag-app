<?php

namespace App\Console\Commands;

use App\Services\ExamReadiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('exams:remind-readiness')]
#[Description('Remind teachers, once, to confirm students whose expected exam readiness day has come.')]
class RemindExamReadiness extends Command
{
    public function handle(): int
    {
        $this->info('Reminders sent: '.ExamReadiness::remindDue());

        return self::SUCCESS;
    }
}
