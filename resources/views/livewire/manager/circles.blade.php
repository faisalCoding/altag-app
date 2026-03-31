<div class="space-y-6">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                <flux:icon icon="circle-stack" />
            </div>
            <div>
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">إدارة الحلقات</flux:heading>
                <flux:subheading>إدارة حلقات التسميع والحفظ بالمجمع</flux:subheading>
            </div>
        </div>

        <flux:button wire:click="create" variant="primary" icon="plus" class="bg-white text-maroon border-zinc-200 hover:bg-zinc-50 shadow-xs dark:bg-zinc-800 dark:text-white dark:border-zinc-700">
            إضافة حلقة جديدة
        </flux:button>
    </div>

    <div class="flex flex-col md:flex-row gap-4 items-end">
        <div class="flex-1">
            <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="بحث عن حلقة..." />
        </div>
        <div class="w-full md:w-48">
            <flux:select wire:model.live="teacherFilter" placeholder="تصفية حسب المعلم">
                <flux:select.option value="all">الكل</flux:select.option>
                @foreach($teachersList as $teacher)
                    <flux:select.option :value="$teacher->id">{{ $teacher->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-full md:w-48">
            <flux:select wire:model.live="stageFilter" placeholder="تصفية حسب المرحلة">
                <flux:select.option value="">الكل</flux:select.option>
                @foreach($stages as $stage)
                    <flux:select.option :value="$stage->id">{{ $stage->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column class="text-right">اسم الحلقة</flux:table.column>
                <flux:table.column class="text-right">المرحلة</flux:table.column>
                <flux:table.column class="text-center">معلمون</flux:table.column>
                <flux:table.column class="text-center">طلاب</flux:table.column>
                <flux:table.column class="text-center">تاريخ الإضافة</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($circles as $circle)
                    <flux:table.row :key="$circle->id">
                        <flux:table.cell class="font-bold text-zinc-900 dark:text-white">{{ $circle->name }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" variant="neutral">{{ $circle->stage->name }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="text-center">
                            <div class="flex flex-wrap items-center justify-center gap-1">
                                    @forelse($circle->teachers as $teacher)
                                        <flux:badge size="sm" variant="success">{{ $teacher->name }}</flux:badge>
                                    @empty
                                        <span class="text-xs text-zinc-400">لا يوجد معلمين</span>
                                    @endforelse
                                </div>
                        </flux:table.cell>
                        <flux:table.cell class="text-center">
                            <flux:badge size="sm" variant="neutral">{{ $circle->students_count }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="text-center text-xs text-zinc-400">
                            {{ $circle->created_at?->format('Y-m-d') }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center justify-end gap-2">
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $circle->id }})" />
                                <flux:button size="sm" variant="ghost" icon="trash" class="text-red-500 hover:text-red-600" wire:confirm="هل أنت متأكد من حذف هذه الحلقة؟" wire:click="delete({{ $circle->id }})" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center py-16">
                            <flux:text class="text-zinc-400">لا توجد حلقات حالياً</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="circle-modal" class="md:w-[500px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">@if($editingCircleId) تعديل الحلقة @else إضافة حلقة جديدة @endif</flux:heading>
                <flux:subheading>أدخل بيانات حلقة التحفيظ أدناه.</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:input label="اسم الحلقة" wire:model="name" placeholder="مثال: حلقة ابن كثير" required />
                
                <flux:select label="المرحلة التعليمية" wire:model="stage_id" placeholder="اختر المرحلة..." required>
                    @foreach($stages as $stage)
                        <flux:select.option :value="$stage->id">{{ $stage->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:textarea label="وصف الحلقة (اختياري)" wire:model="description" placeholder="وصف موجز للحلقة..." />
            </div>

            <div class="space-y-2">
                <flux:heading>تعيين المعلمين</flux:heading>
                <div class="grid grid-cols-2 gap-2 max-h-48 overflow-y-auto p-2 border border-zinc-100 rounded-lg dark:border-zinc-800">
                    @forelse($teachersList as $teacher)
                        <div class="flex items-center gap-2">
                            <flux:checkbox wire:model="selectedTeachers" :value="$teacher->id" :id="'tch-'.$teacher->id" />
                            <flux:label :for="'tch-'.$teacher->id" class="cursor-pointer">{{ $teacher->name }}</flux:label>
                        </div>
                    @empty
                        <span class="text-xs text-zinc-400 col-span-2 text-center py-2">لا يوجد معلمين متاحين</span>
                    @endforelse
                </div>
            </div>

            <div class="flex gap-2 justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost" wire:click="cancel">إلغاء</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" class="bg-maroon hover:bg-burgundy dark:bg-red-secondary">حفظ البيانات</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
