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

    <!-- Global Permissions Card -->
    <flux:card class="border-blue-100 dark:border-blue-900/50 bg-blue-50/50 dark:bg-blue-950/20">
        <div class="flex flex-col sm:flex-row sm:items-center gap-4">
            <div class="flex items-start gap-3 flex-1">
                <div class="p-2 rounded-lg bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 mt-0.5 shrink-0">
                    <flux:icon icon="shield-check" class="size-5" />
                </div>
                <div>
                    <div class="font-semibold text-sm text-zinc-800 dark:text-zinc-200">الصلاحيات الافتراضية لجميع المعلمين</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">هذه الإعدادات تطبق على جميع المعلمين ما لم يكن لديهم استثناء خاص</div>
                </div>
            </div>
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4 sm:gap-6">
                <label class="flex items-center gap-2.5 cursor-pointer">
                    <flux:switch wire:model="globalPermissions.can_create_students" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">إضافة طلاب جدد</span>
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer">
                    <flux:switch wire:model="globalPermissions.can_manage_students" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">إضافة وإزالة الطلاب (غير مسجلين)</span>
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer">
                    <flux:switch wire:model="globalPermissions.can_change_student_status" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">تغيير حالة الطالب</span>
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer">
                    <flux:switch wire:model="globalPermissions.can_manage_hadith_paths" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">تسكين الطلاب في مسارات الأحاديث</span>
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer">
                    <flux:switch wire:model="globalPermissions.can_manage_ode_paths" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">تسكين الطلاب في مسارات المنظومات</span>
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer">
                    <flux:switch wire:model="globalPermissions.can_manage_gamification_tracks" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">تسكين الطلاب في مسارات التلعيب</span>
                </label>
                <flux:button wire:click="saveGlobalPermissions" size="sm" variant="primary" icon="check">
                    حفظ الإعدادات
                </flux:button>
            </div>
        </div>
    </flux:card>

    <div class="flex flex-col md:flex-row gap-4 items-end">
        <div class="w-full md:flex-1">
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

    <!-- Quick Create Card -->
    <flux:card>
        <form wire:submit="createQuickTeacher" class="flex flex-col md:flex-row items-start md:items-end gap-4">
            <div class="w-full md:w-1/3">
                <flux:input wire:model="quickName" label="{{ __('اسم المعلم') }}"
                    placeholder="{{ __('مثال: محمد أحمد') }}" required />
            </div>
            <div class="w-full md:w-1/4">
                <flux:input wire:model="quickPhone" label="{{ __('رقم الهاتف') }}" placeholder="{{ __('اختياري') }}" />
            </div>
            <div class="w-full md:w-1/3">
                <flux:select wire:model="quickCircleId" label="{{ __('اختر الحلقة') }}" required placeholder="اختر حلقة">
                    <flux:select.option value="">اختر حلقة</flux:select.option>
                    @foreach($circles as $circle)
                        <flux:select.option :value="$circle->id">{{ $circle->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="w-full md:w-auto pt-6 md:pt-0">
                <flux:button type="submit" variant="primary" icon="user-plus" class="w-full md:w-auto">{{ __('إنشاء سريع') }}
                </flux:button>
            </div>
        </form>
    </flux:card>

    <div
        class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <flux:table class="w-full">
            <flux:table.columns>
                <flux:table.column>{{ __('المعلم') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('الحلقات') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('الصلاحيات') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('حالة البيانات') }}</flux:table.column>
                <flux:table.column class="w-10"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($teachers as $teacher)
                    <flux:table.row :key="$teacher->id"
                        class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50   s"
                        x-on:click="$flux.modal('teacher-modal').show(); $wire.edit({{ $teacher->id }})">
                        <flux:table.cell class="first:ps-3" >
                            <div class="flex flex-col">
                                <span class="font-bold text-zinc-900 dark:text-white">{{ $teacher->name }}</span>
                                <div class="flex gap-2">
                                    <span class="text-xs text-zinc-500">{{ $teacher->email }}</span>
                                    @if ($teacher->phone)
                                        <span class="text-xs text-zinc-400">| {{ $teacher->phone }}</span>
                                    @endif
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">
                            <div class="flex flex-wrap gap-1">
                                @forelse($teacher->circles as $circle)
                                    <flux:badge size="sm" variant="neutral">{{ $circle->name }}</flux:badge>
                                @empty
                                    <span class="text-xs text-zinc-400">لا يوجد حلقات</span>
                                @endforelse
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">
                            <div class="flex flex-wrap gap-1">
                                @php $perms = $teacher->effectivePermissions(); @endphp
                                @if(!empty($perms['can_manage_students']))
                                    <flux:badge size="sm" color="blue" icon="users">إدارة الطلاب</flux:badge>
                                @endif
                                @if(!empty($perms['can_change_student_status']))
                                    <flux:badge size="sm" color="violet" icon="arrow-path">تغيير الحالة</flux:badge>
                                @endif
                                @if(!empty($perms['can_manage_hadith_paths']))
                                    <flux:badge size="sm" color="emerald" icon="book-open">مسارات الأحاديث</flux:badge>
                                @endif
                                @if(!empty($perms['can_manage_ode_paths']))
                                    <flux:badge size="sm" color="teal" icon="musical-note">مسارات المنظومات</flux:badge>
                                @endif
                                @if(!empty($perms['can_manage_gamification_tracks']))
                                    <flux:badge size="sm" color="amber" icon="trophy">مسارات التلعيب</flux:badge>
                                @endif
                                @if(empty($perms['can_manage_students']) && empty($perms['can_change_student_status']) && empty($perms['can_manage_hadith_paths']) && empty($perms['can_manage_ode_paths']) && empty($perms['can_manage_gamification_tracks']))
                                    <span class="text-xs text-zinc-400">لا صلاحيات إضافية</span>
                                @endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">
                            @if($teacher->is_data_completed)
                                <flux:badge color="green" size="sm" icon="check-circle">{{ __('مكتملة') }}</flux:badge>
                            @else
                                <flux:badge color="amber" size="sm" icon="clock">{{ __('غير مكتملة') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="first:ps-3" >
                            <div class="flex items-center justify-end gap-2" @click.stop>
                                @if (!$teacher->is_approved)
                                    <flux:button size="sm" variant="primary" class="bg-emerald-600 hover:bg-emerald-700"
                                        wire:click="approve({{ $teacher->id }})">موافقة</flux:button>
                                @endif
                                <flux:dropdown>
                                    <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" />
                                    <flux:menu>
                                        <flux:menu.item
                                            x-on:click="$flux.modal('teacher-modal').show(); $wire.edit({{ $teacher->id }})"
                                            icon="eye">{{ __('عرض وتعديل التفاصيل') }}</flux:menu.item>
                                        <flux:separator />
                                        <flux:menu.item wire:click="delete({{ $teacher->id }})"
                                            wire:confirm="هل أنت متأكد من حذف هذا المعلم؟" variant="danger" icon="trash">
                                            {{ __('حذف المعلم') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <!-- Teacher Details Modal -->
    <flux:modal name="teacher-modal" variant="flyout" class="md:w-[500px]">
        <div wire:loading wire:target="edit"
            class="w-full h-full flex flex-col items-center justify-center min-h-[300px] text-zinc-400">
            <flux:icon icon="arrow-path" class="size-8 animate-spin mb-4" />
            <p>{{ __('جاري تحميل بيانات المعلم...') }}</p>
        </div>

        <div wire:loading.remove wire:target="edit">
            @if ($viewingTeacher)
                <div class="space-y-8">
                    <div>
                        <flux:heading size="xl">{{ __('ملف المعلم') }}</flux:heading>
                        <flux:subheading>{{ __('عرض وتعديل بيانات المعلم الأساسية') }}</flux:subheading>
                    </div>

                    <form wire:submit="save" class="space-y-4">
                        <flux:input label="الاسم" wire:model="name" required />
                        <flux:input label="البريد الإلكتروني" wire:model="email" type="email" required />
                        <div class="flex items-end gap-2">
                            <flux:input label="رقم الجوال" wire:model="phone" placeholder="9665xxxxxxx" dir="ltr"
                                class="text-right flex-1" />
                            @if ($phone)
                                @php
                                    $whatsappPhone = preg_replace('/[^0-9]/', '', $phone);
                                    $whatsappPhone = ltrim($whatsappPhone, '0');
                                    $whatsappPhone = str_starts_with($whatsappPhone, '966') ? $whatsappPhone : '966' . $whatsappPhone;
                                @endphp
                                <flux:button variant="ghost" icon="chat-bubble-left-ellipsis"
                                    class="text-emerald-600 dark:text-emerald-400" href="https://wa.me/{{ $whatsappPhone }}"
                                    target="_blank" title="{{ __('تواصل عبر واتساب') }}">
                                </flux:button>
                            @endif
                        </div>

                        <div class="space-y-2">
                            <flux:heading size="sm">{{ __('تعيين الحلقات') }}</flux:heading>
                            <div
                                class="flex flex-col gap-2 max-h-48 overflow-y-auto p-3 border border-zinc-100 rounded-xl dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50">
                                @foreach ($circles as $circle)
                                    <div class="flex items-center gap-2">
                                        <flux:checkbox wire:model="selectedCircles" :value="$circle->id"
                                            :id="'circle-'.$circle->id" />
                                        <flux:label :for="'circle-'.$circle->id" class="cursor-pointer">{{ $circle->name }}
                                            <span class="text-xs text-zinc-400">({{ $circle->stage->name }})</span></flux:label>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <flux:heading size="sm">{{ __('صلاحيات المعلم') }}</flux:heading>
                                @if($useCustomPermissions)
                                    <flux:badge size="sm" color="amber" icon="exclamation-triangle">استثناء خاص</flux:badge>
                                @else
                                    <flux:badge size="sm" color="blue" icon="shield-check">يرث الإعداد العام</flux:badge>
                                @endif
                            </div>

                            {{-- Override toggle --}}
                            <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-100 dark:border-zinc-800">
                                <flux:switch wire:model.live="useCustomPermissions" />
                                <div>
                                    <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">تطبيق استثناء خاص لهذا المعلم</div>
                                    <div class="text-xs text-zinc-400">عند التفعيل يمكنك تخصيص صلاحيات مختلفة عن الإعداد العام</div>
                                </div>
                            </label>

                            {{-- Custom permissions (only when override is enabled) --}}
                            @if($useCustomPermissions)
                            <div class="flex flex-col gap-3 p-3 border border-amber-200 dark:border-amber-900/50 rounded-xl bg-amber-50/50 dark:bg-amber-950/20">
                                <label class="flex items-center justify-between gap-3 cursor-pointer group">
                                    <div>
                                        <div class="text-sm font-medium text-zinc-800 dark:text-zinc-200">إضافة طلاب جدد</div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">إنشاء حساب طالب جديد في النظام</div>
                                    </div>
                                    <flux:switch wire:model="permissions.can_create_students" />
                                </label>
                                <flux:separator />
                                <label class="flex items-center justify-between gap-3 cursor-pointer group">
                                    <div>
                                        <div class="text-sm font-medium text-zinc-800 dark:text-zinc-200">إضافة وإزالة الطلاب (غير مسجلين)</div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">إضافة طلاب للحلقة، إزالتهم، وإدارة الطلاب غير المرتبطين</div>
                                    </div>
                                    <flux:switch wire:model="permissions.can_manage_students" />
                                </label>
                                <flux:separator />
                                <label class="flex items-center justify-between gap-3 cursor-pointer group">
                                    <div>
                                        <div class="text-sm font-medium text-zinc-800 dark:text-zinc-200">تغيير حالة الطالب</div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">تغيير حالة الطالب (نشط، موقوف، متخرج...)</div>
                                    </div>
                                    <flux:switch wire:model="permissions.can_change_student_status" />
                                </label>
                                <flux:separator />
                                <label class="flex items-center justify-between gap-3 cursor-pointer group">
                                    <div>
                                        <div class="text-sm font-medium text-zinc-800 dark:text-zinc-200">تسكين الطلاب في مسارات الأحاديث</div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">تسكين الطالب في مسار حديث أو تغييره من صفحة التسميع</div>
                                    </div>
                                    <flux:switch wire:model="permissions.can_manage_hadith_paths" />
                                </label>
                                <flux:separator />
                                <label class="flex items-center justify-between gap-3 cursor-pointer group">
                                    <div>
                                        <div class="text-sm font-medium text-zinc-800 dark:text-zinc-200">تسكين الطلاب في مسارات المنظومات</div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">تسكين الطالب في مسار منظومة أو تغييره من صفحة التسميع</div>
                                    </div>
                                    <flux:switch wire:model="permissions.can_manage_ode_paths" />
                                </label>
                                <flux:separator />
                                <label class="flex items-center justify-between gap-3 cursor-pointer group">
                                    <div>
                                        <div class="text-sm font-medium text-zinc-800 dark:text-zinc-200">تسكين الطلاب في مسارات التلعيب</div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">إضافة الطالب إلى أحد مسارات مسابقة التلعيب من صفحة التسميع</div>
                                    </div>
                                    <flux:switch wire:model="permissions.can_manage_gamification_tracks" />
                                </label>
                            </div>
                            @else
                            {{-- Show what the teacher inherits --}}
                            <div class="flex flex-col gap-2 p-3 border border-zinc-100 dark:border-zinc-800 rounded-xl bg-zinc-50 dark:bg-zinc-800/30 text-xs text-zinc-500">
                                <div class="flex items-center justify-between">
                                    <span>إضافة طلاب جدد</span>
                                    @if($globalPermissions['can_create_students'] ?? true)
                                        <flux:badge size="sm" color="green">مسموح (من الإعداد العام)</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="red">غير مسموح (من الإعداد العام)</flux:badge>
                                    @endif
                                </div>
                                <div class="flex items-center justify-between">
                                    <span>إضافة وإزالة الطلاب (غير مسجلين)</span>
                                    @if($globalPermissions['can_manage_students'])
                                        <flux:badge size="sm" color="green">مسموح (من الإعداد العام)</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="red">غير مسموح (من الإعداد العام)</flux:badge>
                                    @endif
                                </div>
                                <div class="flex items-center justify-between">
                                    <span>تغيير حالة الطالب</span>
                                    @if($globalPermissions['can_change_student_status'])
                                        <flux:badge size="sm" color="green">مسموح (من الإعداد العام)</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="red">غير مسموح (من الإعداد العام)</flux:badge>
                                    @endif
                                </div>
                                <div class="flex items-center justify-between">
                                    <span>تسكين الطلاب في مسارات الأحاديث</span>
                                    @if($globalPermissions['can_manage_hadith_paths'] ?? true)
                                        <flux:badge size="sm" color="green">مسموح (من الإعداد العام)</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="red">غير مسموح (من الإعداد العام)</flux:badge>
                                    @endif
                                </div>
                                <div class="flex items-center justify-between">
                                    <span>تسكين الطلاب في مسارات المنظومات</span>
                                    @if($globalPermissions['can_manage_ode_paths'] ?? true)
                                        <flux:badge size="sm" color="green">مسموح (من الإعداد العام)</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="red">غير مسموح (من الإعداد العام)</flux:badge>
                                    @endif
                                </div>
                                <div class="flex items-center justify-between">
                                    <span>تسكين الطلاب في مسارات التلعيب</span>
                                    @if($globalPermissions['can_manage_gamification_tracks'] ?? true)
                                        <flux:badge size="sm" color="green">مسموح (من الإعداد العام)</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="red">غير مسموح (من الإعداد العام)</flux:badge>
                                    @endif
                                </div>
                            </div>
                            @endif
                        </div>

                        <div class="flex justify-between pt-2">
                            <flux:button type="submit" variant="primary" size="sm" icon="check">
                                {{ __('حفظ التعديلات') }}
                            </flux:button>
                        </div>
                    </form>

                    <flux:separator />

                    <!-- Additional Info -->
                    <div class="space-y-4">
                        <flux:heading size="sm">{{ __('معلومات إضافية') }}</flux:heading>
                        <div class="flex items-center justify-between p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl">
                            <div>
                                <div class="font-medium text-sm">{{ __('حالة الاعتماد') }}</div>
                                <div class="text-xs mt-1">
                                    @if ($viewingTeacher->is_approved)
                                        <span class="text-green-600 dark:text-green-400">{{ __('معتمد') }}</span>
                                    @else
                                        <span class="text-amber-600 dark:text-amber-400">{{ __('قيد الانتظار') }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="font-medium text-sm">{{ __('تاريخ الإضافة') }}</div>
                                <div class="text-xs text-zinc-500 mt-1"><x-hijri-date :date="$viewingTeacher->created_at" />
                                </div>
                            </div>
                        </div>

                        <div class="p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl">
                            <div class="flex items-center justify-between mb-2">
                                <div class="font-medium text-sm">{{ __('رابط الدخول السحري') }}</div>
                                <flux:button size="sm" variant="ghost" icon="arrow-path"
                                    wire:click="resetToken({{ $viewingTeacher->id }})"
                                    wire:confirm="هل أنت متأكد من إنشاء رابط جديد؟ سيتم إبطال الرابط القديم.">
                                    {{ $viewingTeacher->access_token ? __('إعادة إنشاء') : __('إنشاء رابط') }}
                                </flux:button>
                            </div>
                            @if($viewingTeacher->access_token)
                                <div class="flex items-center gap-2"
                                    x-data="{ copied: false, link: '{{ route('teacher.magic-link', ['token' => $viewingTeacher->access_token]) }}' }">
                                    <flux:input readonly copyable class="w-full text-xs"
                                        :value="route('teacher.magic-link', ['token' => $viewingTeacher->access_token])" />
                                </div>
                            @else
                                <div class="text-xs text-zinc-500">{{ __('لم يتم إنشاء رابط دخول لهذا المعلم بعد.') }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </flux:modal>
</div>