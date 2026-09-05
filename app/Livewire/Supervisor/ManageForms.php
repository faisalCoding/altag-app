<?php

namespace App\Livewire\Supervisor;

use App\Models\Form;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * The forms a person may see and manage.
 *
 * Forms began as a supervisor's alone and are now written by managers and
 * teachers too, so ownership is read from the created_by pair rather than from
 * one guard — with supervisor_id still honoured for everything made before the
 * morph existed.
 */
class ManageForms extends Component
{
    /**
     * The signed-in author and the role they are acting in.
     *
     * @return array{0: User, 1: string}
     */
    private function author(): array
    {
        foreach (['manager', 'supervisor', 'teacher'] as $guard) {
            if ($user = auth()->guard($guard)->user()) {
                return [$user, $guard];
            }
        }

        abort(403);
    }

    /**
     * What this author is allowed to open: their own forms, plus — for
     * supervisors — the ones shared across supervisors, and everything for a
     * manager, who answers for the academy.
     *
     * @return Builder<Form>
     */
    private function visibleForms()
    {
        [$author, $role] = $this->author();

        $query = Form::query();

        if ($role === 'manager') {
            return $query;
        }

        return $query->where(function ($q) use ($author, $role) {
            $q->where(fn ($own) => $own->where('created_by_id', $author->id)->where('created_by_type', $role));

            if ($role === 'supervisor') {
                // Legacy ownership, from before forms could belong to anyone else.
                $q->orWhere('supervisor_id', $author->id)
                    ->orWhere('is_supervisor_shared', true);
            }
        });
    }

    public function delete($id): void
    {
        // Deleting is narrower than viewing: a shared form is not yours to remove.
        [$author, $role] = $this->author();

        $form = $this->visibleForms()->findOrFail($id);

        $isOwner = ($form->created_by_id === $author->id && $form->created_by_type === $role)
            || ($role === 'supervisor' && $form->supervisor_id === $author->id)
            || $role === 'manager';

        abort_unless($isOwner, 403);

        $form->delete();

        Flux::toast('تم حذف النموذج بنجاح', variant: 'success');
    }

    public function render()
    {
        [$author, $role] = $this->author();

        $forms = $this->visibleForms()
            ->withCount(['responses', 'assignments'])
            ->with('supervisor:id,name')
            ->latest()
            ->get();

        return view('livewire.supervisor.manage-forms', [
            'forms' => $forms,
            'currentSupervisorId' => $author->id,
            'currentRole' => $role,
        ]);
    }
}
