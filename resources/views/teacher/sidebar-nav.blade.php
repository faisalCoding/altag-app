<flux:sidebar.group heading="التعليم" class="grid">
    <flux:sidebar.item class="[&_svg]:bg-[#3b82f6] hover:[&_svg]:bg-[#2563eb]" icon="home" wire:navigate :current="request()->routeIs('teacher.dashboard')"
        href="{{ route('teacher.dashboard') }}">
        {{ __('الرئيسية') }}
    </flux:sidebar.item>
    @php
        $teacherUnreadMessages = \App\Services\MessagingService::unreadCountFor('teacher', auth('teacher')->id());
    @endphp
    @if(\App\Support\RolePages::isEnabled('teacher', 'teacher.messages'))
        <flux:sidebar.item class="[&_svg]:bg-[#e11d48] hover:[&_svg]:bg-[#be123c]" icon="envelope" wire:navigate :current="request()->routeIs('teacher.messages')"
            :badge="$teacherUnreadMessages > 0 ? $teacherUnreadMessages : null" badge-color="rose"
            href="{{ route('teacher.messages') }}">
            {{ __('الرسائل') }}
        </flux:sidebar.item>
    @endif
    <flux:sidebar.group heading="{{ __('الخطط القرآنية') }}" class="mt-4">
        @if(\App\Support\RolePages::isEnabled('teacher', 'teacher.students'))
            <flux:sidebar.item class="[&_svg]:bg-[#a855f7] hover:[&_svg]:bg-[#9333ea]" icon="users"
                x-on:click.prevent="if(document.getElementById('teacher-app-shell')) { $dispatch('switch-tab', { tab: 'students', url: '{{ route('teacher.students') }}' }); } else { Livewire.navigate('{{ route('teacher.students') }}'); }"
                x-bind:data-current="'{{ $initialTab ?? '' }}' === 'students' ? 'true' : null"
                x-on:switch-tab.window="if($event.detail.tab === 'students') $el.setAttribute('data-current', 'true'); else $el.removeAttribute('data-current');"
                href="{{ route('teacher.students') }}">
                {{ __('إدارة الطلاب') }}
            </flux:sidebar.item>
        @endif
        @if(\App\Support\RolePages::isEnabled('teacher', 'teacher.plan-creator'))
            <flux:sidebar.item class="[&_svg]:bg-[#0ea5e9] hover:[&_svg]:bg-[#0284c7]" icon="pencil-square"
                x-on:click.prevent="if(document.getElementById('teacher-app-shell')) { $dispatch('switch-tab', { tab: 'plan-creator', url: '{{ route('teacher.plan-creator') }}' }); } else { Livewire.navigate('{{ route('teacher.plan-creator') }}'); }"
                x-bind:data-current="'{{ $initialTab ?? '' }}' === 'plan-creator' ? 'true' : null"
                x-on:switch-tab.window="if($event.detail.tab === 'plan-creator') $el.setAttribute('data-current', 'true'); else $el.removeAttribute('data-current');"
                href="{{ route('teacher.plan-creator') }}">
                {{ __('إنشاء خطة طالب') }}
            </flux:sidebar.item>
        @endif
        @if(\App\Support\RolePages::isEnabled('teacher', 'teacher.student-plans'))
            <flux:sidebar.item class="[&_svg]:bg-[#0ea5e9] hover:[&_svg]:bg-[#0284c7]" icon="clipboard-document-list" wire:navigate
                :current="request()->routeIs('teacher.student-plans')" href="{{ route('teacher.student-plans') }}">
                {{ __('عرض الخطط المنشأة') }}
            </flux:sidebar.item>
        @endif
        @if(\App\Support\RolePages::isEnabled('teacher', 'teacher.tasmeeh'))
            <flux:sidebar.item class="[&_svg]:bg-[#a855f7] hover:[&_svg]:bg-[#9333ea]" icon="book-open"
                x-on:click.prevent="if(document.getElementById('teacher-app-shell')) { $dispatch('switch-tab', { tab: 'tasmeeh', url: '{{ route('teacher.tasmeeh') }}' }); } else { Livewire.navigate('{{ route('teacher.tasmeeh') }}'); }"
                x-bind:data-current="'{{ $initialTab ?? '' }}' === 'tasmeeh' ? 'true' : null"
                x-on:switch-tab.window="if($event.detail.tab === 'tasmeeh') $el.setAttribute('data-current', 'true'); else $el.removeAttribute('data-current');"
                href="{{ route('teacher.tasmeeh') }}">
                {{ __('التسميع والمتابعة') }}
            </flux:sidebar.item>
        @endif
        @if(\App\Support\RolePages::isEnabled('teacher', 'teacher.pairs'))
            <flux:sidebar.item class="[&_svg]:bg-[#ec4899] hover:[&_svg]:bg-[#db2777]" icon="users" wire:navigate :current="request()->routeIs('teacher.pairs')"
                href="{{ route('teacher.pairs') }}">
                {{ __('التسميع المتبادل') }}
            </flux:sidebar.item>
        @endif
    </flux:sidebar.group>

    @if(\App\Support\RolePages::isEnabled('teacher', 'teacher.ode-plans'))
        <flux:sidebar.group heading="{{ __('خطط المنظومات') }}" class="mt-4">
            <flux:sidebar.item class="[&_svg]:bg-[#f97316] hover:[&_svg]:bg-[#ea580c]" icon="clipboard-document-list" wire:navigate
                :current="request()->routeIs('teacher.ode-plans')" href="{{ route('teacher.ode-plans') }}">
                {{ __('عرض الخطط المنشأة') }}
            </flux:sidebar.item>
        </flux:sidebar.group>
    @endif

    @if(\App\Support\RolePages::isEnabled('teacher', 'teacher.leaderboards'))
        <flux:sidebar.group heading="{{ __('التحفيز والمنافسة') }}" class="mt-4">
            <flux:sidebar.item class="[&_svg]:bg-[#eab308] hover:[&_svg]:bg-[#ca8a04]" icon="trophy"
                x-on:click.prevent="if(document.getElementById('teacher-app-shell')) { $dispatch('switch-tab', { tab: 'leaderboards', url: '{{ route('teacher.leaderboards') }}' }); } else { Livewire.navigate('{{ route('teacher.leaderboards') }}'); }"
                x-bind:data-current="'{{ $initialTab ?? '' }}' === 'leaderboards' ? 'true' : null"
                x-on:switch-tab.window="if($event.detail.tab === 'leaderboards') $el.setAttribute('data-current', 'true'); else $el.removeAttribute('data-current');"
                href="{{ route('teacher.leaderboards') }}">
                {{ __('مسابقات الحلقة') }}
            </flux:sidebar.item>
        </flux:sidebar.group>
    @endif

    @if(\App\Support\RolePages::isEnabled('teacher', 'teacher.student-exams'))
        <flux:sidebar.group heading="{{ __('الاختبارات') }}" class="mt-4">
            <flux:sidebar.item class="[&_svg]:bg-[#0ea5e9] hover:[&_svg]:bg-[#0284c7]" icon="academic-cap" wire:navigate :current="request()->routeIs('teacher.student-exams*')"
                href="{{ route('teacher.student-exams') }}">
                {{ __('اختبارات الطلاب') }}
            </flux:sidebar.item>
        </flux:sidebar.group>
    @endif

    <flux:sidebar.group heading="{{ __('التحضير') }}" class="mt-4">
        @if(\App\Support\RolePages::isEnabled('teacher', 'teacher.attendance'))
            <flux:sidebar.item class="[&_svg]:bg-[#10b981] hover:[&_svg]:bg-[#059669]" icon="calendar"
                x-on:click.prevent="if(document.getElementById('teacher-app-shell')) { $dispatch('switch-tab', { tab: 'attendance', url: '{{ route('teacher.attendance') }}' }); } else { Livewire.navigate('{{ route('teacher.attendance') }}'); }"
                x-bind:data-current="'{{ $initialTab ?? '' }}' === 'attendance' ? 'true' : null"
                x-on:switch-tab.window="if($event.detail.tab === 'attendance') $el.setAttribute('data-current', 'true'); else $el.removeAttribute('data-current');"
                href="{{ route('teacher.attendance') }}">
                سجل الحضور
            </flux:sidebar.item>
        @endif
        @if(\App\Support\RolePages::isEnabled('teacher', 'teacher.discipline'))
            <flux:sidebar.item class="[&_svg]:bg-[#ef4444] hover:[&_svg]:bg-[#dc2626]" icon="chart-bar" wire:navigate :current="request()->routeIs('teacher.discipline')"
                href="{{ route('teacher.discipline') }}">
                الانضباط الحضوري
            </flux:sidebar.item>
        @endif
        @if(\App\Support\RolePages::isEnabled('teacher', 'teacher.quranic-discipline'))
            <flux:sidebar.item class="[&_svg]:bg-[#ef4444] hover:[&_svg]:bg-[#dc2626]" icon="chart-pie" wire:navigate :current="request()->routeIs('teacher.quranic-discipline')"
                href="{{ route('teacher.quranic-discipline') }}">
                الانضباط القرآني
            </flux:sidebar.item>
        @endif
        @if(\App\Support\RolePages::isEnabled('teacher', 'teacher.exceeded-limits'))
            <flux:sidebar.item class="[&_svg]:bg-[#ef4444] hover:[&_svg]:bg-[#dc2626]" icon="exclamation-triangle" wire:navigate
                :current="request()->routeIs('teacher.exceeded-limits')" href="{{ route('teacher.exceeded-limits') }}">
                لائحة التجاوزات
            </flux:sidebar.item>
        @endif
    </flux:sidebar.group>
    @if(\App\Support\RolePages::isEnabled('teacher', 'teacher.forms'))
        <flux:sidebar.item class="[&_svg]:bg-[#14b8a6] hover:[&_svg]:bg-[#0d9488]" icon="document-text" :href="route('teacher.forms')"
            :current="request()->routeIs('teacher.forms*')" wire:navigate>
            الاستبانات والنماذج
        </flux:sidebar.item>
    @endif
</flux:sidebar.group>
