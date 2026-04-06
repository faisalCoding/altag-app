<x-layouts.role-shell>
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold">لوحة تحكم ولي الأمر</h1>
        </div>

        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div class="relative overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6">
                <div class="flex items-center gap-4">
                    <div class="p-3 bg-blue-100 text-blue-600 rounded-lg dark:bg-blue-900/30 dark:text-blue-400">
                        <flux:icon icon="users" class="size-6" />
                    </div>
                    <div>
                        <p class="text-sm font-medium text-neutral-500 dark:text-neutral-400">عدد الأبناء (الطلاب)</p>
                        <h3 class="text-2xl font-bold text-neutral-900 dark:text-white">{{ auth()->guard('guardian')->user()->students()->count() }}</h3>
                    </div>
                </div>
            </div>
            
            <div class="relative overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6">
                <div class="flex items-center gap-4">
                    <div class="p-3 bg-green-100 text-green-600 rounded-lg dark:bg-green-900/30 dark:text-green-400">
                        <flux:icon icon="check-circle" class="size-6" />
                    </div>
                    <div>
                        <p class="text-sm font-medium text-neutral-500 dark:text-neutral-400">حالة الاعتماد</p>
                        <h3 class="text-xl font-bold text-neutral-900 dark:text-white">
                            {{ auth()->guard('guardian')->user()->is_approved ? 'معتمد' : 'قيد الانتظار' }}
                        </h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="relative h-full flex-1 rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-6">
            <h2 class="text-lg font-bold mb-4">بيانات الأبناء</h2>
            
            <div class="space-y-4">
                @forelse(auth()->guard('guardian')->user()->students as $student)
                    <div class="flex items-center justify-between p-4 rounded-lg bg-neutral-50 dark:bg-neutral-900 border border-neutral-100 dark:border-neutral-800">
                        <div class="flex items-center gap-3">
                            <flux:icon icon="academic-cap" class="size-5 text-neutral-500" />
                            <div>
                                <h4 class="font-medium text-neutral-900 dark:text-neutral-100">{{ $student->name }}</h4>
                                <p class="text-sm text-neutral-500">{{ $student->circle ? $student->circle->name : 'لم يتم تعيين حلقة بعد' }}</p>
                            </div>
                        </div>
                        <div>
                            @if($student->is_approved)
                                <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded-full dark:bg-green-900/30 dark:text-green-400">معتمد</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-800 rounded-full dark:bg-yellow-900/30 dark:text-yellow-400">قيد الانتظار</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="text-center py-8 text-neutral-500">
                        لا يوجد أبناء مسجلين حالياً
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-layouts.role-shell>
