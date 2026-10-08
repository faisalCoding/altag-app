@php
    $activeGuard = collect(['manager', 'supervisor', 'teacher', 'student', 'guardian'])
        ->first(fn ($guard) => auth()->guard($guard)->check());
@endphp

<x-layouts.role-shell>
    <x-slot:sidebar>
        @if($activeGuard)
            @include("{$activeGuard}.sidebar-nav")
        @endif
    </x-slot:sidebar>

    {{ $slot }}
</x-layouts.role-shell>
