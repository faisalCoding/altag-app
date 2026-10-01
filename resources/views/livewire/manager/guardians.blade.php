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
        <div class="w-full md:flex-1">
            <flux:input icon="magnifying-glass" wire:model.live.debounce.300ms="search"
                placeholder="بحث عن ولي أمر..." />
        </div>
        <div class="w-full md:w-64">
            <flux:select wire:model.live="statusFilter" placeholder="تصفية حسب الحالة">
                <flux:select.option value="all">الكل</flux:select.option>
                <flux:select.option value="pending">في انتظار الموافقة</flux:select.option>
                <flux:select.option value="approved">تمت الموافقة</flux:select.option>
            </flux:select>
        </div>
    </div>

    <x-magic-link-selection-bar :count="count($selectedIds)" :outside="$selectedOutsideFilters"
        :text="$selectedMagicLinksText" noun="وليّ أمر" copied="نُسخت أسماء الأوصياء وروابطهم" />

    <div
        class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <flux:table class="w-full">
            <flux:table.columns>
                <flux:table.column class="w-10">
                    <flux:checkbox wire:model.live="selectAll" />
                </flux:table.column>
                <flux:table.column>{{ __('ولي الأمر') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('الأبناء') }}</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">{{ __('الحالة') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('تاريخ الإضافة') }}</flux:table.column>
                <flux:table.column class="w-10"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($guardians as $guardian)
                    <flux:table.row :key="$guardian->id"
                        class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50   s"
                        x-on:click="$flux.modal('guardian-modal').show(); $wire.edit({{ $guardian->id }})">
                        <flux:table.cell x-on:click.stop="">
                            <flux:checkbox wire:model.live="selectedIds" value="{{ $guardian->id }}" />
                        </flux:table.cell>
                        <flux:table.cell class="first:ps-3" >
                            <div class="flex flex-col">
                                <span class="font-bold text-zinc-900 dark:text-white">{{ $guardian->name }}</span>
                                <div class="flex gap-2">
                                    <span class="text-xs text-zinc-500">{{ $guardian->email }}</span>
                                    @if ($guardian->phone)
                                        <span class="text-xs text-zinc-400">| {{ $guardian->phone }}</span>
                                    @endif
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">
                            <div class="flex flex-wrap gap-1">
                                @forelse($guardian->students as $student)
                                    <flux:badge size="sm" variant="neutral">{{ $student->name }}</flux:badge>
                                @empty
                                    <span class="text-xs text-zinc-400">لا يوجد أبناء مسجلين</span>
                                @endforelse
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="hidden sm:table-cell">
                            @if ($guardian->is_approved)
                                <flux:badge size="sm" color="green">معتمد</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber">قيد الانتظار</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell text-xs text-zinc-400">
                            <x-hijri-date :date="$guardian->created_at" />
                        </flux:table.cell>
                        <flux:table.cell class="first:ps-3" >
                            <div class="flex items-center justify-end gap-2" @click.stop>
                                @if (!$guardian->is_approved)
                                    <flux:button size="sm" variant="primary" class="bg-emerald-600 hover:bg-emerald-700"
                                        wire:click="approve({{ $guardian->id }})">موافقة</flux:button>
                                @endif
                                <flux:dropdown>
                                    <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" />
                                    <flux:menu>
                                        <flux:menu.item
                                            x-on:click="$flux.modal('guardian-modal').show(); $wire.edit({{ $guardian->id }})"
                                            icon="eye">{{ __('عرض وتعديل التفاصيل') }}</flux:menu.item>
                                        <flux:separator />
                                        <flux:menu.item wire:click="delete({{ $guardian->id }})"
                                            wire:confirm="هل أنت متأكد من حذف ولي الأمر؟" variant="danger" icon="trash">
                                            {{ __('حذف ولي الأمر') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <!-- Guardian Details Modal -->
    <flux:modal name="guardian-modal" variant="flyout" class="md:w-[500px]">
        <div wire:loading wire:target="edit"
            class="w-full h-full flex flex-col items-center justify-center min-h-[300px] text-zinc-400">
            <flux:icon icon="arrow-path" class="size-8 animate-spin mb-4" />
            <p>{{ __('جاري تحميل بيانات ولي الأمر...') }}</p>
        </div>

        <div wire:loading.remove wire:target="edit">
            @if ($viewingGuardian)
                <div class="space-y-8">
                    <div>
                        <flux:heading size="xl">{{ __('ملف ولي الأمر') }}</flux:heading>
                        <flux:subheading>{{ __('عرض وتعديل بيانات ولي الأمر والطلاب المرتبطين به') }}</flux:subheading>
                    </div>

                    <form wire:submit="save" class="space-y-4">
                        <flux:input label="الاسم الكامل" wire:model="name" required />
                        <flux:input label="البريد الإلكتروني" wire:model="email" type="email" required />
                        <flux:input label="رقم الجوال" wire:model="phone" placeholder="9665xxxxxxxx" dir="ltr"
                            class="text-right" />

                        <div class="space-y-2">
                            <flux:heading size="sm">{{ __('تعيين الأبناء (الطلاب)') }}</flux:heading>
                            <flux:input wire:model.live.debounce.300ms="studentSearch" placeholder="بحث باسم الطالب..."
                                icon="magnifying-glass" size="sm" />
                            <div
                                class="flex flex-col gap-4 max-h-64 overflow-y-auto p-3 border border-zinc-100 rounded-xl dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50">
                                @foreach($this->groupedStudents as $circleName => $students)
                                    <div>
                                        <div class="text-xs font-semibold text-zinc-500 mb-2">{{ $circleName }}</div>
                                        <div
                                            class="grid grid-cols-1 sm:grid-cols-2 gap-2 pl-2 border-r-2 border-zinc-200 dark:border-zinc-700">
                                            @foreach($students as $student)
                                                <div class="flex items-center gap-2">
                                                    <flux:checkbox wire:model="selectedStudents" :value="$student->id"
                                                        :id="'student-'.$student->id" />
                                                    <flux:label :for="'student-'.$student->id" class="cursor-pointer text-sm">
                                                        {{ $student->name }}</flux:label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                                @if($this->groupedStudents->isEmpty())
                                    <div class="text-sm text-zinc-500 text-center py-2">{{ __('لم يتم العثور على طلاب') }}</div>
                                @endif
                            </div>
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
                                    @if ($viewingGuardian->is_approved)
                                        <span class="text-green-600 dark:text-green-400">{{ __('معتمد') }}</span>
                                    @else
                                        <span class="text-amber-600 dark:text-amber-400">{{ __('قيد الانتظار') }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="font-medium text-sm">{{ __('تاريخ الإضافة') }}</div>
                                <div class="text-xs text-zinc-500 mt-1"><x-hijri-date :date="$viewingGuardian->created_at" />
                                </div>
                            </div>
                        </div>

                        <div class="p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl">
                            <div class="flex items-center justify-between mb-2">
                                <div class="font-medium text-sm">{{ __('رابط الدخول السحري') }}</div>
                                <flux:button size="sm" variant="ghost" icon="arrow-path"
                                    wire:click="resetToken({{ $viewingGuardian->id }})"
                                    wire:confirm="هل أنت متأكد من إنشاء رابط جديد؟ سيتم إبطال الرابط القديم.">
                                    {{ $viewingGuardian->access_token ? __('إعادة إنشاء') : __('إنشاء رابط') }}
                                </flux:button>
                            </div>
                            @if($viewingGuardian->access_token)
                                <div class="flex items-center gap-2"
                                    x-data="{ copied: false, link: '{{ route('guardian.magic-link', ['token' => $viewingGuardian->access_token]) }}' }">
                                    <flux:input readonly copyable class="w-full text-xs"
                                        :value="route('guardian.magic-link', ['token' => $viewingGuardian->access_token])" />
                                </div>
                            @else
                                <div class="text-xs text-zinc-500">{{ __('لم يتم إنشاء رابط دخول لولي الأمر هذا بعد.') }}</div>
                            @endif
                        </div>

                        <livewire:manager.add-linked-role
                            source-guard="guardian"
                            :source-id="$viewingGuardian->id"
                            :source-name="$viewingGuardian->name"
                            :key="'add-linked-role-guardian-'.$viewingGuardian->id" />
                    </div>
                </div>
            @endif
        </div>
    </flux:modal>
</div>