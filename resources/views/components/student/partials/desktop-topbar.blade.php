@php
    $topbarStudent = auth('student')->user();
    $topbarNextExam = \App\Models\StudentExam::where('student_id', $topbarStudent->id)
        ->where('status', 'pending')
        ->where('date_time', '>=', now())
        ->exists();
    $topbarHasPendingMission = \App\Models\StudentPlanDay::whereHas('plan', fn ($q) => $q->where('student_id', $topbarStudent->id)->where('status', 'active')->where('is_approved', 1))
        ->where(fn ($q) => $q->whereNull('hifz_achievement')->orWhereNull('review_achievement'))
        ->exists();
@endphp

<div class="hidden lg:flex items-center gap-4 px-6 py-3 border-b border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900" dir="rtl">
    <div class="flex items-center gap-2.5 shrink-0">
        <img src="{{ App\Support\Branding::logoUrl() }}" alt="{{ App\Support\Branding::siteName() }}" class="h-9 object-contain" />
        <div class="text-end">
            <div class="font-extrabold text-maroon dark:text-red-secondary text-sm leading-tight">{{ App\Support\Branding::siteName() }}</div>
            <div class="text-[10px] text-zinc-400">{{ __('منصة رقمية متكاملة لحفظ ومراجعة القرآن الكريم') }}</div>
        </div>
    </div>

    <div class="flex-1 max-w-xl mx-auto">
        <livewire:student.header-search />
    </div>

    <div class="flex items-center gap-3 shrink-0">
        <flux:dropdown position="bottom" align="end">
            <button class="relative p-2 rounded-full hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors">
                <flux:icon icon="bell" class="size-5 text-zinc-500 dark:text-zinc-400" />
                @if($topbarNextExam || $topbarHasPendingMission)
                    <span class="absolute top-1.5 right-1.5 size-2 rounded-full bg-rose-500"></span>
                @endif
            </button>

            <flux:menu>
                <div class="px-3 py-2 text-xs font-bold text-zinc-400">{{ __('الإشعارات') }}</div>
                @if($topbarNextExam)
                    <flux:menu.item icon="academic-cap">
                        {{ __('لديك اختبار قادم') }}
                    </flux:menu.item>
                @endif
                @if($topbarHasPendingMission)
                    <flux:menu.item icon="book-open">
                        {{ __('لديك مهمة حفظ لم تكتمل بعد') }}
                    </flux:menu.item>
                @endif
                @if(!$topbarNextExam && !$topbarHasPendingMission)
                    <div class="px-3 py-4 text-center text-sm text-zinc-400">{{ __('لا توجد إشعارات جديدة') }}</div>
                @endif
            </flux:menu>
        </flux:dropdown>

        <button disabled class="relative p-2 rounded-full text-zinc-300 dark:text-zinc-600 cursor-not-allowed" title="{{ __('الرسائل - قريباً') }}">
            <flux:icon icon="envelope" class="size-5" />
            <flux:badge size="sm" class="absolute -top-1 -left-1">{{ __('قريباً') }}</flux:badge>
        </button>

        <flux:dropdown position="bottom" align="start">
            <button class="flex items-center gap-2.5 rounded-full hover:bg-zinc-50 dark:hover:bg-zinc-800 pe-1 transition-colors">
                <div class="text-end hidden xl:block">
                    <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100 leading-tight">{{ $topbarStudent->name }}</div>
                    <div class="text-[11px] text-zinc-400">{{ __('طالب') }}</div>
                </div>
                @if($topbarStudent->avatar_path)
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($topbarStudent->avatar_path) }}" class="size-9 rounded-full object-cover border border-zinc-200 dark:border-zinc-700" />
                @else
                    <div class="size-9 rounded-full flex items-center justify-center font-bold text-xs border border-zinc-200 dark:border-zinc-700" style="{{ $topbarStudent->avatarStyle() }}">
                        {{ $topbarStudent->initials() }}
                    </div>
                @endif
            </button>

            <flux:menu>
                <flux:menu.item :href="route('student.settings')" icon="cog" wire:navigate>
                    {{ __('الإعدادات') }}
                </flux:menu.item>
                <flux:menu.separator />
                <form method="POST" action="{{ route('student.logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer">
                        {{ __('تسجيل الخروج') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </div>
</div>
