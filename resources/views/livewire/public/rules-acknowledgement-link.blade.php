@php
    use App\Support\Branding;
    use App\Support\HijriDate;
@endphp

<div class="w-full max-w-xl space-y-5">
    @push('meta')
        <meta name="robots" content="noindex, nofollow">
        <title>الإقرار بأنظمة الحلقات - {{ Branding::siteName() }}</title>
    @endpush

    <div class="flex flex-col items-center text-center gap-2">
        <img src="{{ Branding::logoUrl() }}" alt="" class="size-16 object-contain">
        <h1 class="text-xl font-bold text-zinc-900">الإقرار بأنظمة الحلقات</h1>
        <p class="text-sm text-zinc-500">{{ Branding::siteName() }}</p>
    </div>

    @if(! $version)
        <div class="rounded-2xl border border-dashed border-zinc-300 p-8 text-center text-zinc-500">
            لم تُنشر أنظمة الحلقات بعد.
        </div>
    @else
        {{-- The steps run in the browser on what came with the page: no
             waiting between them. Only the word itself goes to the server. --}}
        <div wire:ignore x-data="{
                stages: @js($stages),
                stage: null,
                person: null,
                agreed: false,
                saving: false,
                done: null,
                error: null,
                get current() { return this.stages.find(s => s.id === this.stage) ?? null },
                top() { window.scrollTo({ top: 0, behavior: 'smooth' }) },
                pickStage(id) { this.stage = id; this.person = null; this.error = null; this.top() },
                pickPerson(person) {
                    if (! person.owes) return;
                    this.person = person; this.agreed = false; this.error = null; this.top();
                },
                back() {
                    if (this.saving) return;
                    if (this.person) { this.person = null } else { this.stage = null }
                    this.error = null;
                },
                markDone(id) { this.stages.forEach(s => s.people.forEach(p => { if (p.id === id) p.owes = false })) },
                async acknowledge() {
                    if (! this.agreed || this.saving) return;
                    this.saving = true; this.error = null;
                    try {
                        const result = await this.$wire.acknowledge(this.person.id);
                        if (result.ok) {
                            this.markDone(this.person.id);
                            this.done = result.name; this.person = null; this.stage = null; this.top();
                        } else {
                            this.error = result.message;
                            if (result.already) this.markDone(this.person.id);
                        }
                    } catch (e) {
                        this.error = 'تعذّر الوصول إلى الخادم، تحقق من اتصالك بالإنترنت وأعد المحاولة.';
                    } finally {
                        this.saving = false;
                    }
                },
                startOver() { this.done = null; this.stage = null; this.person = null; this.top() },
            }">

            {{-- Done. --}}
            <div x-show="done" x-cloak class="rounded-2xl border border-emerald-200 bg-emerald-50 p-6 text-center space-y-3" data-rules-link-done>
                <flux:icon icon="check-badge" class="size-10 text-emerald-600 mx-auto" />
                <p class="font-bold text-emerald-900">شكراً لك <span x-text="done"></span>، حُفظ إقرارك بالأنظمة.</p>
                <flux:button x-on:click="startOver()" variant="ghost">إقرار شخص آخر</flux:button>
            </div>

            {{-- 1. The stage. --}}
            <div x-show="! done && ! stage" class="space-y-3">
                <p class="font-bold text-zinc-800">اختر مرحلتك</p>
                <div class="grid grid-cols-2 gap-2.5">
                    <template x-for="candidate in stages" :key="candidate.id">
                        <button type="button" x-on:click="pickStage(candidate.id)" x-text="candidate.name"
                            class="rounded-xl border border-zinc-200 bg-white px-4 py-3.5 text-sm font-bold text-zinc-800 hover:border-accent hover:text-accent active:scale-[0.98] transition"></button>
                    </template>
                </div>
            </div>

            {{-- 2. The name. --}}
            <div x-show="! done && stage && ! person" x-cloak class="space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <p class="font-bold text-zinc-800">اختر اسمك <span class="font-normal text-zinc-500" x-text="'· ' + (current?.name ?? '')"></span></p>
                    <button type="button" x-on:click="back()" class="text-sm text-zinc-500 hover:text-zinc-800">تغيير المرحلة</button>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-white divide-y divide-zinc-100">
                    <template x-for="candidate in (current?.people ?? [])" :key="candidate.id">
                        <button type="button" x-on:click="pickPerson(candidate)" x-bind:disabled="! candidate.owes"
                            x-bind:class="candidate.owes ? 'hover:bg-zinc-50 active:bg-zinc-100' : 'opacity-60 cursor-default'"
                            class="w-full flex items-center gap-3 px-4 py-3 text-start transition-colors">
                            <span class="flex-1 font-medium text-zinc-900" x-text="candidate.name"></span>
                            <span class="text-xs text-zinc-500" x-text="candidate.roles"></span>
                            <span x-show="candidate.owes"><flux:icon icon="chevron-left" class="size-4 text-zinc-400" /></span>
                            <span x-show="! candidate.owes" class="text-xs font-bold text-emerald-600">أقرّ</span>
                        </button>
                    </template>
                    <p x-show="current && current.people.length === 0" class="p-6 text-center text-sm text-zinc-500">لا معلمين ولا مشرفين في هذه المرحلة.</p>
                </div>
            </div>

            {{-- 3. The rules, and the word. --}}
            <div x-show="! done && person" x-cloak class="space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="font-bold text-zinc-900" x-text="person?.name"></p>
                        <p class="text-xs text-zinc-500">الإصدار {{ HijriDate::arabicDigits($version->number) }} · نُشر {{ HijriDate::full($version->published_at) }}</p>
                    </div>
                    <button type="button" x-on:click="back()" x-bind:disabled="saving" class="text-sm text-zinc-500 hover:text-zinc-800"
                        x-text="'لستُ ' + (person?.name ?? '').trim().split(' ')[0]"></button>
                </div>

                @foreach($texts as $key => $text)
                    <div x-show="person && person.text === @js($key)">
                        <x-rules.text :text="$text" />
                    </div>
                @endforeach

                <div class="rounded-2xl border border-zinc-200 bg-white p-5 space-y-4">
                    <flux:checkbox x-model="agreed" x-bind:disabled="saving" label="قرأتُ هذه الأنظمة وأتعهد بالالتزام بها" />

                    <p x-show="error" x-cloak x-text="error" class="text-sm text-rose-600"></p>

                    <flux:button variant="primary" x-on:click="acknowledge()" x-bind:disabled="! agreed || saving" class="w-full">
                        <span x-show="! saving" class="flex items-center gap-2">
                            <flux:icon icon="check" variant="mini" />
                            إقرار
                        </span>
                        <span x-show="saving" x-cloak class="flex items-center gap-2" data-saving>
                            <flux:icon.loading variant="mini" />
                            جارٍ حفظ إقرارك…
                        </span>
                    </flux:button>
                </div>
            </div>
        </div>
    @endif
</div>
