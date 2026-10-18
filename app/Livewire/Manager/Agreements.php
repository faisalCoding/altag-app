<?php

namespace App\Livewire\Manager;

use App\Livewire\Supervisor\Agreements as SupervisorAgreements;
use App\Models\Agreement;
use App\Models\Stage;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The manager's agreements: the supervisor's page over every stage, where an
 * agreement may also be written for all of them — a general one imposed on
 * every circle, or a template every teacher may send.
 */
class Agreements extends SupervisorAgreements
{
    protected const ROLE = 'manager';

    /** @return Collection<int, Stage> */
    #[Computed]
    public function stages(): Collection
    {
        return Stage::orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, Agreement> */
    #[Computed]
    public function agreements(): Collection
    {
        return Agreement::with(['stages:id,name', 'currentVersion'])
            ->orderByRaw("case status when 'published' then 0 when 'draft' then 1 else 2 end")
            ->latest('id')
            ->get();
    }
}
