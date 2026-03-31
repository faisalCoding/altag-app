<div class="space-y-6">
    <div class="flex items-center gap-3">
        <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
            <flux:icon icon="users" />
        </div>
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">إدارة المعلمين</flux:heading>
            <flux:subheading>إدارة شؤون المعلمين والموافقة عليهم</flux:subheading>
        </div>
    </div>

    <div class="flex flex-col md:flex-row gap-4 items-end">
        <div class="flex-1">
            <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="بحث عن معلم..." />
        </div>
        <div class="w-full md:w-48">
            <flux:select wire:model.live="circleFilter" placeholder="تصفية حسب الحلقة">
                <flux:select.option value="all">الكل</flux:select.option>
                @foreach($circles as $circle)
                    <flux:select.option :value="$circle->id">{{ $circle->name }}</flux:select.option>
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

    <div
        class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column class="text-right">المعلم</flux:table.column>
                <flux:table.column class="text-right">الحلقات</flux:table.column>
                <flux:table.column class="text-center">الحالة</flux:table.column>
                <flux:table.column class="text-center">تاريخ الإضافة</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($teachers as $teacher)
                    <flux:table.row :key="$teacher->id">
                        <flux:table.cell>
                            <div class="flex flex-col text-right">
                                <span class="font-bold text-zinc-900 dark:text-white">{{ $teacher->name }}</span>
                                <span class="text-xs text-zinc-500">{{ $teacher->email }}</span>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @forelse($teacher->circles as $circle)
                                    <flux:badge size="sm" variant="neutral">{{ $circle->name }}</flux:badge>
                                @empty
                                    <span class="text-xs text-zinc-400">لا يوجد حلقات</span>
                                @endforelse
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="text-center">
                            @if ($teacher->is_approved)
                                <flux:badge size="sm" variant="success">معتمد</flux:badge>
                            @else
                                <flux:badge size="sm" variant="warning">قيد الانتظار</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-center text-xs text-zinc-400">
                            {{ $teacher->created_at?->format('Y-m-d') }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center justify-end gap-2">
                                @if (!$teacher->is_approved)
                                    <flux:button size="sm" variant="primary"
                                        class="bg-emerald-600 hover:bg-emerald-700"
                                        wire:click="approve({{ $teacher->id }})">موافقة</flux:button>
                                @endif
                                <flux:button size="sm" variant="ghost" icon="pencil-square"
                                    wire:click="edit({{ $teacher->id }})" />
                                <flux:button size="sm" variant="ghost" icon="trash"
                                    class="text-red-500 hover:text-red-600"
                                    wire:confirm="هل أنت متأكد من حذف هذا المعلم؟"
                                    wire:click="delete({{ $teacher->id }})" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="teacher-modal" class="md:w-[600px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">تعديل بيانات المعلم</flux:heading>
                <flux:subheading>قم بتحديث بيانات المعلم وتعيين الحلقات له.</flux:subheading>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:input label="الاسم" wire:model="name" required />
                <flux:input label="البريد الإلكتروني" wire:model="email" type="email" required />
            </div>

            <div class="space-y-2">
                <flux:heading>تعيين الحلقات</flux:heading>
                <div
                    class="grid grid-cols-2 gap-2 max-h-48 overflow-y-auto p-2 border border-zinc-100 rounded-lg dark:border-zinc-800">
                    @foreach ($circles as $circle)
                        <div class="flex items-center gap-2">
                            <flux:checkbox wire:model="selectedCircles" :value="$circle->id"
                                :id="'circle-'.$circle->id" />
                            <flux:label :for="'circle-'.$circle->id" class="cursor-pointer">{{ $circle->name }}
                            </flux:label>
                            <span class="text-xs text-zinc-400">({{ $circle->stage->name }})</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex gap-2 justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost" wire:click="cancel">إلغاء</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" class="bg-maroon hover:bg-burgundy dark:bg-red-secondary">
                    حفظ التغييرات</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
