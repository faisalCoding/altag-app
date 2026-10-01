@props(['data'])

@php
    /*
     * The week as it is printed and handed out: the brand row, then one row per
     * programme day. It keeps its light, printed look in dark mode too, since it
     * is the same sheet on screen and on paper.
     */
    $week = $data['week'];
    $track = $data['track'];
    $settings = $data['settings'];
    $table = $settings['table'];
    $theme = $data['theme'];
    $columns = $track->columns;
    $template = '104px '.collect($data['weights'])->map(fn ($weight) => "minmax(0,{$weight}fr)")->implode(' ')
        .($table['show_memo'] ? ' minmax(0,1.75fr)' : '');
@endphp

<div {{ $attributes->merge(['class' => 'schedule-poster relative overflow-hidden rounded-[26px] bg-white p-5 text-[#173a4d] shadow-[0_16px_44px_rgba(24,64,90,0.12)] print:shadow-none']) }}
     style="min-width: {{ 360 + $columns * 150 }}px" dir="rtl">
    <div class="pointer-events-none absolute inset-x-0 top-0 h-40 opacity-60"
         style="background: radial-gradient(220px 120px at 8% 0%, #d7f0ff 0%, transparent 70%), radial-gradient(240px 130px at 30% 0%, #ffe6d3 0%, transparent 70%), radial-gradient(260px 130px at 70% 0%, #e7e0fb 0%, transparent 70%), radial-gradient(220px 120px at 94% 0%, #d9f3e7 0%, transparent 70%)"></div>

    <div class="relative flex items-center justify-between gap-5 px-1.5 pb-4 pt-1">
        <div class="shrink-0">
            @if ($settings['logo'])
                <img src="{{ asset($settings['logo']['path']) }}" alt="{{ $settings['title'] }}" class="block w-auto mix-blend-multiply" style="height: {{ $settings['logo']['height'] }}px">
            @else
                <span class="text-3xl font-black text-[#f2794d]">{{ $settings['title'] }}</span>
            @endif
        </div>

        @if ($table['show_tagline'] && $settings['tagline'])
            <div class="max-w-52 shrink-0 whitespace-pre-line border-s-[3px] border-[#bfe3f4] ps-2.5 text-[13px] font-medium leading-relaxed text-[#3c5766]">{{ $settings['tagline'] }}</div>
        @endif

        <div class="flex flex-1 justify-center">
            <div class="min-w-80 rounded-[22px] border px-10 py-3 text-center"
                 style="background: linear-gradient(180deg, {{ $theme['title_from'] }}, {{ $theme['title_to'] }}); border-color: {{ $theme['title_border'] }}">
                <h2 class="text-[34px] font-black leading-snug" style="color: {{ $theme['title'] }}">{{ $week->displayTitle() }}</h2>
                <span class="mt-1 inline-block rounded-full px-5 py-1 text-base font-bold text-white" style="background: {{ $theme['stage'] }}">{{ $track->name }}</span>
                <span class="mt-1.5 block text-[12.5px] font-medium text-[#4b7a93]">{{ $data['range'] }}</span>
                @if ($week->note)
                    <span class="mt-1.5 inline-block rounded-lg bg-[#fff4dc] px-2.5 py-0.5 text-[12.5px] font-bold text-[#8a5a00]">{{ $week->note }}</span>
                @endif
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-5">
            @if ($table['show_partners'])
                @foreach ($settings['partners'] as $partner)
                    <img src="{{ asset($partner['path']) }}" alt="{{ $partner['alt'] }}" class="block w-auto mix-blend-multiply" style="height: {{ $partner['height'] }}px">
                @endforeach
            @endif
        </div>
    </div>

    <div class="relative grid gap-2" style="grid-template-columns: {{ $template }}">
        <div class="flex h-9 items-center justify-center rounded-[13px] text-[17px] font-extrabold text-white" style="background: {{ $theme['day'] }}">{{ $table['heads']['day'] }}</div>
        <div class="flex h-9 items-center justify-center rounded-[13px] text-lg font-extrabold text-white"
             style="grid-column: span {{ $columns }}; background: linear-gradient(90deg, {{ $theme['program_from'] }}, {{ $theme['program_to'] }})">{{ $table['heads']['program'] }}</div>
        @if ($table['show_memo'])
            <div class="flex h-9 items-center justify-center rounded-[13px] text-base font-extrabold text-white" style="background: {{ $theme['memo'] }}">{{ $table['heads']['memo'] }}</div>
        @endif

        @foreach ($data['grid'] as $weekday => $row)
            <div class="flex min-h-21 flex-col items-center justify-center rounded-[14px] border bg-gradient-to-b from-[#f3fbff] to-[#e7f5fd] {{ $data['dates'][$weekday]->isToday() ? 'border-[#1b9dd9] ring-1 ring-[#1b9dd9]' : 'border-[#cfe9f7]' }}">
                <span class="text-[19px] font-black leading-snug" style="color: {{ $theme['title'] }}">{{ \App\Services\ProgramScheduleService::WEEKDAY_NAMES[$weekday] }}</span>
                @if ($table['show_dates'])
                    <small class="text-[11.5px] font-medium text-[#5b87a0]">{{ $data['date_labels'][$weekday] }}</small>
                @endif
            </div>

            @foreach ($row as $slot)
                @php $cell = $slot['cell']; @endphp
                @if (! $cell)
                    <div class="min-h-21 rounded-[14px] border border-dashed border-[#e1eaf0] bg-[#f5f9fb]"></div>
                @else
                    @php $activity = $cell->activity; $wide = $slot['span'] > 1; @endphp
                    <div @class([
                            'flex min-h-21 items-center justify-center rounded-[14px] border px-2 py-2.5 text-center',
                            'flex-col gap-1.5 border-[#e1eaf0] bg-[#fbfdff]' => ! $wide,
                            'flex-row gap-3 border-[#d5eef7] bg-gradient-to-l from-[#eafaff] to-[#f2fbf6]' => $wide,
                        ])
                        @if ($wide) style="grid-column: span {{ $slot['span'] }}" @endif>
                        @if ($table['show_icons'] && $activity)
                            <span class="flex items-center gap-2">
                                <x-schedule.icon :name="$activity->icon" :color="$activity->color" :class="$wide ? 'size-7' : 'size-[27px]'" />
                                @if ($activity->second_icon)
                                    <x-schedule.icon :name="$activity->second_icon" :color="$activity->second_color ?? $activity->color" class="size-[27px]" />
                                @endif
                            </span>
                        @endif
                        <span class="flex flex-col items-center gap-0.5">
                            @if ($cell->displayTime())
                                <span class="text-lg font-black leading-tight text-[#16849b]">{{ \App\Support\ArabicDigits::toEastern($cell->displayTime()) }}</span>
                            @endif
                            <span @class(['font-extrabold text-[#127a9a] text-xl' => $wide, 'text-[13.5px] font-bold leading-snug text-[#1f4d63]' => ! $wide])>{{ $cell->displayName() }}</span>
                            @if ($cell->detail)
                                <span class="text-xs font-medium leading-snug text-[#d9432f]">{{ $cell->detail }}</span>
                            @endif
                        </span>
                    </div>
                @endif
            @endforeach

            @if ($table['show_memo'])
                @php $memo = $week->memoFor($weekday); @endphp
                <div class="flex min-h-21 flex-col items-center justify-center gap-1.5 rounded-[14px] border border-[#e1eaf0] bg-[#fbfdff] px-2 py-2.5 text-center">
                    @if ($memo['wird'] || $memo['line'])
                        @if ($table['show_icons'])
                            <x-schedule.icon name="book" color="red" />
                        @endif
                        @if ($memo['wird'])
                            <span class="text-xs font-medium leading-normal text-[#4a6270]">{{ $memo['wird'] }}</span>
                        @endif
                        @if ($memo['line'])
                            <span class="text-[12.5px] font-bold text-[#1f4d63]">{{ $memo['line'] }}</span>
                        @endif
                        @if ($memo['reps'])
                            <span class="rounded-xl bg-[#fdece4] px-3 py-0.5 text-[11.5px] font-bold text-[#d1502a]">{{ $memo['reps'] }}</span>
                        @endif
                    @endif
                </div>
            @endif
        @endforeach
    </div>
</div>
