<?php

namespace App\Livewire\Manager;

use App\Livewire\Supervisor\AgreementFollowUp as SupervisorAgreementFollowUp;
use App\Models\Agreement;

/**
 * The manager's follow-up of an agreement: the supervisor's page over every
 * stage the agreement reaches.
 */
class AgreementFollowUp extends SupervisorAgreementFollowUp
{
    protected const ROLE = 'manager';

    protected function stageIds(): array
    {
        return Agreement::findOrFail($this->agreementId)->stageIds();
    }
}
