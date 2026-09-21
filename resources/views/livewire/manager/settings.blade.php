<div class="space-y-6">
    <div class="flex items-center gap-3">
        <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
            <flux:icon icon="cog" />
        </div>
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">إعدادات الانضباط</flux:heading>
            <flux:subheading>تخصيص القوانين الخاصة بشؤون الغياب والتأخير للطلاب</flux:subheading>
        </div>
    </div>

    {{-- ─────────── هوية المجمع ─────────── --}}
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-4 sm:p-6 max-w-2xl space-y-6">
        <div>
            <flux:heading size="lg">هوية المجمع</flux:heading>
            <flux:subheading>الشعار واللون اللذان يظهران في كل صفحات التطبيق.</flux:subheading>
        </div>

        {{-- الاسم --}}
        <form wire:submit="saveName" class="space-y-3">
            <div class="flex flex-col sm:flex-row sm:items-end gap-3">
                <div class="flex-1 min-w-0">
                    <flux:input wire:model="siteName" label="اسم المجمع"
                        description="يظهر في الشريط الجانبي وعنوان الصفحة والواجهة العامة." />
                </div>
                <flux:button type="submit" variant="primary" class="shrink-0">حفظ الاسم</flux:button>
            </div>
            <flux:error name="siteName" />
        </form>

        <flux:separator />

        {{-- الشعار --}}
        <form wire:submit="saveLogo" class="space-y-3">
            <flux:label>الشعار</flux:label>

            <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                <div class="flex items-center justify-center h-20 w-32 shrink-0 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 p-2">
                    <img src="{{ $logoUrl }}" alt="شعار المجمع" class="max-h-full max-w-full object-contain">
                </div>

                <div class="flex-1 min-w-0 space-y-2">
                    <flux:input type="file" wire:model="uploadedLogo"
                        accept="image/png,image/jpeg,image/webp,image/svg+xml" />
                    <p class="text-xs text-zinc-400">
                        PNG أو SVG بخلفية شفافة أوضح ما يظهر. الحدّ الأعلى ميغابايت واحد.
                    </p>
                    <flux:error name="uploadedLogo" />
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <flux:button type="submit" variant="primary" size="sm" icon="arrow-up-tray"
                    wire:loading.attr="disabled" wire:target="uploadedLogo,saveLogo">
                    <span wire:loading.remove wire:target="uploadedLogo">حفظ الشعار</span>
                    <span wire:loading wire:target="uploadedLogo">جارٍ الرفع…</span>
                </flux:button>
            </div>
        </form>

        <flux:separator />

        {{-- اللون --}}
        <form wire:submit="saveColor" class="space-y-3">
            <flux:label>اللون الرئيسي</flux:label>

            @php
                // Half a written colour is not a colour, so the current one stands
                // in until what is being typed is whole.
                $preview = App\Support\Branding::normalize($primaryColor) ?? App\Support\Branding::color();
            @endphp

            <div class="flex items-center gap-3">
                {{-- The swatch and the field write to the same property, so
                     picking and typing stay in step whichever the manager uses. --}}
                {{-- value is written out as well as bound: a native colour input
                     with no value falls back to black, and the swatch would read
                     as the chosen colour only after Livewire had booted. --}}
                <input type="color" wire:model.live="primaryColor" value="{{ $preview }}"
                    class="h-11 w-14 shrink-0 cursor-pointer rounded-lg border border-zinc-200 dark:border-zinc-700 bg-transparent p-1"
                    aria-label="اختيار اللون الرئيسي">

                <div class="w-40">
                    <flux:input wire:model="primaryColor" placeholder="#7a2727" dir="ltr" class="text-center" />
                </div>

                <flux:button type="submit" variant="primary">حفظ اللون</flux:button>
            </div>

            <flux:error name="primaryColor" />

            {{-- ما سيبدو عليه، قبل الحفظ لا بعده --}}
            <div class="flex flex-wrap items-center gap-2 pt-1">
                <span class="text-xs text-zinc-400">معاينة:</span>
                <span class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-bold text-white"
                    style="background-color: {{ $preview }}">زرّ رئيسي</span>
                <span class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-bold"
                    style="color: {{ $preview }}; background-color: {{ $preview }}1a">نصّ ملوّن</span>
                <span class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-bold text-white"
                    style="background-color: {{ App\Support\Branding::shade($preview, 0.70) }}">درجة أغمق</span>
            </div>
        </form>

        <flux:separator />

        <div class="flex items-center justify-between gap-3">
            <p class="text-xs text-zinc-400">
                إعادة الشعار واللون إلى ما يأتي به التطبيق أصلاً.
            </p>
            <flux:button size="sm" variant="ghost" class="shrink-0 text-red-500 hover:text-red-600"
                wire:click="resetBranding" wire:confirm="إعادة الهوية إلى الأصل؟">
                إعادة للأصل
            </flux:button>
        </div>
    </div>

    <div
        class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-6 max-w-2xl">
        <form wire:submit="save" class="space-y-6">
            <div class="space-y-4">
                <flux:input type="number" label="حد الغياب المسموح" wire:model="absenceLimit" min="1"
                    description="عدد أيام الغياب التي إذا تجاوزها الطالب سيتم اعتباره متجاوزاً وتحويله للإدارة." />

                <flux:input type="number" label="حد التأخير المسموح" wire:model="latenessLimit" min="1"
                    description="عدد أيام التأخير التي إذا تجاوزها الطالب سيتم اعتباره متجاوزاً وتحويله للإدارة." />

                <flux:input type="number" label="فترة الحساب (بالأيام)" wire:model="calculationPeriodDays" min="1"
                    description="يحدد الإطار الزمني الذي يتم حساب الغياب والتأخير خلاله (مثلاً: آخر 30 يوماً)." />
            </div>

            <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800 flex justify-end">
                <flux:button type="submit" variant="primary"
                    class="w-full sm:w-auto bg-emerald-600 hover:bg-emerald-700 text-white">
                    حفظ الإعدادات
                </flux:button>
            </div>
        </form>
    </div>

    <div class="flex items-center gap-3 mt-8">
        <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
            <flux:icon icon="circle-stack" />
        </div>
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">النسخ الاحتياطي</flux:heading>
            <flux:subheading>أخذ نسخ احتياطية من قاعدة البيانات</flux:subheading>
        </div>
    </div>

    <div
        class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-xs p-6 max-w-2xl space-y-6">
        <div class="flex flex-col sm:flex-row gap-4 items-center justify-between">
            <div>
                <h4 class="font-bold text-zinc-900 dark:text-zinc-100">تحميل نسخة لجهازك</h4>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">تحميل نسخة كاملة من قاعدة البيانات الحالية إلى
                    جهازك.</p>
            </div>
            <flux:button :href="route('manager.backup.download')" icon="arrow-down-tray" class="shrink-0">
                تحميل نسخة
            </flux:button>
        </div>

        <div
            class="pt-4 border-t border-zinc-100 dark:border-zinc-800 flex flex-col sm:flex-row gap-4 items-center justify-between">
            <div>
                <h4 class="font-bold text-zinc-900 dark:text-zinc-100">حفظ نسخة في الخادم</h4>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">إنشاء وحفظ نسخة احتياطية من قاعدة البيانات وتخزينها
                    في الخادم.</p>
            </div>
            <flux:button wire:click="saveBackupToServer" icon="server"
                class="shrink-0 bg-indigo-600 hover:bg-indigo-700 text-white border-none" wire:loading.attr="disabled">
                حفظ في الخادم
            </flux:button>
        </div>

        <div
            class="pt-4 border-t border-zinc-100 dark:border-zinc-800 flex flex-col sm:flex-row gap-4 items-center justify-between">
            <div>
                <h4 class="font-bold text-zinc-900 dark:text-zinc-100">رفع نسخة احتياطية</h4>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">رفع ملف نسخة احتياطية بصيغة sqlite إلى الخادم.</p>
            </div>
            <div class="flex flex-col gap-2 w-full sm:w-auto" x-data="{ isUploading: false, progress: 0 }"
                x-on:livewire-upload-start="isUploading = true"
                x-on:livewire-upload-finish="isUploading = false; progress = 100"
                x-on:livewire-upload-error="isUploading = false; alert('فشل رفع الملف. يرجى التأكد من الحجم والصيغة.')"
                x-on:livewire-upload-progress="progress = $event.detail.progress">

                <div class="flex items-center gap-2">
                    <input type="file" wire:model="uploadedBackup" accept=".sqlite,.db"
                        class="text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-zinc-50 file:text-zinc-700 hover:file:bg-zinc-100 dark:file:bg-zinc-800 dark:file:text-zinc-300">
                    <flux:button wire:click="uploadBackup" icon="arrow-up-tray" class="shrink-0"
                        wire:loading.attr="disabled" x-bind:disabled="isUploading">
                        رفع الملف
                    </flux:button>
                </div>
                <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $uploadedBackup }}
                </div>


                <!-- شريط التقدم -->
                <div x-show="isUploading" class="w-full bg-zinc-200 rounded-full h-2.5 dark:bg-zinc-700 mt-2">
                    <div class="bg-indigo-600 h-2.5 rounded-full   duration-300"
                        x-bind:style="'width: ' + progress + '%'"></div>
                </div>
                <div x-show="isUploading" class="text-xs text-zinc-500 text-center">جاري المعالجة والرفع... <span
                        x-text="progress"></span>%</div>

                @error('uploadedBackup') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
            </div>
        </div>

        @php
            $backupGroups = [
                ['title' => 'النسخ المجدولة', 'items' => $scheduledBackups],
                ['title' => 'النسخ التي تم نسخها', 'items' => $manualBackups],
                ['title' => 'النسخ المرفوعة', 'items' => $uploadedBackups],
            ];
        @endphp

        @foreach($backupGroups as $group)
            @if(count($group['items']) > 0)
                <div class="pt-6 border-t border-zinc-100 dark:border-zinc-800">
                    <h4 class="font-bold text-zinc-900 dark:text-zinc-100 mb-4">{{ $group['title'] }}</h4>
                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-sm text-right">
                            <thead class="bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500 dark:text-zinc-400">
                                <tr>
                                    <th class="px-4 py-3 font-medium">اسم الملف</th>
                                    <th class="px-4 py-3 font-medium">الحجم</th>
                                    <th class="px-4 py-3 font-medium">وقت الإنشاء</th>
                                    <th class="px-4 py-3 font-medium">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach($group['items'] as $backup)
                                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                        <td class="px-4 py-3 dir-ltr text-right text-zinc-900 dark:text-zinc-100">
                                            {{ $backup['name'] }}
                                        </td>
                                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">{{ $backup['size'] }}</td>
                                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400 dir-ltr text-right">
                                            {{ $backup['time'] }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-2">
                                                <flux:button
                                                    :href="route('manager.backup.download.stored', ['filename' => $backup['name']])"
                                                    icon="arrow-down-tray" variant="subtle" size="sm">
                                                    تحميل
                                                </flux:button>
                                                <flux:button
                                                    href="{{ route('manager.backup-browser', ['filename' => $backup['name']]) }}"
                                                    icon="folder-open" variant="subtle" size="sm" class="text-indigo-600">
                                                    تصفح
                                                </flux:button>
                                                <flux:button wire:click="restoreFullBackup('{{ $backup['name'] }}')"
                                                    icon="arrow-path" variant="subtle" size="sm"
                                                    class="text-emerald-600 hover:text-emerald-700"
                                                    wire:confirm="هل أنت متأكد من العودة لهذه النسخة بشكل كامل؟ سيقوم النظام تلقائياً بحفظ نسخة من وضعك الحالي قبل الاسترجاع.">
                                                    استرجاع كامل
                                                </flux:button>
                                                <flux:button wire:click="deleteBackup('{{ $backup['name'] }}')" icon="trash"
                                                    variant="subtle" size="sm" class="text-red-500 hover:text-red-700"
                                                    wire:confirm="هل أنت متأكد من حذف هذه النسخة؟">
                                                    حذف
                                                </flux:button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</div>