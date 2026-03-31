<div class="space-y-6">
    <div class="flex items-center gap-3">
        <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
            <flux:icon icon="users" />
        </div>
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">إدارة المشرفين</flux:heading>
            <flux:subheading>إدارة شؤون المشرفين والموافقة عليهم وتعيين المراحل.</flux:subheading>
        </div>
    </div>

    <div class="flex flex-col md:flex-row gap-4 items-end">
        <div class="flex-1">
            <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="بحث عن مشرف..." />
        </div>
        <div class="w-full md:w-48">
            <flux:select wire:model.live="stageFilter" placeholder="تصفية حسب المرحلة">
                <flux:select.option value="all">الكل</flux:select.option>
                @foreach($stages as $stage)
                    <flux:select.option :value="$stage->id">{{ $stage->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-full md:w-48">
            <flux:select wire:model.live="statusFilter" placeholder="تصفية حسب الحالة">
                <flux:select.option value="all">الكل</flux:select.option>
                <flux:select.option value="pending">في انتظار الموافقة</flux:select.option>
                <flux:select.option value="approved">تمت الموافقة</flux:select.option>
            </flux:select>
        </div>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column class="text-right">المشرف</flux:table.column>
                <flux:table.column class="text-right">المراحل</flux:table.column>
                <flux:table.column class="text-center">الحالة</flux:table.column>
                <flux:table.column class="text-center">تاريخ الإضافة</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($supervisors as $supervisor)
                    <flux:table.row :key="$supervisor->id">
                        <flux:table.cell>
                            <div class="flex flex-col text-right">
                                <span class="font-bold text-zinc-900 dark:text-white">{{ $supervisor->name }}</span>
                                <span class="text-xs text-zinc-500">{{ $supervisor->email }}</span>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @forelse($supervisor->stages as $stage)
                                    <flux:badge size="sm" variant="neutral">{{ $stage->name }}</flux:badge>
                                @empty
                                    <span class="text-xs text-zinc-400">لم يتم تعيين مراحل</span>
                                @endforelse
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="text-center">
                            @if($supervisor->is_approved)
                                <flux:badge size="sm" variant="success">معتمد</flux:badge>
                            @else
                                <flux:badge size="sm" variant="warning">قيد الانتظار</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-center text-xs text-zinc-400">
                            {{ $supervisor->created_at?->format('Y-m-d') }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center justify-end gap-2">
                                @if(!$supervisor->is_approved)
                                    <flux:button size="sm" variant="primary" class="bg-emerald-600 hover:bg-emerald-700" wire:click="approve({{ $supervisor->id }})">موافقة</flux:button>
                                @endif
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $supervisor->id }})" />
                                <flux:button size="sm" variant="ghost" icon="trash" class="text-red-500 hover:text-red-600" wire:confirm="هل أنت متأكد من حذف هذا المشرف؟" wire:click="delete({{ $supervisor->id }})" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="supervisor-modal" class="md:w-[600px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">تعديل بيانات المشرف</flux:heading>
                <flux:subheading>قم بتحديث بيانات المشرف وتعيين المراحل له.</flux:subheading>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:input label="الاسم" wire:model="name" required />
                <flux:input label="البريد الإلكتروني" wire:model="email" type="email" required />
            </div>

            <div class="space-y-2">
                <flux:heading>تعيين المراحل</flux:heading>
                <div class="grid grid-cols-2 gap-2 max-h-48 overflow-y-auto p-2 border border-zinc-100 rounded-lg dark:border-zinc-800">
                    @foreach($stages as $stage)
                        <div class="flex items-center gap-2">
                            <flux:checkbox wire:model="selectedStages" :value="$stage->id" :id="'stage-'.$stage->id" />
                            <flux:label :for="'stage-'.$stage->id" class="cursor-pointer">{{ $stage->name }}</flux:label>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex gap-2 justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost" wire:click="cancel">إلغاء</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" class="bg-maroon hover:bg-burgundy dark:bg-red-secondary">حفظ التغييرات</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
