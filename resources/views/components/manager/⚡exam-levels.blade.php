<?php

use App\Models\ExamLevel;
use App\Models\Surah;
use App\Models\Ayah;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Locked;

new class extends Component {
    use WithPagination;

    public $search = '';

    public $showModal = false;
    #[Locked]
    public $editingId = null;

    public $name = '';
    public $direction = 'nas_to_baqarah';
    
    public $startSurahId = null;
    public $startAyahId = null;
    public $endSurahId = null;
    public $endAyahId = null;
    
    public $previousLevelId = null;

    public $surahs = [];
    public $startAyahs = [];
    public $endAyahs = [];

    protected $rules = [
        'name' => 'required|string|max:255',
        'direction' => 'required|in:nas_to_baqarah,baqarah_to_nas',
        'startAyahId' => 'required|exists:ayahs,id',
        'endAyahId' => 'required|exists:ayahs,id',
        'previousLevelId' => 'nullable|exists:exam_levels,id',
    ];

    public function messages()
    {
        return [
            'name.required' => 'اسم المستوى مطلوب.',
            'name.string' => 'اسم المستوى يجب أن يكون نصاً.',
            'name.max' => 'اسم المستوى يجب ألا يتجاوز 255 حرفاً.',
            'direction.required' => 'تحديد الاتجاه مطلوب.',
            'direction.in' => 'الاتجاه المحدد غير صحيح.',
            'startAyahId.required' => 'تحديد آية البداية مطلوب.',
            'startAyahId.exists' => 'الآية المحددة للبداية غير موجودة.',
            'endAyahId.required' => 'تحديد آية النهاية مطلوب.',
            'endAyahId.exists' => 'الآية المحددة للنهاية غير موجودة.',
        ];
    }

    public function mount()
    {
        $this->surahs = Surah::orderBy('id')->get();
    }

    public function updatedStartSurahId($value)
    {
        $this->startAyahs = $value ? Ayah::where('surah_id', $value)->orderBy('verse_number')->get() : [];
        $this->startAyahId = null;
    }

    public function updatedEndSurahId($value)
    {
        $this->endAyahs = $value ? Ayah::where('surah_id', $value)->orderBy('verse_number')->get() : [];
        $this->endAyahId = null;
    }

    public function create()
    {
        $this->resetValidation();
        $this->reset(['editingId', 'name', 'direction', 'startSurahId', 'startAyahId', 'endSurahId', 'endAyahId', 'startAyahs', 'endAyahs', 'previousLevelId']);
        $this->showModal = true;
    }

    public function edit($id)
    {
        $this->resetValidation();
        $level = ExamLevel::with(['startAyah', 'endAyah'])->findOrFail($id);
        
        $this->editingId = $level->id;
        $this->name = $level->name;
        $this->direction = $level->direction;
        $this->previousLevelId = $level->previous_level_id;
        
        $this->startSurahId = $level->startAyah->surah_id ?? null;
        if ($this->startSurahId) {
            $this->startAyahs = Ayah::where('surah_id', $this->startSurahId)->orderBy('verse_number')->get();
            $this->startAyahId = $level->start_ayah_id;
        }

        $this->endSurahId = $level->endAyah->surah_id ?? null;
        if ($this->endSurahId) {
            $this->endAyahs = Ayah::where('surah_id', $this->endSurahId)->orderBy('verse_number')->get();
            $this->endAyahId = $level->end_ayah_id;
        }

        $this->showModal = true;
    }

    public function save()
    {
        $this->validate();

        ExamLevel::updateOrCreate(
            ['id' => $this->editingId],
            [
                'name' => $this->name,
                'direction' => $this->direction,
                'start_ayah_id' => $this->startAyahId,
                'end_ayah_id' => $this->endAyahId,
                'previous_level_id' => $this->previousLevelId ?: null,
            ]
        );

        $this->showModal = false;
        $this->dispatch('toast', variant: 'success', heading: 'تم الحفظ بنجاح!');
    }

    public function delete($id)
    {
        ExamLevel::findOrFail($id)->delete();
        $this->dispatch('toast', variant: 'success', heading: 'تم الحذف بنجاح!');
    }

    public function with()
    {
        $levels = ExamLevel::with(['startAyah.surah', 'endAyah.surah', 'nextLevel'])
            ->where('name', 'like', '%' . $this->search . '%')
            ->latest()
            ->paginate(15);

        return [
            'levels' => $levels,
            'allLevels' => ExamLevel::where('id', '!=', $this->editingId)->get(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <flux:heading size="xl">{{ __('مستويات الاختبارات') }}</flux:heading>
            <flux:subheading>{{ __('إدارة مستويات الاختبارات ونطاقها في القرآن الكريم') }}</flux:subheading>
        </div>
        <flux:button variant="primary" wire:click="create" icon="plus">{{ __('إضافة مستوى جديد') }}</flux:button>
    </div>

    <flux:card>
        <div class="mb-4 w-full sm:w-1/3">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="{{ __('بحث بالاسم...') }}" />
        </div>

        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('اسم المستوى') }}</flux:table.column>
                    <flux:table.column>{{ __('الاتجاه') }}</flux:table.column>
                    <flux:table.column>{{ __('بداية الاختبار') }}</flux:table.column>
                    <flux:table.column>{{ __('نهاية الاختبار') }}</flux:table.column>
                    <flux:table.column>{{ __('المستوى التالي') }}</flux:table.column>
                    <flux:table.column>{{ __('الإجراءات') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($levels as $level)
                        <flux:table.row>
                            <flux:table.cell class="font-semibold">{{ $level->name }}</flux:table.cell>
                            <flux:table.cell class="first:ps-3" >
                                @if($level->direction === 'nas_to_baqarah')
                                    <flux:badge color="indigo">من الناس إلى البقرة</flux:badge>
                                @else
                                    <flux:badge color="emerald">من البقرة إلى الناس</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="first:ps-3" >
                                @if($level->startAyah)
                                    سورة {{ $level->startAyah->surah->name_arabic }} - آية {{ $level->startAyah->verse_number }}
                                @else
                                    -
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="first:ps-3" >
                                @if($level->endAyah)
                                    سورة {{ $level->endAyah->surah->name_arabic }} - آية {{ $level->endAyah->verse_number }}
                                @else
                                    -
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="first:ps-3" >
                                @if($level->nextLevel)
                                    <flux:badge size="sm" color="zinc">{{ $level->nextLevel->name }}</flux:badge>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="first:ps-3" >
                                <flux:dropdown>
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" inset="top bottom" />
                                    <flux:menu>
                                        <flux:menu.item wire:click="edit({{ $level->id }})" icon="pencil-square">{{ __('تعديل') }}</flux:menu.item>
                                        <flux:menu.item wire:click="delete({{ $level->id }})" wire:confirm="{{ __('هل أنت متأكد من الحذف؟') }}" icon="trash" variant="danger">{{ __('حذف') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center text-zinc-500 py-8">
                                {{ __('لا توجد مستويات لعرضها.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        <div class="mt-4">
            {{ $levels->links() }}
        </div>
    </flux:card>

    <flux:modal wire:model="showModal" class="md:w-3/4 max-w-2xl">
        <form wire:submit.prevent="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('تعديل المستوى') : __('إضافة مستوى جديد') }}</flux:heading>

            <flux:input wire:model="name" label="{{ __('اسم المستوى') }}" placeholder="{{ __('مثال: الثلاثة أجزاء الأخيرة') }}" />

            <flux:radio.group wire:model="direction" label="{{ __('اتجاه الاختبار') }}">
                <flux:radio value="nas_to_baqarah" label="{{ __('من الناس إلى البقرة') }}" />
                <flux:radio value="baqarah_to_nas" label="{{ __('من البقرة إلى الناس') }}" />
            </flux:radio.group>

            <flux:select wire:model="previousLevelId" label="{{ __('المستوى السابق (اختياري)') }}">
                <flux:select.option value="">{{ __('بدون مستوى سابق') }}</flux:select.option>
                @foreach($allLevels as $lvl)
                    <flux:select.option value="{{ $lvl->id }}">{{ $lvl->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-zinc-50 dark:bg-zinc-800/50 p-4 rounded-xl">
                <div class="col-span-full">
                    <flux:heading size="sm" class="mb-2">{{ __('بداية الاختبار') }}</flux:heading>
                </div>
                <flux:select wire:model.live="startSurahId" label="{{ __('السورة') }}">
                    @foreach($surahs as $surah)
                        <flux:select.option value="{{ $surah->id }}">{{ $surah->name_arabic }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div>
                    <flux:label class="mb-2">{{ __('الآية') }}</flux:label>
                    <select wire:model="startAyahId" class="w-full text-sm p-2 border border-zinc-200 rounded-lg dark:bg-zinc-900 dark:border-zinc-700 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-shadow disabled:opacity-50" {{ !$startSurahId ? 'disabled' : '' }}>
                        <option value="">{{ __('اختر الآية') }}</option>
                        @foreach($startAyahs as $ayah)
                            <option value="{{ $ayah->id }}">{{ __('آية') }} {{ $ayah->verse_number }}</option>
                        @endforeach
                    </select>
                    @error('startAyahId') <div class="text-red-500 text-xs mt-1">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-zinc-50 dark:bg-zinc-800/50 p-4 rounded-xl">
                <div class="col-span-full">
                    <flux:heading size="sm" class="mb-2">{{ __('نهاية الاختبار') }}</flux:heading>
                </div>
                <flux:select wire:model.live="endSurahId" label="{{ __('السورة') }}">
                    @foreach($surahs as $surah)
                        <flux:select.option value="{{ $surah->id }}">{{ $surah->name_arabic }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div>
                    <flux:label class="mb-2">{{ __('الآية') }}</flux:label>
                    <select wire:model="endAyahId" class="w-full text-sm p-2 border border-zinc-200 rounded-lg dark:bg-zinc-900 dark:border-zinc-700 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-shadow disabled:opacity-50" {{ !$endSurahId ? 'disabled' : '' }}>
                        <option value="">{{ __('اختر الآية') }}</option>
                        @foreach($endAyahs as $ayah)
                            <option value="{{ $ayah->id }}">{{ __('آية') }} {{ $ayah->verse_number }}</option>
                        @endforeach
                    </select>
                    @error('endAyahId') <div class="text-red-500 text-xs mt-1">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="flex justify-end gap-2 mt-6">
                <flux:button variant="ghost" wire:click="$set('showModal', false)">{{ __('إلغاء') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('حفظ') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>