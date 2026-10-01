<div class="space-y-6">
    <div class="flex items-center gap-3">
        <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
            <flux:icon icon="academic-cap" />
        </div>
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">طلاب المرحلة</flux:heading>
            <flux:subheading>عرض وإدارة الطلاب الواقعين ضمن حلقات مرحلتك</flux:subheading>
        </div>
    </div>

    <div class="flex flex-col md:flex-row gap-4 items-end">
        <div class="w-full md:flex-1">
            <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="بحث عن طالب..." />
        </div>
        <div class="w-full md:w-36">
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
    </div>

    @if (count($selectedIds) > 0)
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 p-4 bg-indigo-50 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900/50 rounded-2xl">
            <div class="flex items-center gap-2">
                <span class="flex h-2 w-2 rounded-full bg-indigo-600 dark:bg-indigo-400"></span>
                <div class="flex flex-col">
                    <span class="text-sm font-medium text-indigo-900 dark:text-indigo-200">
                        تم تحديد {{ count($selectedIds) }} طالب
                    </span>
                    @if ($selectedOutsideFilters > 0)
                        <span class="text-xs text-amber-700 dark:text-amber-400">
                            منهم {{ $selectedOutsideFilters }} خارج نتائج البحث الحالية
                        </span>
                    @endif
                </div>
                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="resetSelection">
                    إلغاء التحديد
                </flux:button>
            </div>
            <div class="flex flex-wrap gap-2">
                <flux:button size="sm" variant="filled" x-on:click="$flux.modal('bulk-circle-modal').show()">
                    تغيير الحلقة
                </flux:button>
                <flux:button size="sm" variant="filled" x-on:click="$flux.modal('bulk-joined-at-modal').show()">
                    تغيير تاريخ الالتحاق
                </flux:button>
                <flux:button size="sm" variant="filled" x-on:click="$flux.modal('bulk-status-modal').show()">
                    تغيير حالة الطالب
                </flux:button>
                <flux:button size="sm" variant="filled" icon="clipboard-document-list"
                    x-on:click="navigator.clipboard.writeText(@js($selectedMagicLinksText)); $dispatch('toast', { message: 'تم نسخ أسماء الطلاب وروابطهم السحرية', variant: 'success' })">
                    نسخ الأسماء والروابط
                </flux:button>
                <flux:button size="sm" variant="filled" wire:click="applyBulkResetMagicLinks" wire:confirm="هل أنت متأكد من إعادة تعيين الروابط السحرية للطلاب المحددين؟">
                    تحديث الروابط السحرية
                </flux:button>
                <flux:button size="sm" color="red" variant="filled" x-on:click="$flux.modal('bulk-delete-modal').show()">
                    حذف الحسابات
                </flux:button>
            </div>
        </div>
    @endif

    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
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
                @forelse ($students as $student)
                    <flux:table.row :key="$student->id"
                        class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                        x-on:click="$flux.modal('student-modal').show(); $wire.edit({{ $student->id }})">
                        <flux:table.cell x-on:click.stop="">
                            <flux:checkbox wire:model.live="selectedIds" value="{{ $student->id }}" />
                        </flux:table.cell>
                        <flux:table.cell class="first:ps-3" >
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
                            <flux:badge size="sm" :color="\App\Support\StudentStatus::color($student->status)">{{ \App\Support\StudentStatus::label($student->status) }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="first:ps-3" >
                            <div class="flex items-center justify-end gap-2" @click.stop>
                                @if(!$student->is_approved)
                                    <flux:button size="xs" variant="primary" wire:click.stop="approve({{ $student->id }})">
                                        موافقة
                                    </flux:button>
                                @endif
                                <flux:button size="sm" variant="ghost" icon="eye"
                                    x-on:click.stop="$flux.modal('student-modal').show(); $wire.edit({{ $student->id }})" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center py-16">
                            <flux:text class="text-zinc-400">لا يوجد طلاب ضمن حلقاتك</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
            {{ $students->links() }}
        </div>
    </div>

    {{-- Student Details Flyout --}}
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
                                <flux:select.option value="">بدون حلقة</flux:select.option>
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
                                    $sStatusLabels = ['active' => 'مشارك', 'registering' => 'تحت التسجيل', 'suspended' => 'موقوف', 'left' => 'غادر الحلقات'];
                                    $sStatusColors = ['active' => 'green', 'registering' => 'blue', 'suspended' => 'amber', 'left' => 'red'];
                                @endphp
                                <div class="flex items-center gap-2">
                                    <flux:badge color="{{ $sStatusColors[$viewingStudent->status] ?? 'zinc' }}">
                                        {{ $sStatusLabels[$viewingStudent->status] ?? $viewingStudent->status }}
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

                    {{-- Magic Link --}}
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
                                <flux:input readonly copyable class="w-full text-xs"
                                    :value="route('magic-link', ['token' => $viewingStudent->access_token])" />
                            @else
                                <div class="text-xs text-zinc-500">{{ __('لم يتم إنشاء رابط دخول لهذا الطالب بعد.') }}</div>
                            @endif
                        </div>
                    </div>

                    <flux:separator />

                    {{-- Guardian --}}
                    <div class="space-y-3">
                        <flux:heading size="sm">{{ __('معلومات التواصل') }}</flux:heading>
                        <div class="bg-zinc-50 dark:bg-zinc-800/50 p-4 rounded-xl border border-zinc-100 dark:border-zinc-800">
                            @if ($viewingStudent->guardian)
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 p-2 rounded-lg">
                                            <flux:icon icon="user" class="size-5" />
                                        </div>
                                        <div>
                                            <div class="font-medium text-sm">{{ $viewingStudent->guardian->name }}</div>
                                            <div class="text-xs text-zinc-500">{{ $viewingStudent->guardian->phone ?? 'لا يوجد رقم' }}</div>
                                        </div>
                                    </div>
                                    @if ($viewingStudent->guardian->phone)
                                        @php
                                            $phone = ltrim($viewingStudent->guardian->phone, '0');
                                            $phone = str_starts_with($phone, '966') ? $phone : '966'.$phone;
                                        @endphp
                                        <flux:button variant="ghost" size="sm" icon="chat-bubble-left-ellipsis"
                                            class="text-emerald-600 dark:text-emerald-400"
                                            href="https://wa.me/{{ $phone }}" target="_blank">
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

                    {{-- Plans --}}
                    <div>
                        <flux:heading size="sm" class="mb-3">
                            {{ __('الخطط القرآنية ('.$viewingStudent->plans->count().')') }}</flux:heading>
                        <div class="space-y-2 max-h-48 overflow-y-auto pr-2">
                            @forelse($viewingStudent->plans as $plan)
                                <div class="flex items-center justify-between p-3 border border-zinc-200 dark:border-zinc-700/50 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
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

                    {{-- Ode Plans --}}
                    <div>
                        <div class="flex justify-between items-center mb-3">
                            <flux:heading size="sm">
                                {{ __('خطط المنظومات ('.$viewingStudent->odePlans->count().')') }}
                            </flux:heading>
                            <flux:button size="xs" variant="filled" as="a" href="{{ route('supervisor.odes.paths') }}">
                                إدارة المسارات والتسكين
                            </flux:button>
                        </div>
                        <div class="space-y-2 max-h-48 overflow-y-auto pr-2">
                            @forelse($viewingStudent->odePlans as $plan)
                                <div class="flex items-center justify-between p-3 border border-zinc-200 dark:border-zinc-700/50 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                    <div class="flex flex-col">
                                        <span class="text-sm font-medium">
                                            {{ $plan->path->ode->name ?? '—' }}
                                        </span>
                                        <span class="text-xs text-zinc-500">
                                            <x-hijri-date :date="$plan->start_date" /> •
                                            {{ $plan->path->days->count() ?? 0 }} يوم •
                                            @if($plan->status === 'active')
                                                <span class="text-green-600 font-semibold">نشطة</span>
                                            @else
                                                <span class="text-zinc-400 font-semibold">مكتملة</span>
                                            @endif
                                        </span>
                                    </div>
                                </div>
                            @empty
                                <div class="text-sm text-zinc-500 text-center py-4">{{ __('ليس لديه خطط منظومات مسجلة.') }}</div>
                            @endforelse
                        </div>
                    </div>

                    <flux:separator />

                    {{-- Attendance Stats --}}
                    <div>
                        <flux:heading size="sm" class="mb-3">{{ __('سجل الحضور والغياب الإجمالي') }}</flux:heading>
                        <div class="grid grid-cols-3 gap-3 text-center">
                            <div class="p-3 bg-green-50 dark:bg-green-500/10 rounded-xl border border-green-100 dark:border-green-500/20">
                                <div class="text-2xl font-bold text-green-600 dark:text-green-500">{{ $stats['present'] ?? 0 }}</div>
                                <div class="text-xs text-green-600/70 dark:text-green-500/70 mt-1">{{ __('حضور') }}</div>
                            </div>
                            <div class="p-3 bg-red-50 dark:bg-red-500/10 rounded-xl border border-red-100 dark:border-red-500/20">
                                <div class="text-2xl font-bold text-red-600 dark:text-red-500">{{ $stats['absent'] ?? 0 }}</div>
                                <div class="text-xs text-red-600/70 dark:text-red-500/70 mt-1">{{ __('غياب') }}</div>
                            </div>
                            <div class="p-3 bg-amber-50 dark:bg-amber-500/10 rounded-xl border border-amber-100 dark:border-amber-500/20">
                                <div class="text-2xl font-bold text-amber-600 dark:text-amber-500">{{ $stats['late'] ?? 0 }}</div>
                                <div class="text-xs text-amber-600/70 dark:text-amber-500/70 mt-1">{{ __('تأخر') }}</div>
                            </div>
                        </div>
                    </div>

                </div>
            @endif
    </flux:modal>

    <livewire:shared.student-status-manager />

    {{-- Bulk Change Circle Modal --}}
    <flux:modal name="bulk-circle-modal" class="md:w-[450px]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">تغيير الحلقة للمحددين</flux:heading>
                <flux:subheading>سيتم نقل الطلاب المحددين إلى الحلقة الجديدة</flux:subheading>
            </div>
            
            <flux:select label="الحلقة الدراسية الجديدة" wire:model="bulkCircleId" placeholder="اختر الحلقة...">
                <flux:select.option value="">بدون حلقة</flux:select.option>
                @foreach ($circles as $circle)
                    <flux:select.option :value="$circle->id">{{ $circle->name }} ({{ $circle->stage->name }})</flux:select.option>
                @endforeach
            </flux:select>
            
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" x-on:click="$flux.modal('bulk-circle-modal').close()">إلغاء</flux:button>
                <flux:button variant="primary" wire:click="applyBulkCircle">تطبيق</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Bulk Change Joined At Modal --}}
    <flux:modal name="bulk-joined-at-modal" class="md:w-[450px]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">تغيير تاريخ الالتحاق للمحددين</flux:heading>
                <flux:subheading>تحديث تاريخ الالتحاق لجميع الطلاب المحددين</flux:subheading>
            </div>
            
            <livewire:shared.hijri-datepicker wire:model="bulkJoinedAt" label="{{ __('تاريخ الالتحاق الجديد') }}" />
            
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" x-on:click="$flux.modal('bulk-joined-at-modal').close()">إلغاء</flux:button>
                <flux:button variant="primary" wire:click="applyBulkJoinedAt">تطبيق</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Bulk Change Status Modal --}}
    <flux:modal name="bulk-status-modal" class="md:w-[450px]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">تغيير حالة الطلاب المحددين</flux:heading>
                <flux:subheading>تحديث حالة جميع الطلاب المحددين</flux:subheading>
            </div>
            
            <flux:select wire:model="bulkStatus" label="الحالة الجديدة">
                <flux:select.option value="active">مشارك</flux:select.option>
                <flux:select.option value="registering">تحت التسجيل</flux:select.option>
                <flux:select.option value="suspended">موقوف</flux:select.option>
                <flux:select.option value="left">غادر الحلقات</flux:select.option>
            </flux:select>

            <livewire:shared.hijri-datepicker wire:model="bulkStatusDate"
                label="{{ __('تاريخ سريان الحالة (افتراضياً اليوم)') }}" />

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" x-on:click="$flux.modal('bulk-status-modal').close()">إلغاء</flux:button>
                <flux:button variant="primary" wire:click="applyBulkStatus">تطبيق</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Bulk Delete Confirmation Modal --}}
    <flux:modal name="bulk-delete-modal" class="md:w-[450px]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg" class="text-red-600 dark:text-red-400">حذف الحسابات المحددة</flux:heading>
                <flux:subheading class="text-zinc-600 dark:text-zinc-400">
                    أنت على وشك حذف حسابات الطلاب المحددين بشكل نهائي. هذا الإجراء سيؤدي إلى حذف جميع البيانات والخطط وسجلات الحضور التابعة لهم ولا يمكن التراجع عنه.
                </flux:subheading>
            </div>
            
            <div class="p-3 bg-red-50 dark:bg-red-950/20 text-red-700 dark:text-red-300 rounded-xl text-xs space-y-1">
                <p class="font-bold">تنبيه:</p>
                <p>سيتم حذف بيانات الحضور، والخطط القرآنية، وخطط المنظومات، وخطط الحديث، وجميع الحركات ونقاط التحفيز التابعة للطلاب المحددين.</p>
            </div>
            
            <flux:input 
                wire:model.live="deleteConfirmationInput" 
                label="لتأكيد الحذف، يرجى كتابة عبارة 'تأكيد الحذف' أدناه:" 
                placeholder="تأكيد الحذف"
            />
            
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" x-on:click="$flux.modal('bulk-delete-modal').close()">إلغاء</flux:button>
                <flux:button 
                    color="red" 
                    variant="filled" 
                    wire:click="confirmBulkDelete" 
                    :disabled="$deleteConfirmationInput !== 'تأكيد الحذف'"
                >
                    تأكيد الحذف النهائي
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
