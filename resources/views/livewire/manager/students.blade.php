<div class="space-y-6">
    <div class="flex items-center gap-3">
        <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
            <flux:icon icon="academic-cap" />
        </div>
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">إدارة الطلاب</flux:heading>
            <flux:subheading>إدارة شؤون الطلاب وتوزيعهم على الحلقات</flux:subheading>
        </div>
    </div>

    <div class="flex flex-col md:flex-row gap-4 items-end">
        <div class="flex-1">
            <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="بحث عن طالب..." />
        </div>
        <div class="w-full md:w-32">
            <flux:select wire:model.live="statusFilter" placeholder="الحالة">
                <flux:select.option value="all">الكل</flux:select.option>
                <flux:select.option value="pending">في انتظار الموافقة</flux:select.option>
                <flux:select.option value="approved">موافق عليه</flux:select.option>
            </flux:select>
        </div>
        <div class="w-full md:w-48">
            <flux:select wire:model.live="circleFilter" placeholder="تصفية حسب الحلقة">
                <flux:select.option value="">كل الحلقات</flux:select.option>
                @foreach ($circles as $circle)
                    <flux:select.option :value="$circle->id">{{ $circle->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-full md:w-48">
            <flux:select wire:model.live="guardianFilter" placeholder="تصفية حسب ولي الأمر">
                <flux:select.option value="all">كل أولياء الأمور</flux:select.option>
                @foreach ($guardiansList as $guardian)
                    <flux:select.option :value="$guardian->id">{{ $guardian->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <div
        class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <flux:table class=" justify-center items-center w-full !min-w-full">
            <flux:table.columns>
                <flux:table.column class="text-right">الطالب</flux:table.column>
                <flux:table.column class="text-right">الحلقة</flux:table.column>
                <flux:table.column class="text-right">ولي الأمر</flux:table.column>
                <flux:table.column class="text-center">الحالة</flux:table.column>
                <flux:table.column class="text-center">تاريخ الإضافة</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($students as $student)
                    <flux:table.row :key="$student->id">
                        <flux:table.cell>
                            <div class="flex flex-col text-right">
                                <span class="font-bold text-zinc-900 dark:text-white">{{ $student->name }}</span>
                                <span class="text-xs text-zinc-500">{{ $student->email }}</span>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($student->circle)
                                <flux:badge size="sm" variant="neutral">{{ $student->circle->name }}</flux:badge>
                                <span
                                    class="text-xs text-zinc-400 block mt-1">{{ $student->circle->stage->name }}</span>
                            @else
                                <span class="text-xs text-zinc-400 italic">بانتظار التوزيع</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($student->guardian)
                                <flux:badge size="sm" variant="success">{{ $student->guardian->name }}</flux:badge>
                            @else
                                <span class="text-xs text-zinc-400 italic">بدون ولي أمر</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-center">
                            @if ($student->is_approved)
                                <flux:badge size="sm" variant="success">معتمد</flux:badge>
                            @else
                                <flux:badge size="sm" variant="warning">قيد الانتظار</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-center text-xs text-zinc-400">
                            {{ $student->created_at?->format('Y-m-d') }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center justify-end gap-2">
                                @if (!$student->is_approved)
                                    <flux:button size="sm" variant="primary"
                                        class="bg-emerald-600 hover:bg-emerald-700"
                                        wire:click="approve({{ $student->id }})">موافقة</flux:button>
                                @endif
                                <flux:button size="sm" variant="ghost" icon="pencil-square"
                                    wire:click="edit({{ $student->id }})" />
                                <flux:button size="sm" variant="ghost" icon="trash"
                                    class="text-red-500 hover:text-red-600"
                                    wire:confirm="هل أنت متأكد من حذف هذا الطالب؟"
                                    wire:click="delete({{ $student->id }})" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="student-modal" class="md:w-[500px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">تعديل بيانات الطالب</flux:heading>
                <flux:subheading>تحديث البيانات الأساسية وتعيين الحلقة الدراسية.</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:input label="الاسم الكامل" wire:model="name" required />
                <flux:input label="البريد الإلكتروني" wire:model="email" type="email" required />

                <flux:select label="الحلقة الدراسية" wire:model="circle_id" placeholder="اختر الحلقة...">
                    <flux:select.option value="">بدون حلقة (قيد الانتظار)</flux:select.option>
                    @foreach ($circles as $circle)
                        <flux:select.option :value="$circle->id">{{ $circle->name }} ({{ $circle->stage->name }})
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select label="ولي الأمر" wire:model="guardian_id" placeholder="اختر ولي الأمر...">
                    <flux:select.option value="">بدون ولي أمر</flux:select.option>
                    @foreach ($guardiansList as $guardian)
                        <flux:select.option :value="$guardian->id">{{ $guardian->name }}</flux:select.option>
                    @endforeach
                </flux:select>
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
