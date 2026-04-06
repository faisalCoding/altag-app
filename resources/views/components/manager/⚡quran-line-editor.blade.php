<?php

use Livewire\Component;
use App\Models\Surah;
use App\Models\Ayah;
use Livewire\Volt\Component as VoltComponent;

new class extends Component
{
    public $surahId = 1;

    public function with()
    {
        return [
            'surahs' => Surah::orderBy('id')->get(),
            'ayahs' => Ayah::where('surah_id', $this->surahId)
                ->orderBy('verse_number')
                ->get(),
        ];
    }

    public function updateLine($ayahId, $value)
    {
        Ayah::where('id', $ayahId)->update(['line_number' => (int) $value]);
        
        // No need for a global toast here to avoid clutter during bulk edits, 
        // maybe a small success state per row could be better.
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('محرر أسطر المصحف') }}</flux:heading>
            <flux:subheading>{{ __('مراجعة وتعديل أرقام الأسطر لآيات المصحف الشريف') }}</flux:subheading>
        </div>
        
        <div class="w-64">
            <flux:select wire:model.live="surahId" placeholder="{{ __('اختر السورة...') }}">
                @foreach ($surahs as $surah)
                    <flux:select.option value="{{ $surah->id }}">{{ $surah->number }}. {{ $surah->name_arabic }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <flux:card class="p-0 overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column class="w-20">#</flux:table.column>
                <flux:table.column>{{ __('الآية') }}</flux:table.column>
                <flux:table.column class="w-24">{{ __('الصفحة') }}</flux:table.column>
                <flux:table.column class="w-32">{{ __('السطر (نهاية الآية)') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($ayahs as $ayah)
                    <flux:table.row :key="$ayah->id">
                        <flux:table.cell class="font-mono text-zinc-400">{{ $ayah->verse_number }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-col gap-1">
                                <span class="text-lg font-arabic leading-loose">{{ $ayah->text_uthmani }}</span>
                                <span class="text-xs text-zinc-400">{{ $ayah->verse_key }}</span>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" color="zinc">{{ $ayah->page_number }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:input 
                                type="number" 
                                size="sm" 
                                value="{{ $ayah->line_number }}" 
                                wire:change="updateLine({{ $ayah->id }}, $event.target.value)"
                                class="w-20"
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>