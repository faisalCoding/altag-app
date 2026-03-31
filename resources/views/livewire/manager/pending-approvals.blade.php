<div dir="rtl" class="space-y-6">
    <div class="flex items-center gap-3 mb-2">
        <div class="p-2 rounded-lg bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
            <flux:icon icon="user-plus" />
        </div>
        <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">الموافقة على المستخدمين</flux:heading>
    </div>

    {{-- Tabs --}}
    <div class="flex gap-1 bg-zinc-50 dark:bg-zinc-800 p-1 rounded-xl w-fit border border-zinc-100 dark:border-zinc-700">
        @php
            $tabs = [
                'supervisor' => ['label' => 'المشرفون', 'color' => 'blue'],
                'teacher'    => ['label' => 'المعلمون',  'color' => 'violet'],
                'student'    => ['label' => 'الطلاب',    'color' => 'emerald'],
            ];
        @endphp

        @foreach ($tabs as $key => $tab)
            <button
                wire:click="setTab('{{ $key }}')"
                @class([
                    'flex items-center gap-2 px-6 py-2 text-sm font-bold rounded-lg transition-all',
                    'bg-white dark:bg-zinc-700 text-maroon dark:text-white shadow-sm' => $activeTab === $key,
                    'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' => $activeTab !== $key,
                ])
            >
                {{ $tab['label'] }}
                @if (($this->counts[$key] ?? 0) > 0)
                    <span @class([
                        'flex items-center justify-center min-w-5 h-5 px-1.5 text-[10px] rounded-full text-white',
                        'bg-blue-600' => $tab['color'] === 'blue',
                        'bg-purple-600' => $tab['color'] === 'violet',
                        'bg-emerald-600' => $tab['color'] === 'emerald',
                    ])>
                        {{ $this->counts[$key] }}
                    </span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Table --}}
    <div class="overflow-x-auto">
        <flux:table :paginate="$this->pendingUsers">
            <flux:table.columns>
                <flux:table.column class="text-right">الاسم</flux:table.column>
                <flux:table.column class="text-right">البريد الإلكتروني</flux:table.column>
                <flux:table.column class="text-right">تاريخ التسجيل</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->pendingUsers as $user)
                    <flux:table.row :key="$user->id">
                        <flux:table.cell class="font-bold text-zinc-900 dark:text-white">{{ $user->name }}</flux:table.cell>
                        <flux:table.cell>{{ $user->email }}</flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap text-xs text-zinc-500">
                            {{ $user->created_at->diffForHumans() }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-2 justify-end">
                                <flux:button
                                    size="sm"
                                    variant="primary"
                                    wire:click="approve({{ $user->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="approve({{ $user->id }})"
                                    class="bg-emerald-600 hover:bg-emerald-700 border-none px-4"
                                >
                                    موافقة
                                </flux:button>
                                <flux:button
                                    size="sm"
                                    variant="danger"
                                    wire:click="reject({{ $user->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="reject({{ $user->id }})"
                                    wire:confirm="هل أنت متأكد من رفض هذا المستخدم وحذفه؟"
                                    class="px-4"
                                >
                                    رفض
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" class="text-center py-16">
                            <div class="flex flex-col items-center gap-2">
                                <flux:icon icon="check-circle" class="size-10 text-emerald-500/20" />
                                <flux:text class="text-zinc-400">لا يوجد مستخدمون في انتظار الموافقة</flux:text>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</div>
