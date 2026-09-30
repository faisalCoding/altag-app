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

    <x-magic-link-selection-bar :count="count($selectedIds)" :outside="$selectedOutsideFilters"
        :text="$selectedMagicLinksText" noun="طالب" copied="نُسخت أسماء الطلاب وروابطهم" />

    <div
        class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <flux:table class="w-full">
            <flux:table.columns>
                <flux:table.column class="w-10">
                    <flux:checkbox wire:model.live="selectAll" />
                </flux:table.column>
                <flux:table.column>{{ __('الطالب') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('الحلقة') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('حالة الطالب') }}</flux:table.column>
                <flux:table.column class="w-10"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($students as $student)
                    <flux:table.row :key="$student->id"
                        class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50   s"
                        x-on:click="$flux.modal('student-modal').show(); $wire.edit({{ $student->id }})">
                        <flux:table.cell x-on:click.stop="">
                            <flux:checkbox wire:model.live="selectedIds" value="{{ $student->id }}" />
                        </flux:table.cell>
                        <flux:table.cell class="first:ps-3">
                            <div class="flex flex-col">
                                <span class="font-bold text-zinc-900 dark:text-white">{{ $student->name }}</span>
                                <div class="flex gap-2">
                                    <span class="text-xs text-zinc-500">{{ $student->email }}</span>
                                    @if ($student->guardian_id)
                                        <span class="text-xs text-zinc-400">| {{ $student->guardian->name }}</span>
                                    @endif
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">
                            @if ($student->circle)
                                <flux:badge size="sm" variant="neutral">{{ $student->circle->name }}</flux:badge>
                            @else
                                <span class="text-xs text-zinc-400">غير محدد</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell">
                            @if ($student->status === 'active')
                                <flux:badge size="sm" variant="success">مشارك</flux:badge>
                            @elseif ($student->status === 'registering')
                                <flux:badge size="sm" variant="warning">تحت التسجيل</flux:badge>
                            @elseif ($student->status === 'suspended')
                                <flux:badge size="sm" variant="danger">موقوف</flux:badge>
                            @else
                                <flux:badge size="sm" variant="neutral">غادر الحلقات</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="first:ps-3" >
                            <div class="flex items-center justify-end gap-2" @click.stop>
                                <flux:dropdown>
                                    <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" />
                                    <flux:menu>
                                        <flux:menu.item
                                            x-on:click="$flux.modal('student-modal').show(); $wire.edit({{ $student->id }})"
                                            icon="eye">{{ __('عرض وتعديل التفاصيل') }}</flux:menu.item>
                                        <flux:separator />
                                        <flux:menu.item wire:click="delete({{ $student->id }})"
                                            wire:confirm="هل أنت متأكد من حذف هذا الطالب؟" variant="danger" icon="trash">
                                            {{ __('حذف الطالب') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
            {{ $students->links() }}
        </div>
    </div>

    <!-- Student Details Modal -->
    <flux:modal name="student-modal" variant="flyout" class="md:w-[600px]">
        <div wire:loading wire:target="edit"
            class="w-full h-full flex flex-col items-center justify-center min-h-[300px] text-zinc-400">
            <flux:icon icon="arrow-path" class="size-8 animate-spin mb-4" />
            <p>{{ __('جاري تحميل بيانات الطالب...') }}</p>
        </div>

        <div wire:loading.remove wire:target="edit">
            @if ($viewingStudent)
                <div class="space-y-8">
                    <div>
                        <flux:heading size="xl">{{ __('ملف الطالب') }}</flux:heading>
                        <flux:subheading>{{ __('إدارة بيانات الطالب وتتبع حالته وخططه القرآنية') }}</flux:subheading>
                    </div>

                    <form wire:submit="save" class="space-y-4">
                        <flux:input wire:model="name" label="{{ __('الاسم الكامل') }}" required />
                        <flux:input wire:model="email" label="{{ __('البريد الإلكتروني') }}" type="email" required />

                        <div class="grid grid-cols-2 gap-4">
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

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <div class="text-sm font-medium text-zinc-800 dark:text-white mb-1.5">{{ __('حالة الطالب') }}</div>
                                @php
                                    $mStatusLabels = ['active' => 'مشارك', 'registering' => 'تحت التسجيل', 'suspended' => 'موقوف', 'left' => 'غادر الحلقات'];
                                    $mStatusColors = ['active' => 'green', 'registering' => 'blue', 'suspended' => 'amber', 'left' => 'red'];
                                @endphp
                                <div class="flex items-center gap-2">
                                    <flux:badge color="{{ $mStatusColors[$viewingStudent->status] ?? 'zinc' }}">
                                        {{ $mStatusLabels[$viewingStudent->status] ?? $viewingStudent->status }}
                                    </flux:badge>
                                    <flux:button type="button" size="sm" variant="filled" icon="adjustments-horizontal"
                                        wire:click="$dispatch('open-status-manager', { studentId: {{ $viewingStudent->id }} })">
                                        {{ __('إدارة الحالة') }}
                                    </flux:button>
                                </div>
                            </div>

                            <livewire:shared.hijri-datepicker wire:model="editJoinedAt"
                                label="{{ __('تاريخ الالتحاق') }}" />
                        </div>

                        <div class="flex justify-between pt-2">
                            <flux:button type="submit" variant="primary" size="sm" icon="check">
                                {{ __('حفظ التعديلات') }}
                            </flux:button>
                        </div>
                    </form>

                    <flux:separator />

                    <div class="space-y-4">
                        <flux:heading size="sm">{{ __('بيانات الدخول') }}</flux:heading>
                        <div class="p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl">
                            <div class="flex items-center justify-between mb-2">
                                <div class="font-medium text-sm">{{ __('رابط الدخول السحري') }}</div>
                                <flux:button size="sm" variant="ghost" icon="arrow-path"
                                    wire:click="resetToken({{ $viewingStudent->id }})"
                                    wire:confirm="هل أنت متأكد من إنشاء رابط جديد؟ سيتم إبطال الرابط القديم.">
                                    {{ $viewingStudent->access_token ? __('إعادة إنشاء') : __('إنشاء رابط') }}
                                </flux:button>
                            </div>
                            @if($viewingStudent->access_token)
                                <div class="flex items-center gap-2"
                                    x-data="{ copied: false, link: '{{ route('magic-link', ['token' => $viewingStudent->access_token]) }}' }">
                                    <flux:input readonly copyable class="w-full text-xs"
                                        :value="route('magic-link', ['token' => $viewingStudent->access_token])" />
                                </div>
                            @else
                                <div class="text-xs text-zinc-500">{{ __('لم يتم إنشاء رابط دخول لهذا الطالب بعد.') }}</div>
                            @endif
                        </div>
                    </div>

                    <flux:separator />

                    <!-- Guardian Info -->
                    <div class="space-y-3">
                        <flux:heading size="sm">{{ __('معلومات التواصل') }}</flux:heading>
                        <div
                            class="bg-zinc-50 dark:bg-zinc-800/50 p-4 rounded-xl border border-zinc-100 dark:border-zinc-800">
                            @if ($viewingStudent->guardian)
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 p-2 rounded-lg">
                                            <flux:icon icon="user" class="size-5" />
                                        </div>
                                        <div>
                                            <div class="font-medium text-sm">{{ $viewingStudent->guardian->name }}</div>
                                            <div class="text-xs text-zinc-500">
                                                {{ $viewingStudent->guardian->phone ?? 'لا يوجد رقم' }}</div>
                                        </div>
                                    </div>
                                    @if ($viewingStudent->guardian->phone)
                                        @php
                                            $phone = ltrim($viewingStudent->guardian->phone, '0');
                                            $phone = str_starts_with($phone, '966') ? $phone : '966' . $phone;
                                        @endphp
                                        <flux:button variant="ghost" size="sm" icon="chat-bubble-left-ellipsis"
                                            class="text-emerald-600 dark:text-emerald-400" href="https://wa.me/{{ $phone }}"
                                            target="_blank">
                                            {{ __('مراسلة') }}
                                        </flux:button>
                                    @endif
                                </div>
                            @else
                                <div class="text-center text-zinc-500 text-sm py-2">
                                    {{ __('لم يتم تعيين ولي أمر لهذا الطالب') }}
                                </div>
                            @endif
                        </div>
                    </div>

                    <flux:separator />

                    <!-- Quran Plans -->
                    <div>
                        <flux:heading size="sm" class="mb-3">
                            {{ __('الخطط القرآنية (' . $viewingStudent->plans->count() . ')') }}</flux:heading>
                        <div class="space-y-2 max-h-48 overflow-y-auto pr-2">
                            @forelse($viewingStudent->plans as $plan)
                                <div
                                    class="flex items-center justify-between p-3 border border-zinc-200 dark:border-zinc-700/50 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-800/50   s">
                                    <div class="flex flex-col">
                                        <span class="text-sm font-medium">
                                            @if ($plan->plan_type === 'hifz_review') {{ __('حفظ ومراجعة') }}
                                            @elseif($plan->plan_type === 'hifz') {{ __('حفظ') }}
                                            @else {{ __('مراجعة') }} @endif
                                        </span>
                                        <span class="text-xs text-zinc-500"><x-hijri-date :date="$plan->start_date" /> •
                                            {{ $plan->days_count }} يوم</span>
                                    </div>
                                    <flux:button as="a" href="{{ route('teacher.print-plan', $plan->id) }}" target="_blank"
                                        size="xs" variant="ghost" icon="eye"></flux:button>
                                </div>
                            @empty
                                <div class="text-sm text-zinc-500 text-center py-4">{{ __('ليس لديه خطط مسجلة.') }}</div>
                            @endforelse
                        </div>
                    </div>

                    <flux:separator />

                    <!-- Attendance Stats -->
                    <div>
                        <flux:heading size="sm" class="mb-3">{{ __('سجل الحضور والغياب الإجمالي') }}</flux:heading>
                        <div class="grid grid-cols-3 gap-3 text-center">
                            <div
                                class="p-3 bg-green-50 dark:bg-green-500/10 rounded-xl border border-green-100 dark:border-green-500/20">
                                <div class="text-2xl font-bold text-green-600 dark:text-green-500">
                                    {{ $stats['present'] ?? 0 }}</div>
                                <div class="text-xs text-green-600/70 dark:text-green-500/70 mt-1">{{ __('حضور') }}</div>
                            </div>
                            <div
                                class="p-3 bg-red-50 dark:bg-red-500/10 rounded-xl border border-red-100 dark:border-red-500/20">
                                <div class="text-2xl font-bold text-red-600 dark:text-red-500">{{ $stats['absent'] ?? 0 }}
                                </div>
                                <div class="text-xs text-red-600/70 dark:text-red-500/70 mt-1">{{ __('غياب') }}</div>
                            </div>
                            <div
                                class="p-3 bg-amber-50 dark:bg-amber-500/10 rounded-xl border border-amber-100 dark:border-amber-500/20">
                                <div class="text-2xl font-bold text-amber-600 dark:text-amber-500">{{ $stats['late'] ?? 0 }}
                                </div>
                                <div class="text-xs text-amber-600/70 dark:text-amber-500/70 mt-1">{{ __('تأخر') }}</div>
                            </div>
                        </div>
                    </div>

                    <flux:separator />

                    <livewire:manager.add-linked-role
                        source-guard="student"
                        :source-id="$viewingStudent->id"
                        :source-name="$viewingStudent->name"
                        :key="'add-linked-role-student-'.$viewingStudent->id" />
                </div>
            @endif
        </div>
    </flux:modal>

    <livewire:shared.student-status-manager />
</div>