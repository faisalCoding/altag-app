<x-layouts.role-shell :title="__('سجل انضباط الابن')">
    <x-slot:sidebar>
        @include('guardian.sidebar-nav')
    </x-slot:sidebar>

    @php
        $student = auth()->guard('guardian')->user()->students()->findOrFail($studentId);
    @endphp

    <div class="flex h-full w-full flex-1 flex-col gap-4">
        <div class="flex items-center gap-3">
            <a href="{{ route('guardian.student', $student->id) }}" wire:navigate aria-label="رجوع"
                class="p-2 rounded-lg text-zinc-500 hover:text-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                <flux:icon icon="arrow-right" class="size-5" />
            </a>
            <p class="text-sm font-bold text-zinc-600 dark:text-zinc-300">{{ $student->name }}</p>
        </div>

        {{-- The same record the student reads of themself. --}}
        <livewire:shared.discipline-record role="guardian" :student-id="$student->id" />
    </div>
</x-layouts.role-shell>
