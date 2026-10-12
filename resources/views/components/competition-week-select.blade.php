@props(['weeks'])

{{-- The standings over the whole competition, or over one week of its days
     (App\Support\CompetitionWeeks). Bind it with wire:model.live. --}}
<flux:select size="sm" {{ $attributes->class('max-w-72') }} data-week-filter aria-label="{{ __('ترتيب المتصدرين خلال') }}">
    <flux:select.option value="">{{ __('كل أيام المسابقة') }}</flux:select.option>
    @foreach ($weeks as $week)
        <flux:select.option value="{{ $week['number'] }}">{{ $week['label'] }}</flux:select.option>
    @endforeach
</flux:select>
