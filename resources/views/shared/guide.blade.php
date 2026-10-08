@php
    $activeGuard = collect(['manager', 'supervisor', 'teacher', 'student', 'guardian'])
        ->first(fn ($guard) => auth()->guard($guard)->check());
@endphp

<x-layouts.role-shell>
    <x-slot:title>دليل الاستخدام</x-slot:title>

    <x-slot:sidebar>
        @if($activeGuard)
            @include("{$activeGuard}.sidebar-nav")
        @endif
    </x-slot:sidebar>

    <livewire:shared.user-guide />
</x-layouts.role-shell>
