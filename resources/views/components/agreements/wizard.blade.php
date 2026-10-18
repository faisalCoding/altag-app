@props(['agreements', 'needsName' => false, 'name' => '', 'storeKey'])

{{--
    The steps run in the browser on what came with the page, and the page
    is never drawn again (every call is renderless): the word on an
    agreement goes to the server once, when its last topic is agreed to.
    Topics agreed to on an agreement not yet sent wait in the browser, so a
    page closed halfway opens where it was left.
--}}
<div wire:ignore x-data="{
        agreements: @js($agreements),
        needsName: @js($needsName),
        name: @js($name),
        storeKey: @js($storeKey),
        progress: {},
        steps: [],
        current: 0,
        phase: 'steps',
        pressed: false,
        saving: false,
        error: null,
        reload: false,
        nameError: null,
        reduced: window.matchMedia('(prefers-reduced-motion: reduce)').matches,

        init() {
            try {
                const saved = JSON.parse(localStorage.getItem(this.storeKey) || '{}');
                this.progress = saved.progress || {};
                if (this.needsName && saved.name) this.name = saved.name;
            } catch (e) {}

            // Topics agreed to on an older text are read again.
            for (const agreement of this.agreements) {
                const kept = this.progress[agreement.id];
                if (kept && kept.version !== agreement.version) delete this.progress[agreement.id];
            }

            this.steps = this.agreements.flatMap((agreement, a) => agreement.signature ? [] : agreement.topics.map((topic, t) => ({ a, t })));

            if (this.steps.length === 0) { this.phase = 'done'; return; }

            const open = this.steps.findIndex((step) => ! this.isAgreed(step));
            // Every topic agreed to, the word itself not sent: back to the last topic, to send it.
            const unsent = this.steps.findLastIndex((step) => ! this.agreements[step.a].signature);
            this.current = open === -1 ? unsent : open;
            this.phase = this.needsName && this.name.trim().length < 3 ? 'name' : 'steps';
        },

        get step() { return this.steps[this.current] ?? null },
        get agreement() { return this.step ? this.agreements[this.step.a] : null },
        get topic() { return this.step ? this.agreement.topics[this.step.t] : null },
        get agreedCount() { return this.steps.filter((step) => this.isAgreed(step)).length },
        isAgreed(step) {
            const agreement = this.agreements[step.a];
            return !! agreement.signature || (this.progress[agreement.id]?.done ?? 0) > step.t;
        },
        ar(n) { return new Intl.NumberFormat('ar-SA').format(n) },

        save() {
            try { localStorage.setItem(this.storeKey, JSON.stringify({ progress: this.progress, name: this.needsName ? this.name : '' })); } catch (e) {}
        },

        settle(animation) {
            return Promise.race([animation.finished.catch(() => {}), new Promise((done) => setTimeout(done, 450))]);
        },

        // Out the way it goes, in from the other side: forward, the page
        // leaves to the right and the next comes in from the left, as an
        // Arabic book turns.
        async move(change, forward = true) {
            const card = this.$refs.card;
            const still = this.reduced || ! card || ! card.animate;
            const shift = forward ? 16 : -16;

            // Held hidden until the next step is in, then let go: no animation
            // lingers on the card once it has run.
            const leaving = still ? null : card.animate(
                [{ opacity: 1, transform: 'translateX(0)' }, { opacity: 0, transform: `translateX(${shift}px)` }],
                { duration: 150, easing: 'ease-in', fill: 'forwards' },
            );

            if (leaving) await this.settle(leaving);

            change();
            await this.$nextTick();
            leaving?.cancel();
            window.scrollTo({ top: 0, behavior: this.reduced ? 'auto' : 'smooth' });

            if (! still) {
                card.animate(
                    [{ opacity: 0, transform: `translateX(${-shift}px)` }, { opacity: 1, transform: 'translateX(0)' }],
                    { duration: 260, easing: 'cubic-bezier(.2,.8,.2,1)' },
                );
            }

            this.$refs.heading?.focus({ preventScroll: true });
        },

        startSteps() {
            this.name = this.name.trim();
            if (this.name.length < 3) { this.nameError = 'اكتب اسمك كاملاً كما تريد أن يُسجَّل في الإقرار.'; return; }
            this.nameError = null;
            this.save();
            this.move(() => { this.phase = 'steps' });
        },

        back() {
            if (this.current === 0 || this.saving) return;
            this.error = null;
            this.move(() => { this.current-- }, false);
        },

        // The pressed button stays green until the page has turned.
        next() {
            if (this.current < this.steps.length - 1) {
                this.move(() => { this.pressed = false; this.current++ });
            } else {
                this.finish();
            }
        },

        finish() {
            try { localStorage.removeItem(this.storeKey); } catch (e) {}
            this.move(() => { this.pressed = false; this.phase = 'done' });
            this.$nextTick(() => {
                const mark = this.$refs.doneMark;
                if (mark?.animate && ! this.reduced) {
                    mark.animate([{ transform: 'scale(.4)', opacity: 0 }, { transform: 'scale(1.12)', opacity: 1, offset: .7 }, { transform: 'scale(1)' }], { duration: 520, easing: 'ease-out', delay: 180, fill: 'backwards' });
                }
            });
        },

        // The press shows at once; the last topic of an agreement sends its word.
        async agree() {
            if (this.pressed || this.saving) return;
            const step = this.step;
            const agreement = this.agreement;
            this.error = null;
            this.pressed = true;
            this.progress[agreement.id] = { version: agreement.version, done: step.t + 1 };
            this.save();

            if (step.t === agreement.topics.length - 1) {
                this.saving = true;
                try {
                    const result = await this.$wire.sign(agreement.id, agreement.version, this.name);
                    if (! result || ! result.ok) {
                        this.fail(agreement, step, result?.message ?? 'تعذّر حفظ الإقرار، أعد المحاولة.');
                        this.reload = !! result?.reload;
                        if (result?.name) { this.nameError = result.message; this.move(() => { this.phase = 'name' }, false); }
                        return;
                    }
                    agreement.signature = { by: result.signedBy, at: result.signedAt };
                    delete this.progress[agreement.id];
                    this.save();
                } catch (e) {
                    this.fail(agreement, step, 'تعذّر الاتصال بالخادم، تحقق من الإنترنت ثم اضغط مرة أخرى.');
                    return;
                } finally {
                    this.saving = false;
                }
            }

            setTimeout(() => this.next(), this.reduced ? 120 : 420);
        },

        fail(agreement, step, message) {
            this.pressed = false;
            this.progress[agreement.id] = { version: agreement.version, done: step.t };
            this.save();
            this.error = message;
        },
    }">

    {{-- How far along: the topics agreed to of all those asked. --}}
    <div x-show="phase === 'steps'" class="mb-4 space-y-1.5" data-agreement-progress>
        <div class="flex items-center justify-between text-xs text-zinc-500">
            <span x-text="`الخطوة ${ar(current + 1)} من ${ar(steps.length)}`"></span>
            <span x-text="`أقررتَ بـ ${ar(agreedCount)}`"></span>
        </div>
        <div class="h-1.5 overflow-hidden rounded-full bg-zinc-200">
            <div class="h-full rounded-full bg-emerald-500 transition-[width] duration-500 ease-out motion-reduce:transition-none"
                x-bind:style="`width: ${steps.length ? (agreedCount / steps.length) * 100 : 0}%`"></div>
        </div>
    </div>

    <div x-ref="card" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm" aria-live="polite">
        {{-- The guardian's name, given once before the topics. --}}
        <template x-if="phase === 'name'">
            <div class="space-y-4" data-agreement-name>
                <div class="space-y-1">
                    <h2 x-ref="heading" tabindex="-1" class="text-lg font-bold text-zinc-900 outline-none">قبل البدء</h2>
                    <p class="text-sm text-zinc-600">اكتب اسمك كما تريد أن يُسجَّل في إقرارك، ثم تقرأ الموضوعات واحداً بعد الآخر.</p>
                </div>
                <label class="block space-y-1.5">
                    <span class="text-sm font-medium text-zinc-800">اسم ولي الأمر</span>
                    <input type="text" x-model="name" x-on:keydown.enter="startSteps()" autocomplete="name"
                        class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-base text-zinc-900 focus:border-zinc-500 focus:outline-none focus:ring-2 focus:ring-zinc-200">
                </label>
                <p x-show="nameError" x-text="nameError" class="text-sm text-rose-600"></p>
                <button type="button" x-on:click="startSteps()"
                    class="w-full rounded-xl bg-zinc-900 px-4 py-3 text-base font-bold text-white transition active:scale-[.98]">متابعة</button>
            </div>
        </template>

        {{-- One topic of one agreement: its clauses, and the word on it. --}}
        <template x-if="phase === 'steps' && topic">
            <div class="space-y-4" data-agreement-step>
                <div class="flex items-center justify-between gap-3 text-xs">
                    <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 font-semibold text-zinc-700" x-text="agreement.type"></span>
                    <span class="text-zinc-500" x-text="`الموضوع ${ar(step.t + 1)} من ${ar(agreement.topics.length)}`"></span>
                </div>
                <div class="space-y-1">
                    <p class="text-sm text-zinc-500" x-html="agreement.title"></p>
                    <h2 x-ref="heading" tabindex="-1" class="text-xl font-bold leading-8 text-zinc-900 outline-none" x-html="topic.title"></h2>
                </div>
                <ol class="list-[arabic-indic] space-y-2.5 ps-6 text-[15px] leading-7 text-zinc-800 marker:font-bold marker:text-zinc-400">
                    <template x-for="(clause, index) in topic.clauses" x-bind:key="index">
                        <li x-html="clause"></li>
                    </template>
                </ol>

                <div class="space-y-3 border-t border-zinc-100 pt-4">
                    <template x-if="! isAgreed(step) || pressed">
                        <button type="button" x-on:click="agree()" x-bind:disabled="saving" x-bind:aria-busy="saving"
                            class="relative flex w-full items-center justify-center gap-2 overflow-hidden rounded-xl px-4 py-3.5 text-base font-bold text-white transition-all duration-200 active:scale-[.98] motion-reduce:transition-none"
                            x-bind:class="pressed && ! saving ? 'bg-emerald-600 scale-[1.01] shadow-lg shadow-emerald-600/20' : 'bg-maroon hover:bg-burgundy'"
                            data-agreement-agree>
                            <svg x-show="pressed && ! saving" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                            <svg x-show="saving" class="size-5 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3" /><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                            <span x-text="saving ? 'جارٍ حفظ إقرارك…' : (pressed ? 'تم الإقرار' : 'أُقرّ بما ورد في هذا الموضوع')"></span>
                        </button>
                    </template>
                    <template x-if="isAgreed(step) && ! pressed">
                        <div class="flex items-center justify-between gap-3 rounded-xl bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800">
                            <span class="flex items-center gap-1.5">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                                أقررتَ بهذا الموضوع
                            </span>
                            <button type="button" x-on:click="next()" class="font-bold underline-offset-4 hover:underline">التالي</button>
                        </div>
                    </template>
                    <div x-show="error" class="rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        <span x-text="error"></span>
                        <button x-show="reload" type="button" x-on:click="location.reload()" class="ms-1 font-bold underline">إعادة فتح الصفحة</button>
                    </div>
                    <button type="button" x-show="current > 0" x-on:click="back()" class="text-sm text-zinc-500 hover:text-zinc-800">الموضوع السابق</button>
                </div>
            </div>
        </template>

        {{-- Every word given: what was agreed to, by whom and when. --}}
        <template x-if="phase === 'done'">
            <div class="space-y-5 text-center" data-agreement-done>
                <div x-ref="doneMark" class="mx-auto flex size-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                    <svg class="size-9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                </div>
                <div class="space-y-1">
                    <h2 x-ref="heading" tabindex="-1" class="text-lg font-bold text-zinc-900 outline-none"
                        x-text="agreements.length ? 'جزاكم الله خيراً، اكتملت الإقرارات' : 'لا توجد إقرارات مطلوبة الآن'"></h2>
                    <p x-show="agreements.length" class="text-sm text-zinc-500">سُجّل إقرارك، ويمكنك إغلاق هذه الصفحة.</p>
                </div>
                <ul class="space-y-2 text-start">
                    <template x-for="agreement in agreements" x-bind:key="agreement.id">
                        <li class="flex items-start gap-2 rounded-xl bg-zinc-50 px-3 py-2.5">
                            <svg class="mt-0.5 size-4 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-zinc-900" x-html="agreement.title"></p>
                                <p x-show="agreement.signature" class="text-xs text-zinc-500" x-text="agreement.signature ? `أقرّ ${agreement.signature.by} في ${agreement.signature.at}` : ''"></p>
                            </div>
                        </li>
                    </template>
                </ul>
            </div>
        </template>
    </div>
</div>
