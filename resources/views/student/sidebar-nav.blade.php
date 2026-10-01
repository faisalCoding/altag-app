@php
    $studentUnreadMessages = \App\Services\MessagingService::unreadCountFor('student', auth('student')->id());
@endphp

<flux:sidebar.group class="grid">
    <flux:sidebar.item class="[&_svg]:bg-[#3b82f6] hover:[&_svg]:bg-[#2563eb]" icon="home" :href="route('student.dashboard')" :current="request()->routeIs('student.dashboard')" wire:navigate>
        {{ __('الرئيسية') }}
    </flux:sidebar.item>
    @if(\App\Support\RolePages::isEnabled('student', 'student.plan'))
        <flux:sidebar.item class="[&_svg]:bg-[#0ea5e9] hover:[&_svg]:bg-[#0284c7]" icon="book-open" :href="route('student.plan')" :current="request()->routeIs('student.plan')" wire:navigate>
            {{ __('خططي القرآنية') }}
        </flux:sidebar.item>
    @endif
    @if(\App\Support\RolePages::isEnabled('student', 'student.hifz'))
        <flux:sidebar.item class="[&_svg]:bg-[#6366f1] hover:[&_svg]:bg-[#4f46e5]" icon="bookmark" :href="route('student.hifz')" :current="request()->routeIs('student.hifz')" wire:navigate>
            {{ __('الحفظ') }}
        </flux:sidebar.item>
    @endif
    @if(\App\Support\RolePages::isEnabled('student', 'student.review'))
        <flux:sidebar.item class="[&_svg]:bg-[#10b981] hover:[&_svg]:bg-[#059669]" icon="arrow-path" :href="route('student.review')" :current="request()->routeIs('student.review')" wire:navigate>
            {{ __('المراجعة') }}
        </flux:sidebar.item>
    @endif
    @if(\App\Support\RolePages::isEnabled('student', 'student.exams'))
        <flux:sidebar.item class="[&_svg]:bg-[#0ea5e9] hover:[&_svg]:bg-[#0284c7]" icon="academic-cap" :href="route('student.exams')" :current="request()->routeIs('student.exams')" wire:navigate>
            {{ __('الاختبارات') }}
        </flux:sidebar.item>
    @endif
    @if(\App\Support\RolePages::isEnabled('student', 'student.calendar'))
        <flux:sidebar.item class="[&_svg]:bg-[#f59e0b] hover:[&_svg]:bg-[#d97706]" icon="calendar" :href="route('student.calendar')" :current="request()->routeIs('student.calendar')" wire:navigate>
            {{ __('التقويم') }}
        </flux:sidebar.item>
    @endif
    @if(\App\Support\RolePages::isEnabled('student', 'student.schedule'))
        <flux:sidebar.item class="[&_svg]:bg-[#16a6bd] hover:[&_svg]:bg-[#0e8a9e]" icon="calendar-days" :href="route('student.schedule')" :current="request()->routeIs('student.schedule*')" wire:navigate>
            {{ __('جدول البرنامج') }}
        </flux:sidebar.item>
    @endif
    @if(\App\Support\RolePages::isEnabled('student', 'student.attendance'))
        <flux:sidebar.item class="[&_svg]:bg-[#10b981] hover:[&_svg]:bg-[#059669]" icon="clipboard-document-check" :href="route('student.attendance')" :current="request()->routeIs('student.attendance')" wire:navigate>
            {{ __('سجل الانضباط') }}
        </flux:sidebar.item>
    @endif
    @if(\App\Support\RolePages::isEnabled('student', 'student.reports'))
        <flux:sidebar.item class="[&_svg]:bg-[#14b8a6] hover:[&_svg]:bg-[#0d9488]" icon="chart-bar-square" :href="route('student.reports')" :current="request()->routeIs('student.reports')" wire:navigate>
            {{ __('التقارير') }}
        </flux:sidebar.item>
    @endif
    @if(\App\Support\RolePages::isEnabled('student', 'student.messages'))
        <flux:sidebar.item class="[&_svg]:bg-[#e11d48] hover:[&_svg]:bg-[#be123c]" icon="envelope" :href="route('student.messages')" :current="request()->routeIs('student.messages')"
            :badge="$studentUnreadMessages > 0 ? $studentUnreadMessages : null" badge-color="rose" wire:navigate>
            {{ __('الرسائل') }}
        </flux:sidebar.item>
    @endif
    <flux:sidebar.item class="[&_svg]:bg-[#3b82f6] hover:[&_svg]:bg-[#2563eb]" icon="trophy" :href="route('student.dashboard').'#achievements'" wire:navigate>
        {{ __('الإنجازات') }}
    </flux:sidebar.item>
    <flux:sidebar.item class="[&_svg]:bg-[#3b82f6] hover:[&_svg]:bg-[#2563eb]" icon="star" :href="route('student.dashboard').'#leaderboard-standings'" wire:navigate>
        {{ __('المتصدرون') }}
    </flux:sidebar.item>
</flux:sidebar.group>
