<div class="space-y-6">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                <flux:icon icon="circle-stack" />
            </div>
            <div>
                <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">حلقات المرحلة</flux:heading>
                <flux:subheading>الحلقات الواقعة ضمن المراحل التي تشرف عليها</flux:subheading>
            </div>
        </div>
        @if($stages->isNotEmpty())
            <div class="flex items-center gap-2">
                <flux:dropdown>
                    <flux:button size="sm" icon="chart-bar" icon:trailing="chevron-down">تقرير المرحلة</flux:button>
                    <flux:menu>
                        @foreach($stages as $stage)
                            <flux:menu.item :href="route('supervisor.stages.report', $stage->id)" icon="presentation-chart-line">
                                {{ $stage->name }}
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
                <flux:button variant="primary" size="sm" icon="plus" wire:click="create">إضافة حلقة جديدة</flux:button>
            </div>
        @endif
    </div>

    <div class="flex flex-col md:flex-row gap-4 items-end">
        <div class="flex-1">
            <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="بحث عن حلقة..." />
        </div>
        <div class="w-full md:w-56">
            <flux:select wire:model.live="teacherFilter" placeholder="تصفية حسب المعلم">
                <flux:select.option value="all">الكل</flux:select.option>
                @foreach($teachersList as $teacher)
                    <flux:select.option :value="$teacher->id">{{ $teacher->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>اسم الحلقة</flux:table.column>
                <flux:table.column class="hidden md:table-cell">المرحلة</flux:table.column>
                <flux:table.column class="hidden md:table-cell">المعلمون</flux:table.column>
                <flux:table.column class="text-center">الطلاب</flux:table.column>
                <flux:table.column class="w-10"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($circles as $circle)
                    <flux:table.row :key="$circle->id">
                        <flux:table.cell class="font-bold text-zinc-900 dark:text-white">
                            <a href="{{ route('supervisor.circles.report', $circle->id) }}"
                                class="hover:text-emerald-600 dark:hover:text-emerald-400 hover:underline underline-offset-4">
                                {{ $circle->name }}
                            </a>
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">
                            <flux:badge size="sm" variant="neutral">{{ $circle->stage->name }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">
                            <div class="flex flex-wrap gap-1">
                                @forelse($circle->teachers as $teacher)
                                    <flux:badge size="sm" color="green">{{ $teacher->name }}</flux:badge>
                                @empty
                                    <span class="text-xs text-zinc-400">لا يوجد معلمين</span>
                                @endforelse
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="text-center">
                            <button type="button" wire:click="viewStudents({{ $circle->id }})" class="inline-flex">
                                <flux:badge size="sm" variant="neutral" class="cursor-pointer hover:bg-zinc-200 dark:hover:bg-zinc-700">{{ $circle->students_count }}</flux:badge>
                            </button>
                        </flux:table.cell>
                        <flux:table.cell class="first:ps-3" >
                            <div class="flex items-center gap-1">
                                <flux:button size="sm" variant="ghost" icon="chart-bar" :href="route('supervisor.circles.report', $circle->id)" title="تقرير الإنجاز" />
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $circle->id }})" />
                                <flux:button size="sm" variant="ghost" icon="arrows-pointing-in" title="دمج في حلقة أخرى"
                                    wire:click="openMerge({{ $circle->id }})" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center py-16">
                            <flux:text class="text-zinc-400">لا توجد حلقات ضمن صلاحياتك</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    {{-- Edit Circle Modal --}}
    <flux:modal name="circle-modal" class="md:w-[500px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingCircleId ? 'تعديل بيانات الحلقة' : 'إضافة حلقة جديدة' }}</flux:heading>
                <flux:subheading>يمكنك تحديد اسم الحلقة ووصفها وتعيين المعلمين لها.</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:select label="المرحلة التعليمية" wire:model="stage_id" required>
                    <flux:select.option value="" >اختر المرحلة</flux:select.option>
                    @foreach($stages as $stage)
                        <flux:select.option value="{{ $stage->id }}">{{ $stage->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input label="اسم الحلقة" wire:model="name" placeholder="مثال: حلقة ابن كثير" required />
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
                        <span class="text-xs text-zinc-400 col-span-2 text-center py-2">لا يوجد معلمون متاحون</span>
                    @endforelse
                </div>
            </div>

            <div class="flex gap-2 justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost" wire:click="cancel">إلغاء</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">حفظ التعديلات</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Circle Students Modal --}}
    <flux:modal name="circle-students-modal" class="md:w-[560px]">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">طلاب حلقة {{ $viewingCircleName }}</flux:heading>
                <flux:subheading>{{ $viewingCircleStudents ? $viewingCircleStudents->count() : 0 }} طالب</flux:subheading>
            </div>

            <div class="space-y-2 max-h-[420px] overflow-y-auto">
                @forelse(($viewingCircleStudents ?? []) as $student)
                    <div class="flex items-center justify-between gap-3 p-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/50">
                        <div class="flex items-center gap-3 min-w-0">
                            <flux:avatar size="sm" :name="$student['name']" />
                            <div class="min-w-0">
                                <div class="font-bold text-sm text-zinc-800 dark:text-zinc-100 truncate">{{ $student['name'] }}</div>
                                @if($student['status'] === 'active')
                                    <flux:badge size="sm" color="green">مشارك</flux:badge>
                                @elseif($student['status'] === 'registering')
                                    <flux:badge size="sm" color="amber">تحت التسجيل</flux:badge>
                                @elseif($student['status'] === 'suspended')
                                    <flux:badge size="sm" color="red">موقوف</flux:badge>
                                @else
                                    <flux:badge size="sm" variant="neutral">غادر الحلقات</flux:badge>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-4 text-xs text-zinc-500 dark:text-zinc-400 shrink-0">
                            <div class="text-center">
                                <div class="font-bold text-zinc-700 dark:text-zinc-200">{{ $student['memorization_percentage'] }}%</div>
                                <div>الحفظ</div>
                            </div>
                            <div class="text-center">
                                <div class="font-bold {{ $student['absences'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-700 dark:text-zinc-200' }}">{{ $student['absences'] }}</div>
                                <div>غياب</div>
                            </div>
                            <div class="text-center">
                                <div class="font-bold {{ $student['lateness'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-700 dark:text-zinc-200' }}">{{ $student['lateness'] }}</div>
                                <div>تأخر</div>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-400 text-center py-8">لا يوجد طلاب في هذه الحلقة</p>
                @endforelse
            </div>

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost">إغلاق</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
    <x-shared.circle-merge :circles="$mergeableCircles" :source="$mergeSource" :preview="$mergePreview" :merges="$recentMerges" />
</div>
