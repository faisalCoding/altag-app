@props([
    'icon' => 'users',
    'heading' => 'لا معلمين',
    'message' => 'لا يوجد معلمون في حلقات مراحلك مطابقون لهذه التصفية.',
])

<div class="p-16 text-center">
    <flux:icon :icon="$icon" class="size-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
    <flux:heading size="lg" class="text-zinc-500 dark:text-zinc-400">{{ $heading }}</flux:heading>
    <flux:subheading class="text-zinc-400 dark:text-zinc-500">{{ $message }}</flux:subheading>
</div>
