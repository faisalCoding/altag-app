@props(['days' => [], 'studentId', 'date'])

{{--
    The date the grades on the card are given for — the academy's day the
    session was held — with the working day before and after it, and the
    calendar under the date itself. Changing it changes it for every card on
    the page: the tasmeeh page holds it, and hears of it by an event.
--}}
<div x-data="{
        days: @js(array_values($days)),
        today: @js(\App\Services\TeacherSyncSnapshot::today()),
        current() {
            const picked = this.$wire.gradedAtDate || this.today;
            return picked > this.today ? this.today : picked;
        },
        {{-- The working day either side; any day when the calendar names none. --}}
        neighbour(step) {
            const current = this.current();

            if (this.days.length === 0) {
                const day = new Date(current + 'T12:00:00Z');
                day.setUTCDate(day.getUTCDate() + step);
                const iso = day.toISOString().slice(0, 10);
                return iso > this.today ? null : iso;
            }

            return step < 0
                ? (this.days.filter(day => day < current).at(-1) ?? null)
                : (this.days.find(day => day > current && day <= this.today) ?? null);
        },
        go(step) {
            const day = this.neighbour(step);

            if (day) {
                this.$wire.dispatch('grading-date-changed', { date: day });
            }
        },
    }" {{ $attributes->class('flex items-center justify-between gap-2') }}>
    <flux:button type="button" @click="go(-1)" x-bind:disabled="! neighbour(-1)" icon="chevron-right" variant="subtle" size="sm">
        {{ __('اليوم السابق') }}
    </flux:button>

    <div class="min-w-0 w-48 sm:w-60">
        <livewire:teacher.hijri-datepicker wire:model.live="pickedDate" :key="'grading-date-'.$studentId.'-'.$date" />
    </div>

    <flux:button type="button" @click="go(1)" x-bind:disabled="! neighbour(1)" icon-trailing="chevron-left" variant="subtle" size="sm">
        {{ __('اليوم التالي') }}
    </flux:button>
</div>
