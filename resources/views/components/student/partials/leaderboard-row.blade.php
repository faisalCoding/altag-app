<tr class="transition-colors duration-150 {{ $isMe ? 'bg-team-10 text-slate-900 font-bold' : 'text-slate-700 hover:bg-slate-50/50' }}">
    <td class="p-1 !w-1 text-center">
        <div class="flex justify-center items-center">
            @if($rank === 1)
                <span class="inline-flex items-center justify-center size-6 rounded-full bg-gradient-to-br from-amber-300 to-amber-500 text-amber-950 text-xs font-black shadow-sm border border-amber-200">1</span>
            @elseif($rank === 2)
                <span class="inline-flex items-center justify-center size-6 rounded-full bg-gradient-to-br from-slate-200 to-slate-400 text-slate-900 text-xs font-black shadow-sm border border-slate-300">2</span>
            @elseif($rank === 3)
                <span class="inline-flex items-center justify-center size-6 rounded-full bg-gradient-to-br from-amber-600 to-amber-700 text-amber-50 text-xs font-black shadow-sm border border-amber-500">3</span>
            @else
                <span class="text-slate-400 font-semibold text-xs">#{{ $rank }}</span>
            @endif
        </div>
    </td>
    <td class="p-2.5">
        {{-- The avatar keeps its size: a long name beside it squashed the circle into an oval. --}}
        <div class="flex items-center gap-3">
            @if($standing['student']->avatar_path)
                <img src="{{ Storage::url($standing['student']->avatar_path) }}" class="w-12 h-12 shrink-0 rounded-full object-cover border border-slate-200" />
            @else
                <div class="w-12 h-12 shrink-0 rounded-full flex items-center justify-center font-bold text-xs border border-slate-200" style="{{ $standing['student']->avatarStyle() }}">
                    {{ $standing['student']->initials() }}
                </div>
            @endif
            <div class="flex flex-col items-start min-w-0">
                <span>{{ $standing['student']->name }}</span>
                @php
                    $team = $standing['student']->gamificationTeams->first();
                @endphp
                @if($team)
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full mt-0.5" style="background-color: {{ $team->color }}1a; color: {{ $team->color }};">
                        {{ $team->name }}
                    </span>
                @elseif($standing['student']->circle)
                    <span class="text-[10px] text-slate-400 mt-0.5">{{ $standing['student']->circle->name }}</span>
                @endif
            </div>
        </div>
    </td>
    <td class="p-4 text-center font-bold">
        {{ $standing['score'] }} XP
    </td>
</tr>
