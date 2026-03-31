<div class="space-y-6">
    <div>
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                    <flux:icon icon="rectangle-stack" />
                </div>
                <div>
                    <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">إدارة المراحل</flux:heading>
                    <flux:subheading>إدارة المراحل التعليمية في المجمع</flux:subheading>
                </div>
            </div>

            <flux:button wire:click="create" variant="primary" icon="plus" class="bg-white text-maroon border-zinc-200 hover:bg-zinc-50 shadow-xs dark:bg-zinc-800 dark:text-white dark:border-zinc-700">
                إضافة مرحلة جديدة
            </flux:button>
        </div>

        <div class="flex flex-col md:flex-row gap-4 items-end mb-6">
            <div class="flex-1">
                <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="بحث باسم المرحلة أو الوصف..." />
            </div>
            <div class="w-full md:w-64">
                <flux:select wire:model.live="supervisorFilter" placeholder="تصفية حسب المشرفين">
                    <flux:select.option value="all">الكل</flux:select.option>
                    @foreach($supervisorsList as $sup)
                        <flux:select.option :value="$sup->id">{{ $sup->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column class="text-right">اسم المرحلة</flux:table.column>
                <flux:table.column class="text-right">الوصف</flux:table.column>
                <flux:table.column class="text-center">المشرفين الحاضرين</flux:table.column>
                <flux:table.column class="text-center">عدد الحلقات</flux:table.column>
                <flux:table.column class="text-center">تاريخ الإضافة</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($stages as $stage)
                    <flux:table.row :key="$stage->id">
                        <flux:table.cell class="font-bold text-zinc-900 dark:text-white">{{ $stage->name }}</flux:table.cell>
                        <flux:table.cell class="text-zinc-500 dark:text-zinc-400 max-w-xs truncate">{{ $stage->description ?: '-' }}</flux:table.cell>
                        <flux:table.cell class="text-center">
                            <div class="flex flex-wrap items-center justify-center gap-1">
                                @forelse($stage->supervisors as $sup)
                                    <flux:badge size="sm" variant="neutral">{{ $sup->name }}</flux:badge>
                                @empty
                                    <span class="text-xs text-zinc-400">لا يوجد مشرفين</span>
                                @endforelse
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="text-center">
                            <flux:badge size="sm" variant="neutral">{{ $stage->circles_count }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="text-center text-xs text-zinc-400">
                            {{ $stage->created_at?->format('Y-m-d') }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center justify-end gap-2">
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $stage->id }})" />
                                <flux:button size="sm" variant="ghost" icon="trash" class="text-red-500 hover:text-red-600" wire:confirm="هل أنت متأكد من حذف هذه المرحلة؟" wire:click="delete({{ $stage->id }})" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="text-center py-16">
                            <flux:text class="text-zinc-400">لا توجد مراحل حالياً</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="stage-modal" class="md:w-[500px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">@if($editingStageId) تعديل المرحلة @else إضافة مرحلة جديدة @endif</flux:heading>
                <flux:subheading>أدخل بيانات المرحلة التعليمية أدناه.</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:input label="اسم المرحلة" wire:model="name" placeholder="مثال: المرحلة الابتدائية" required />
                <flux:textarea label="وصف المرحلة (اختياري)" wire:model="description" placeholder="وصف موجز للمرحلة..." />
            </div>

            <div class="space-y-2">
                <flux:heading>تعيين المشرفين</flux:heading>
                <div class="grid grid-cols-2 gap-2 max-h-48 overflow-y-auto p-2 border border-zinc-100 rounded-lg dark:border-zinc-800">
                    @forelse($supervisorsList as $sup)
                        <div class="flex items-center gap-2">
                            <flux:checkbox wire:model="selectedSupervisors" :value="$sup->id" :id="'sup-'.$sup->id" />
                            <flux:label :for="'sup-'.$sup->id" class="cursor-pointer">{{ $sup->name }}</flux:label>
                        </div>
                    @empty
                        <span class="text-xs text-zinc-400 col-span-2 text-center py-2">لا يوجد مشرفين متاحين</span>
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
