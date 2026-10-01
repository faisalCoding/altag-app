<?php

use App\Models\UserRole;
use App\Services\MessagingService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Locked;

new class extends Component
{
    public string $sourceGuard;

    #[Locked]
    public int $sourceId;

    public string $sourceName;

    /**
     * Only the four "directory" roles are offered here — matching the tabs
     * on the unified users page this component is embedded in.
     */
    protected const ADDABLE_GUARDS = ['student', 'teacher', 'supervisor', 'guardian'];

    public function mount(string $sourceGuard, int $sourceId, string $sourceName): void
    {
        $this->sourceGuard = $sourceGuard;
        $this->sourceId = $sourceId;
        $this->sourceName = $sourceName;
    }

    /**
     * Grants the same person (same `users` row) an additional role. Unlike
     * the old cross-account linking, this doesn't create a new account —
     * it's the same login, just with another `user_roles` row attached.
     */
    public function grant(string $guard): void
    {
        if (! in_array($guard, self::ADDABLE_GUARDS, true) || $guard === $this->sourceGuard) {
            return;
        }

        UserRole::firstOrCreate(
            ['user_id' => $this->sourceId, 'role' => $guard],
            ['is_approved' => true, 'approved_by' => Auth::guard('manager')->id()]
        );

        $roleLabel = MessagingService::ROLE_LABELS[$guard];

        Flux::toast(
            __('تم إضافة دور :role لـ :name بنجاح', ['role' => $roleLabel, 'name' => $this->sourceName]),
            variant: 'success'
        );
    }

    public function revoke(string $guard): void
    {
        if ($guard === $this->sourceGuard) {
            return;
        }

        UserRole::where('user_id', $this->sourceId)->where('role', $guard)->delete();

        Flux::toast(__('تم سحب الدور بنجاح'), variant: 'success');
    }

    public function with(): array
    {
        $roleLabels = MessagingService::ROLE_LABELS;

        $otherRoles = UserRole::where('user_id', $this->sourceId)
            ->where('role', '!=', $this->sourceGuard)
            ->get();

        $heldGuards = $otherRoles->pluck('role')->all();

        $availableGuards = collect(self::ADDABLE_GUARDS)
            ->reject(fn ($guard) => $guard === $this->sourceGuard)
            ->reject(fn ($guard) => in_array($guard, $heldGuards, true))
            ->values();

        return compact('roleLabels', 'otherRoles', 'availableGuards');
    }
};
?>

<div class="border-t border-zinc-100 dark:border-zinc-800 pt-4 mt-2 space-y-3">
    <flux:heading size="sm">{{ __('الأدوار الأخرى لهذا الشخص') }}</flux:heading>

    @if($otherRoles->isNotEmpty())
        <div class="flex flex-wrap gap-1.5">
            @foreach($otherRoles as $role)
                <flux:badge size="sm" variant="neutral">
                    {{ $roleLabels[$role->role] ?? $role->role }}
                    <button type="button" wire:click="revoke('{{ $role->role }}')" wire:confirm="{{ __('متأكد إنك عايز تسحب دور :role من الشخص ده؟', ['role' => $roleLabels[$role->role] ?? $role->role]) }}" class="ms-1 opacity-60 hover:opacity-100">×</button>
                </flux:badge>
            @endforeach
        </div>
    @endif

    @if($availableGuards->isNotEmpty())
        <div class="flex flex-wrap gap-1.5">
            @foreach($availableGuards as $guard)
                <flux:button size="sm" variant="ghost" icon="plus" wire:click="grant('{{ $guard }}')" wire:confirm="{{ __('هيتضاف دور :role لنفس الحساب، ويقدر يتنقل بينهم من قايمة المستخدم. متابعة؟', ['role' => $roleLabels[$guard] ?? $guard]) }}">
                    {{ __(':role', ['role' => $roleLabels[$guard] ?? $guard]) }}
                </flux:button>
            @endforeach
        </div>
    @endif
</div>
