<div class="space-y-6">
    <div class="flex items-center gap-3">
        <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
            <flux:icon icon="users" />
        </div>
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">إدارة المشرفين</flux:heading>
            <flux:subheading>إدارة شؤون المشرفين والموافقة عليهم وتعيين المراحل.</flux:subheading>
        </div>
        <flux:spacer />
        <flux:button variant="primary" icon="plus" wire:click="add">إضافة مشرف</flux:button>
    </div>

    <div class="flex flex-col md:flex-row gap-4 items-end">
        <div class="w-full md:flex-1">
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

    <!-- Quick Create Card -->
    <flux:card>
        <form wire:submit="createQuickSupervisor" class="flex flex-col md:flex-row items-end gap-4">
            <div class="w-full md:w-2/5">
                <flux:input wire:model="quickName" label="{{ __('اسم المشرف') }}"
                    placeholder="{{ __('مثال: أحمد محمود') }}" required />
            </div>
            <div class="w-full md:w-2/5">
                <flux:input wire:model="quickPhone" label="{{ __('رقم الهاتف') }}" placeholder="{{ __('اختياري') }}" />
            </div>
            <flux:button type="submit" variant="primary" icon="user-plus" class="min-w-fit">{{ __('إنشاء سريع') }}
            </flux:button>
        </form>
    </flux:card>

    <x-magic-link-selection-bar :count="count($selectedIds)" :outside="$selectedOutsideFilters"
        :text="$selectedMagicLinksText" noun="مشرف" copied="نُسخت أسماء المشرفين وروابطهم" />

    <div
        class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs overflow-hidden">
        <flux:table class="w-full">
            <flux:table.columns>
                <flux:table.column class="w-10">
                    <flux:checkbox wire:model.live="selectAll" />
                </flux:table.column>
                <flux:table.column>{{ __('المشرف') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('المراحل') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell text-center">{{ __('حالة البيانات') }}
                </flux:table.column>
                <flux:table.column class="w-10"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($supervisors as $supervisor)
                    <flux:table.row :key="$supervisor->id"
                        class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50   s"
                        x-on:click="$flux.modal('supervisor-modal').show(); $wire.edit({{ $supervisor->id }})">
                        <flux:table.cell x-on:click.stop="">
                            <flux:checkbox wire:model.live="selectedIds" value="{{ $supervisor->id }}" />
                        </flux:table.cell>
                        <flux:table.cell class="first:ps-3" >
                            <div class="flex flex-col">
                                <span class="font-bold text-zinc-900 dark:text-white">{{ $supervisor->name }}</span>
                                <div class="flex gap-2">
                                    <span class="text-xs text-zinc-500">{{ $supervisor->email }}</span>
                                    @if ($supervisor->phone)
                                        <span class="text-xs text-zinc-400">| {{ $supervisor->phone }}</span>
                                    @endif
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">
                            <div class="flex flex-wrap gap-1">
                                @forelse($supervisor->stages as $stage)
                                    <flux:badge size="sm" variant="neutral">{{ $stage->name }}</flux:badge>
                                @empty
                                    <span class="text-xs text-zinc-400">لا يوجد مراحل</span>
                                @endforelse
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell text-center">
                            @if($supervisor->is_data_completed)
                                <flux:badge color="green" size="sm" icon="check-circle">{{ __('مكتملة') }}</flux:badge>
                            @else
                                <flux:badge color="amber" size="sm" icon="clock">{{ __('غير مكتملة') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="first:ps-3" >
                            <div class="flex items-center justify-end gap-2" @click.stop>
                                @if (!$supervisor->is_approved)
                                    <flux:button size="sm" variant="primary" class="bg-emerald-600 hover:bg-emerald-700"
                                        wire:click="approve({{ $supervisor->id }})">موافقة</flux:button>
                                @endif
                                <flux:dropdown>
                                    <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" />
                                    <flux:menu>
                                        <flux:menu.item
                                            x-on:click="$flux.modal('supervisor-modal').show(); $wire.edit({{ $supervisor->id }})"
                                            icon="eye">{{ __('عرض وتعديل التفاصيل') }}</flux:menu.item>
                                        <flux:separator />
                                        <flux:menu.item wire:click="delete({{ $supervisor->id }})"
                                            wire:confirm="هل أنت متأكد من حذف هذا المشرف؟" variant="danger" icon="trash">
                                            {{ __('حذف المشرف') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <!-- Supervisor Details Modal -->
    <flux:modal name="supervisor-modal" variant="flyout" class="md:w-[500px]">
        <div wire:loading wire:target="edit"
            class="w-full h-full flex flex-col items-center justify-center min-h-[300px] text-zinc-400">
            <flux:icon icon="arrow-path" class="size-8 animate-spin mb-4" />
            <p>{{ __('جاري تحميل بيانات المشرف...') }}</p>
        </div>

        <div wire:loading.remove wire:target="edit">
            @if ($viewingSupervisor)
                <div class="space-y-8">
                    <div>
                        <flux:heading size="xl">{{ __('ملف المشرف') }}</flux:heading>
                        <flux:subheading>{{ __('عرض وتعديل بيانات المشرف الأساسية') }}</flux:subheading>
                    </div>

                    <form wire:submit="save" class="space-y-4">
                        <flux:input label="الاسم" wire:model="name" required />
                        <flux:input label="البريد الإلكتروني" wire:model="email" type="email" required />
                        <flux:input label="رقم الجوال" wire:model="phone" placeholder="9665xxxxxxx" dir="ltr"
                            class="text-right" />

                        <div class="grid grid-cols-1 gap-4">
                            <flux:input label="كلمة المرور" wire:model="password" type="password"
                                :required="!$editingSupervisorId" viewable />
                            @if($editingSupervisorId)
                                <p class="text-xs text-zinc-500 mt-1">اتركه فارغاً إذا كنت لا ترغب في تغيير كلمة المرور.</p>
                            @endif
                        </div>

                        <div class="space-y-2">
                            <flux:heading size="sm">{{ __('تعيين المراحل') }}</flux:heading>
                            <div
                                class="flex flex-col gap-2 max-h-48 overflow-y-auto p-3 border border-zinc-100 rounded-xl dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50">
                                @foreach ($stages as $stage)
                                    <div class="flex items-center gap-2">
                                        <flux:checkbox wire:model="selectedStages" :value="$stage->id"
                                            :id="'stage-'.$stage->id" />
                                        <flux:label :for="'stage-'.$stage->id" class="cursor-pointer">{{ $stage->name }}
                                        </flux:label>
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

                    <!-- Additional Info -->
                    <div class="space-y-4">
                        <flux:heading size="sm">{{ __('معلومات إضافية') }}</flux:heading>
                        <div class="flex items-center justify-between p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl">
                            <div>
                                <div class="font-medium text-sm">{{ __('حالة الاعتماد') }}</div>
                                <div class="text-xs mt-1">
                                    @if ($viewingSupervisor->is_approved)
                                        <span class="text-green-600 dark:text-green-400">{{ __('معتمد') }}</span>
                                    @else
                                        <span class="text-amber-600 dark:text-amber-400">{{ __('قيد الانتظار') }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="font-medium text-sm">{{ __('تاريخ الإضافة') }}</div>
                                <div class="text-xs text-zinc-500 mt-1">
                                    <x-hijri-date :date="$viewingSupervisor->created_at" /></div>
                            </div>
                        </div>

                        <div class="p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl">
                            <div class="flex items-center justify-between mb-2">
                                <div class="font-medium text-sm">{{ __('رابط الدخول السحري') }}</div>
                                <flux:button size="sm" variant="ghost" icon="arrow-path"
                                    wire:click="resetToken({{ $viewingSupervisor->id }})"
                                    wire:confirm="هل أنت متأكد من إنشاء رابط جديد؟ سيتم إبطال الرابط القديم.">
                                    {{ $viewingSupervisor->access_token ? __('إعادة إنشاء') : __('إنشاء رابط') }}
                                </flux:button>
                            </div>
                            @if($viewingSupervisor->access_token)
                                <div class="flex items-center gap-2"
                                    x-data="{ copied: false, link: '{{ route('supervisor.magic-link', ['token' => $viewingSupervisor->access_token]) }}' }">
                                    <flux:input readonly copyable class="w-full text-xs"
                                        :value="route('supervisor.magic-link', ['token' => $viewingSupervisor->access_token])" />
                                </div>
                            @else
                                <div class="text-xs text-zinc-500">{{ __('لم يتم إنشاء رابط دخول لهذا المشرف بعد.') }}</div>
                            @endif
                        </div>

                        <livewire:manager.add-linked-role
                            source-guard="supervisor"
                            :source-id="$viewingSupervisor->id"
                            :source-name="$viewingSupervisor->name"
                            :key="'add-linked-role-supervisor-'.$viewingSupervisor->id" />
                    </div>
                </div>
            @endif
        </div>
    </flux:modal>
</div>