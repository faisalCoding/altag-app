<x-layouts::app :title="isset($roleName) ? __('لوحة تحكم') . ' - ' . $roleName : __('لوحة تحكم')">
    <div class="p-6 md:p-8 space-y-8" dir="rtl">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <flux:heading size="xl" class="font-bold text-maroon dark:text-red-secondary">
                    {{ isset($roleName) ? __('لوحة تحكم') . ' ' . $roleName : __('لوحة تحكم') }}
                </flux:heading>
                <flux:subheading>{{ __('مرحباً بك مجدداً في نظام إدارة مجمع التاج القرآني') }}</flux:subheading>
            </div>
            
            @if (isset($role) && $role === 'manager')
                <div class="flex items-center gap-2">
                    <flux:button icon="plus" variant="primary" class="bg-maroon hover:bg-burgundy">{{ __('إضافة مشروع') }}</flux:button>
                </div>
            @endif
        </div>

        @if (isset($role) && $role === 'manager')
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-6 shadow-sm overflow-hidden">
                <livewire:manager.pending-approvals />
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white dark:bg-zinc-900 p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm flex flex-col justify-between h-40 group hover:border-maroon transition-colors cursor-pointer">
                    <div class="flex justify-between items-start">
                        <div class="p-3 rounded-xl bg-maroon/5 text-maroon dark:bg-red-secondary/10 dark:text-red-secondary">
                            <flux:icon icon="users" />
                        </div>
                        <flux:icon icon="chevron-left" class="text-zinc-300 group-hover:text-maroon transition-colors" />
                    </div>
                    <div class="space-y-1">
                        <flux:heading size="lg" class="font-bold">إحصائيات الطلاب</flux:heading>
                        <flux:subheading size="sm">متابعة الحضور والغياب</flux:subheading>
                    </div>
                </div>

                <div class="bg-white dark:bg-zinc-900 p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm flex flex-col justify-between h-40 group hover:border-maroon transition-colors cursor-pointer">
                    <div class="flex justify-between items-start">
                        <div class="p-3 rounded-xl bg-blue-500/5 text-blue-600">
                            <flux:icon icon="book-open" />
                        </div>
                        <flux:icon icon="chevron-left" class="text-zinc-300 group-hover:text-blue-600 transition-colors" />
                    </div>
                    <div class="space-y-1">
                        <flux:heading size="lg" class="font-bold">المناهج القرآنية</flux:heading>
                        <flux:subheading size="sm">إدارة خطط الحفظ</flux:subheading>
                    </div>
                </div>

                <div class="bg-white dark:bg-zinc-900 p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm flex flex-col justify-between h-40 group hover:border-maroon transition-colors cursor-pointer">
                    <div class="flex justify-between items-start">
                        <div class="p-3 rounded-xl bg-orange-500/5 text-orange-600">
                            <flux:icon icon="calendar" />
                        </div>
                        <flux:icon icon="chevron-left" class="text-zinc-300 group-hover:text-orange-600 transition-colors" />
                    </div>
                    <div class="space-y-1">
                        <flux:heading size="lg" class="font-bold">الجدول الدراسي</flux:heading>
                        <flux:subheading size="sm">مواعيد الحلقات والاختبارات</flux:subheading>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-8 shadow-sm h-96 flex flex-col items-center justify-center text-center space-y-4">
                <div class="bg-zinc-50 dark:bg-zinc-800 p-6 rounded-full">
                    <flux:icon icon="sparkles" class="size-12 text-zinc-300 dark:text-zinc-600" />
                </div>
                <div class="max-w-xs mx-auto">
                    <flux:heading size="lg">محتوى لوحة التحكم</flux:heading>
                    <flux:subheading>هنا ستظهر أهم التحديثات والنشاطات اليومية الخاصة بك.</flux:subheading>
                </div>
            </div>
        @endif
    </div>
</x-layouts::app>
