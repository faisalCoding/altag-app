<div class="space-y-6">
    <div class="flex items-center gap-3">
        <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
            <flux:icon icon="user-group" />
        </div>
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">إدارة الأوصياء</flux:heading>
            <flux:subheading>إدارة شؤون أولياء أمور الطلاب</flux:subheading>
        </div>
    </div>

    <div class="flex flex-col md:flex-row gap-4 items-end">
        <div class="flex-1">
            <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="بحث عن ولي أمر..." />
        </div>
        <div class="w-full md:w-64">
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
                <flux:table.column class="text-right"> ولي الأمر </flux:table.column>
                <flux:table.column class="text-right"> الأبناء </flux:table.column>
                <flux:table.column class="text-center"> الحالة </flux:table.column>
                <flux:table.column class="text-center"> تاريخ الإضافة </flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($guardians as $guardian)
                    <flux:table.row :key="$guardian->id">
                        <flux:table.cell>
                            <div class="flex flex-col text-right">
                                <span class="font-bold text-zinc-900 dark:text-white">{{ $guardian->name }}</span>
                                <span class="text-xs text-zinc-500">{{ $guardian->email }}</span>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @forelse($guardian->students as $student)
                                    <flux:badge size="sm" variant="neutral">{{ $student->name }}</flux:badge>
                                @empty
                                    <span class="text-xs text-zinc-400">لا يوجد أبناء مسجلين</span>
                                @endforelse
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="text-center">
                            @if ($guardian->is_approved)
                                <flux:badge size="sm" variant="success">معتمد</flux:badge>
                            @else
                                <flux:badge size="sm" variant="warning">قيد الانتظار</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-center text-xs text-zinc-400">
                            {{ $guardian->created_at?->format('Y-m-d') }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center justify-end gap-2">
                                @if (!$guardian->is_approved)
                                    <flux:button size="sm" variant="primary"
                                        class="bg-emerald-600 hover:bg-emerald-700"
                                        wire:click="approve({{ $guardian->id }})">موافقة</flux:button>
                                @endif
                                <flux:button size="sm" variant="ghost" icon="pencil-square"
                                    wire:click="edit({{ $guardian->id }})" />
                                <flux:button size="sm" variant="ghost" icon="trash"
                                    class="text-red-500 hover:text-red-600"
                                    wire:confirm="هل أنت متأكد من حذف ولي الأمر؟"
                                    wire:click="delete({{ $guardian->id }})" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="guardian-modal" class="md:w-[500px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">تعديل بيانات ولي الأمر</flux:heading>
                <flux:subheading>تحديث البيانات الأساسية لولي الأمر.</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:input label="الاسم الكامل" wire:model="name" required />
                <flux:input label="البريد الإلكتروني" wire:model="email" type="email" required />
            </div>

            <div class="space-y-2">
                <flux:heading>تعيين الأبناء (الطلاب)</flux:heading>
                <div class="grid grid-cols-2 gap-2 max-h-48 overflow-y-auto p-2 border border-zinc-100 rounded-lg dark:border-zinc-800">
                    @foreach($studentsList as $student)
                        <div class="flex items-center gap-2">
                            <flux:checkbox wire:model="selectedStudents" :value="$student->id" :id="'student-'.$student->id" />
                            <flux:label :for="'student-'.$student->id" class="cursor-pointer">{{ $student->name }}</flux:label>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex gap-2 justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost" wire:click="cancel">إلغاء</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" class="bg-maroon hover:bg-burgundy dark:bg-red-secondary">
                    حفظ التعديلات</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
