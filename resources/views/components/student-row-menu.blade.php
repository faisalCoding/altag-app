@props(['student', 'link' => null])
{{--
    The per-student actions, shared by the table and the phone cards.

    The sign-in link used to sit in a column of its own as a read-only field, so
    every token in the circle was legible at once — to anyone glancing over a
    shoulder, and to anything that photographed the screen. It is an action now:
    the teacher copies the one link they want.
--}}
<flux:dropdown>
    <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" aria-label="{{ __('إجراءات') }}" />

    <flux:menu>
        <flux:menu.item icon="eye"
            x-on:click="$flux.modal('student-details').show(); $wire.viewStudent({{ $student->id }})">
            {{ __('عرض وتعديل التفاصيل') }}
        </flux:menu.item>

        @if ($link)
            <flux:separator />

            <flux:menu.item icon="clipboard-document"
                x-on:click="navigator.clipboard.writeText(@js($link)); $dispatch('toast', { message: 'نُسخ رابط الدخول', variant: 'success' })">
                {{ __('نسخ رابط الدخول') }}
            </flux:menu.item>

            <flux:menu.item as="a" href="{{ route('magic-link.login-as', $student->access_token) }}"
                target="_blank" icon="arrow-right">
                {{ __('الدخول لحساب الطالب') }}
            </flux:menu.item>

            <flux:menu.item wire:click="resetToken({{ $student->id }})"
                wire:confirm="{{ __('إعادة إنشاء الرابط؟ يبطل الرابط القديم فوراً.') }}"
                variant="danger" icon="arrow-path">
                {{ __('إعادة إنشاء الرابط') }}
            </flux:menu.item>
        @endif
    </flux:menu>
</flux:dropdown>
