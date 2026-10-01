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
            </flux:select>
        </div>
    </div>

    <!-- Quick Create Card -->
    <flux:card>
        <form wire:submit="createQuickTeacher" class="flex flex-col md:flex-row items-end gap-4">
            <div class="w-full md:w-2/5">
                <flux:input wire:model="quickName" label="{{ __('اسم المعلم') }}"
                    placeholder="{{ __('مثال: محمد أحمد') }}" required />
            </div>
            <div class="w-full md:w-2/5">
                <flux:input wire:model="quickPhone" label="{{ __('رقم الهاتف') }}" placeholder="{{ __('اختياري') }}" />
            </div>
            <flux:button type="submit" variant="primary" icon="user-plus" class="min-w-fit">{{ __('إنشاء سريع') }}
            </flux:button>
        </form>
    </flux:card>

    <x-magic-link-selection-bar :count="count($selectedIds)" :outside="$selectedOutsideFilters"
        :text="$selectedMagicLinksText" noun="معلم" copied="نُسخت أسماء المعلمين وروابطهم" />

    <div
        class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <flux:table class="w-full">
            <flux:table.columns>
                <flux:table.column class="w-10">
                    <flux:checkbox wire:model.live="selectAll" />
                </flux:table.column>
                <flux:table.column>{{ __('المعلم') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('الحلقات') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('حالة البيانات') }}</flux:table.column>
                <flux:table.column class="w-10"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($teachers as $teacher)
                    <flux:table.row :key="$teacher->id"
                        class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50   s"
                        x-on:click="$flux.modal('teacher-modal').show(); $wire.edit({{ $teacher->id }})">
                        <flux:table.cell x-on:click.stop="">
                            <flux:checkbox wire:model.live="selectedIds" value="{{ $teacher->id }}" />
                        </flux:table.cell>
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

                        <div class="flex justify-between pt-2">
                            <flux:button type="submit" variant="primary" size="sm" icon="check">
                                {{ __('حفظ التعديلات') }}
                            </flux:button>
                        </div>
                    </form>

                    <flux:separator />

                    @if ($phone)
                        @php
                            $whatsappPhone = preg_replace('/[^0-9]/', '', $phone);
                            $whatsappPhone = ltrim($whatsappPhone, '0');
                            $whatsappPhone = str_starts_with($whatsappPhone, '966') ? $whatsappPhone : '966' . $whatsappPhone;
                        @endphp
                        <flux:button variant="ghost" size="sm" icon="chat-bubble-left-ellipsis"
                            class="text-emerald-600 dark:text-emerald-400" href="https://wa.me/{{ $whatsappPhone }}"
                            target="_blank">
                            {{ __('مراسلة') }}
                        </flux:button>
                    @endif

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

                        <livewire:manager.add-linked-role
                            source-guard="teacher"
                            :source-id="$viewingTeacher->id"
                            :source-name="$viewingTeacher->name"
                            :key="'add-linked-role-teacher-'.$viewingTeacher->id" />
                    </div>
                </div>
            @endif
        </div>
    </flux:modal>
</div>