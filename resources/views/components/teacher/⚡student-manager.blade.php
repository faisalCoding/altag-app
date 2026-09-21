<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Student;
use App\Models\Circle;
use App\Services\StudentStatusService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Flux\Flux;

new class extends Component {
    use WithPagination;

    /** One name per line, optionally "الاسم، الرقم" — a list pasted as it was sent. */
    public $names = '';

    public $joinedAt = '';

    public $search = '';

    // ── التحديد الجماعي ─────────────────────────────────────────────────────
    /** @var array<int, int|string> */
    public $selected = [];

    public $bulkStatus = 'active';

    public $bulkStatusDate = '';

    public $bulkJoinedAt = '';

    public function mount(): void
    {
        // Riyadh, not app.timezone: the app runs on UTC, and between midnight and
        // three in the morning a student added "today" would be dated yesterday.
        $today = now('Asia/Riyadh')->format('Y-m-d');

        $this->joinedAt = $today;
        $this->bulkStatusDate = $today;
        $this->bulkJoinedAt = $today;
    }

    // Modal state
    public $viewingStudent = null;
    public $editName = '';
    public $editPhone = '';
    public $editCircleId = null;
    public $editJoinedAt = '';
    public $stats = [];

    // Unassigned students modal state
    public $unassignedSearch = '';

    public function getUnassignedStudentsProperty()
    {
        return Student::whereNull('circle_id')
            ->when($this->unassignedSearch, function ($query) {
                $query->where('name', 'like', '%' . $this->unassignedSearch . '%');
            })
            ->latest()
            ->take(20)
            ->get();
    }

    public function addToCircle($studentId)
    {
        $teacher = Auth::guard('teacher')->user();

        if (empty($teacher->effectivePermissions()['can_manage_students'])) {
            Flux::toast('ليس لديك صلاحية لإضافة الطلاب', variant: 'danger');
            return;
        }

        $circle = $teacher->circles()->first();

        if (!$circle) {
            Flux::toast('ليس لديك حلقة لإضافة الطالب إليها', variant: 'danger');
            return;
        }

        $today = now('Asia/Riyadh')->format('Y-m-d');

        $student = Student::whereNull('circle_id')->findOrFail($studentId);
        $student->update(['circle_id' => $circle->id, 'joined_at' => $today]);

        // A student put into a circle is taking part in it. Left as they were,
        // they would sit in the roll call invisible — the register only shows
        // students active on the day.
        if ($student->status !== 'active') {
            try {
                StudentStatusService::changeStatus($student, 'active', $today, 'أُضيف إلى الحلقة');
            } catch (\InvalidArgumentException $e) {
                Flux::toast('أُضيف الطالب، ولم تتغيّر حالته: '.$e->getMessage(), variant: 'warning');
                $this->dispatch('student-list-updated');

                return;
            }
        }

        $this->dispatch('student-list-updated');
        Flux::toast('تمت إضافة الطالب للحلقة بنجاح', variant: 'success');
    }

    public function removeFromCircle()
    {
        $teacher = Auth::guard('teacher')->user();

        if (empty($teacher->effectivePermissions()['can_manage_students'])) {
            Flux::toast('ليس لديك صلاحية لإزالة الطلاب', variant: 'danger');
            return;
        }

        $teacherCircles = $teacher->circles()->pluck('id')->toArray();
        if (!in_array($this->viewingStudent->circle_id, $teacherCircles)) {
            abort(403);
        }

        $this->viewingStudent->update(['circle_id' => null, 'status' => 'left']);
        $this->viewingStudent->statusHistories()->create([
            'status' => 'left',
            'start_date' => now('Asia/Riyadh')->format('Y-m-d'),
            'notes' => 'تمت إزالته من الحلقة عبر إدارة الطلاب',
        ]);

        $this->dispatch('student-list-updated');
        Flux::modal('student-details')->close();
        Flux::toast('تم إزالة الطالب من الحلقة بنجاح', variant: 'success');
    }

    /**
     * The pasted list, one entry per line, as {name, phone} pairs.
     *
     * A line may carry a phone after a comma, a tab or a semicolon, so a column
     * copied out of a spreadsheet arrives intact. Blank lines are dropped and a
     * name repeated inside the paste itself is only taken once.
     *
     * @return array<int, array{name: string, phone: string|null}>
     */
    public function parsedNames(): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/u', (string) $this->names) as $line) {
            // The /u matters: the Arabic comma is two bytes, and a byte-wise
            // character class matches its lead byte inside ordinary Arabic
            // letters too — «محمد أحمد» came back as «م».
            $parts = preg_split('/[,،;\t]+/u', trim($line), 2);
            $name = trim($parts[0] ?? '');

            if ($name === '') {
                continue;
            }

            $phone = isset($parts[1]) ? preg_replace('/[^0-9+]/', '', $parts[1]) : '';

            $rows[$name] = ['name' => $name, 'phone' => $phone !== '' ? $phone : null];
        }

        return array_values($rows);
    }

    /**
     * Create everyone on the list in one go.
     *
     * They are created participating, not "تحت التسجيل". A teacher adding a
     * student to their own circle has enrolled them — the register would
     * otherwise hide the student on the very day they were added, and the
     * teacher would have to correct by hand what the form had just got wrong.
     * Whether the paperwork is finished is a different question, and
     * is_data_completed is the column that already answers it.
     */
    public function createStudent()
    {
        $teacher = Auth::guard('teacher')->user();

        if (empty($teacher->effectivePermissions()['can_create_students'])) {
            Flux::toast('ليس لديك صلاحية إضافة طلاب جدد', variant: 'danger');
            return;
        }

        $this->validate([
            'names' => 'required|string',
            'joinedAt' => 'required|date|before_or_equal:' . now('Asia/Riyadh')->format('Y-m-d'),
        ], [
            'names.required' => 'اكتب اسماً واحداً على الأقل.',
            'joinedAt.before_or_equal' => 'تاريخ الالتحاق لا يكون في المستقبل.',
        ]);

        $rows = $this->parsedNames();

        if ($rows === []) {
            $this->addError('names', 'اكتب اسماً واحداً على الأقل.');
            return;
        }

        $circle = $teacher->circles()->first();

        if (!$circle) {
            Flux::toast('لا توجد حلقة مرتبطة بك لإضافة الطلاب فيها.', variant: 'danger');
            return;
        }

        // Names already in this circle are passed over rather than duplicated:
        // pasting the same list twice is an ordinary slip, and the cost of it
        // should be nothing rather than a second row for every student.
        $existing = Student::where('circle_id', $circle->id)->pluck('name')
            ->map(fn ($name) => trim($name))->all();

        $created = 0;
        $skipped = collect();

        foreach ($rows as $row) {
            if (in_array($row['name'], $existing, true)) {
                $skipped->push($row['name']);
                continue;
            }

            $student = Student::create([
                'name' => $row['name'],
                'phone' => $row['phone'],
                'email' => 'student_' . Str::random(10) . '@uncompleted.altag.app',
                'password' => Hash::make(Str::random(10)),
                'circle_id' => $circle->id,
                'is_approved' => true,
                'access_token' => Str::random(32),
                'is_data_completed' => false,
                'status' => 'active',
                'joined_at' => $this->joinedAt,
            ]);

            $student->statusHistories()->create([
                'status' => 'active',
                'start_date' => $this->joinedAt,
                'notes' => 'أضافه المعلم إلى الحلقة',
            ]);

            $created++;
        }

        $this->dispatch('student-list-updated');
        $this->reset(['names']);
        $this->resetPage();

        if ($created === 0) {
            Flux::toast('كل من في القائمة مضاف إلى الحلقة أصلاً.', variant: 'warning');
            return;
        }

        Flux::toast(
            'أُضيف ' . $created . ' طالباً'
                . ($skipped->isNotEmpty() ? '، وتُخطّي ' . $skipped->count() . ' موجوداً أصلاً: ' . $skipped->take(3)->implode('، ') : '')
                . '.',
            variant: 'success',
        );
    }

    public function viewStudent($studentId)
    {
        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('id');

        $this->viewingStudent = Student::with([
            'circle',
            'guardian',
            'plans' => function ($q) {
                $q->latest();
            },
            'odePlans' => function ($q) {
                $q->latest();
            },
            'odePlans.path.ode',
            'odePlans.path.days',
            'attendances',
            'statusHistories',
        ])
            ->whereIn('circle_id', $circleIds)
            ->findOrFail($studentId);

        $this->editName = $this->viewingStudent->name;
        $this->editPhone = $this->viewingStudent->phone;
        $this->editCircleId = $this->viewingStudent->circle_id;
        $this->editJoinedAt = $this->viewingStudent->joined_at ? $this->viewingStudent->joined_at->format('Y-m-d') : null;

        $this->stats = [
            'present' => $this->viewingStudent->attendances->where('status', 'present')->count(),
            'absent' => $this->viewingStudent->attendances->where('status', 'absent')->count(),
            'late' => $this->viewingStudent->attendances->where('status', 'late')->count(),
        ];

        Flux::modal('student-details')->show();
    }

    public function saveStudentInfo()
    {
        $this->validate([
            'editName' => 'required|string|min:2|max:255',
            'editPhone' => 'nullable|string|max:20',
            'editJoinedAt' => 'nullable|date',
        ]);

        $this->viewingStudent->update([
            'name' => $this->editName,
            'phone' => $this->editPhone,
            'joined_at' => $this->editJoinedAt,
        ]);

        $this->dispatch('student-list-updated');

        Flux::toast('تم حفظ بيانات الطالب بنجاح', variant: 'success');
    }

    #[\Livewire\Attributes\On('student-status-updated')]
    public function refreshViewingStudent()
    {
        if ($this->viewingStudent) {
            $this->viewingStudent->refresh();
        }
    }

    // ── إجراءات على المحدَّدين ───────────────────────────────────────────────

    /**
     * The selected students, never trusted from the browser: the ids arrive from
     * a page anyone can edit, so they are narrowed to this teacher's circles
     * before anything is written.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Student>
     */
    protected function selectedStudents()
    {
        $circleIds = Auth::guard('teacher')->user()->circles()->pluck('id');

        return Student::whereIn('circle_id', $circleIds)
            ->whereIn('id', array_map('intval', $this->selected))
            ->get();
    }

    public function toggleSelectAll(): void
    {
        $onPage = $this->pageStudentIds();

        $this->selected = count(array_intersect($this->selected, $onPage)) === count($onPage)
            ? []
            : $onPage;
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /**
     * The ids shown on the page as it currently reads, as strings — checkbox
     * values arrive as strings, and comparing them to integers silently fails.
     *
     * @return array<int, string>
     */
    protected function pageStudentIds(): array
    {
        return $this->with()['students']->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function applyBulkStatus(): void
    {
        if (empty(Auth::guard('teacher')->user()->effectivePermissions()['can_change_student_status'])) {
            Flux::toast('ليس لديك صلاحية تغيير حالة الطلاب', variant: 'danger');
            return;
        }

        $this->validate([
            'bulkStatus' => 'required|in:active,registering,suspended,left',
            'bulkStatusDate' => 'nullable|date|before_or_equal:' . now('Asia/Riyadh')->format('Y-m-d'),
        ], [
            'bulkStatusDate.before_or_equal' => 'تاريخ سريان الحالة لا يكون في المستقبل',
        ]);

        $changed = 0;
        $skipped = collect();

        foreach ($this->selectedStudents() as $student) {
            try {
                StudentStatusService::changeStatus($student, $this->bulkStatus, $this->bulkStatusDate ?: null);
                $changed++;
            } catch (\InvalidArgumentException $e) {
                $skipped->push($student->name);
            }
        }

        // Nothing written means nothing is closed or cleared: the selection and
        // the date stay put to be corrected, rather than a cheerful count of zero.
        if ($changed === 0) {
            Flux::toast(
                $skipped->isEmpty()
                    ? 'لم يُحدَّد أي طالب.'
                    : 'لم تتغيّر أي حالة — التاريخ المختار يسبق آخر سجل حالة لهؤلاء: '
                        . $skipped->take(3)->implode('، ') . '. اختر تاريخاً أحدث.',
                variant: 'danger',
            );
            return;
        }

        $this->clearSelection();
        Flux::modal('teacher-bulk-status')->close();

        Flux::toast(
            'تغيّرت حالة ' . $changed . ' طالباً'
                . ($skipped->isNotEmpty() ? '، وتُخطّي ' . $skipped->count() . ' لتعارض التاريخ مع سجلّهم' : '')
                . '.',
            variant: 'success',
        );

        $this->dispatch('student-list-updated');
    }

    public function applyBulkJoinedAt(): void
    {
        if (empty(Auth::guard('teacher')->user()->effectivePermissions()['can_manage_students'])) {
            Flux::toast('ليس لديك صلاحية تعديل بيانات الطلاب', variant: 'danger');
            return;
        }

        $this->validate([
            'bulkJoinedAt' => 'required|date|before_or_equal:' . now('Asia/Riyadh')->format('Y-m-d'),
        ], [
            'bulkJoinedAt.required' => 'اختر تاريخ الالتحاق.',
            'bulkJoinedAt.before_or_equal' => 'تاريخ الالتحاق لا يكون في المستقبل.',
        ]);

        $count = 0;

        foreach ($this->selectedStudents() as $student) {
            $student->update(['joined_at' => $this->bulkJoinedAt]);
            $count++;
        }

        if ($count === 0) {
            Flux::toast('لم يُحدَّد أي طالب.', variant: 'danger');
            return;
        }

        $this->clearSelection();
        Flux::modal('teacher-bulk-joined-at')->close();

        Flux::toast('تغيّر تاريخ التحاق ' . $count . ' طالباً.', variant: 'success');

        $this->dispatch('student-list-updated');
    }

    public function resetToken($studentId)
    {
        $teacher = Auth::guard('teacher')->user();
        $circleIds = $teacher->circles()->pluck('id');

        $student = Student::whereIn('circle_id', $circleIds)->findOrFail($studentId);
        $student->update([
            'access_token' => Str::random(32),
        ]);

        Flux::toast('تم إنشاء رابط جديد للطالب بنجاح', variant: 'success');
    }

    public function with()
    {
        $teacher = Auth::guard('teacher')->user();
        $circles = $teacher->circles;
        $circleIds = $circles->pluck('id');

        $students = Student::whereIn('circle_id', $circleIds)
            ->when($this->search, function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%');
            })
            ->latest()
            ->paginate(20);

        $permissions = $teacher->effectivePermissions();
        $onPage = $students->pluck('id')->map(fn ($id) => (string) $id)->all();


        return [
            'students' => $students,
            'circles' => $circles,
            'canManage' => (bool) ($permissions['can_manage_students'] ?? true),
            'canChangeStatus' => (bool) ($permissions['can_change_student_status'] ?? true),
            'allOnPageSelected' => $onPage !== [] && count(array_intersect($this->selected, $onPage)) === count($onPage),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('إدارة طلاب الحلقة') }}</flux:heading>
            <flux:subheading>{{ __('قم بإنشاء حسابات سريعة لطلابك باستخدام روابط الدخول السحرية وإدارة بياناتهم.') }}
            </flux:subheading>
        </div>

    </div>

    <!-- Quick Create Card -->
    @if(Auth::guard('teacher')->user()?->effectivePermissions()['can_create_students'] ?? true)
    <flux:card>
        {{-- A list rather than a field: teachers arrive with the names already
             written down somewhere, and typing them one at a time — then fixing
             each one's status and join date — was thirty acts for ten students. --}}
        <form wire:submit="createStudent" class="space-y-4">
            @php
                // Built here rather than written inline: a double-quoted string
                // inside a double-quoted attribute closes it, and the placeholder
                // rendered as the literal «{{ __(» with the rest of it leaking
                // into the DOM as stray attributes.
                $namesPlaceholder = "محمد أحمد الغامدي\nعبدالله سعد القحطاني، 0555123456";
            @endphp
            <flux:textarea wire:model.live.debounce.400ms="names" rows="5"
                label="{{ __('أسماء الطلاب — اسم في كل سطر') }}"
                :placeholder="$namesPlaceholder"
                description="{{ __('يمكنك لصق القائمة كما هي. ولإضافة رقم الهاتف اكتبه بعد الاسم وبينهما فاصلة.') }}" />

            <div class="flex flex-col sm:flex-row sm:items-end gap-4">
                <div class="w-full sm:w-64">
                    {{-- The app's own picker rather than <input type="date">: the
                         native one shows Gregorian dates, and under dir="rtl" it
                         draws its own hint backwards — «سنة/شهر/يوم» came out
                         «موي/رهش/ةنس» — while the rest of the app reads Hijri. --}}
                    <livewire:shared.hijri-datepicker wire:model="joinedAt"
                        :label="__('تاريخ الالتحاق — للجميع في هذه الدفعة')"
                        :max-date="now('Asia/Riyadh')->format('Y-m-d')" />
                </div>

                @php
                    $pending = count($this->parsedNames());
                @endphp
                <flux:button type="submit" variant="primary" icon="user-plus" class="min-w-fit w-full sm:w-auto">
                    {{ $pending > 1 ? __('إنشاء :count طلاب', ['count' => App\Support\HijriDate::arabicDigits($pending)]) : __('إنشاء طالب') }}
                </flux:button>
            </div>

            <flux:error name="names" />
            <flux:error name="joinedAt" />

            <p class="text-xs text-zinc-400">
                {{ __('يُضاف الطالب مشاركاً، فيظهر في التحضير من يوم التحاقه مباشرة.') }}
            </p>
        </form>
        <flux:button wire:click="$set('unassignedSearch', '')"
            x-on:click="$flux.modal('unassigned-students-modal').show()" icon="magnifying-glass-plus" class="w-full md:w-auto mt-3">
            {{ __('إضافة طالب غير مرتبط بحلقة') }}
        </flux:button>
    </flux:card>
    @endif

    <flux:card class="p-0 overflow-hidden">
        <div class="p-4 border-b border-zinc-100 dark:border-zinc-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <flux:input wire:model.live="search" icon="magnifying-glass" placeholder="{{ __('بحث باسم الطالب...') }}"
                class="max-w-xs" />

            {{-- Shown only with a selection: an empty bar is a row of buttons
                 that do nothing, which is worse than no bar at all. --}}
            @if (count($selected) > 0)
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('محدَّد: :count', ['count' => App\Support\HijriDate::arabicDigits(count($selected))]) }}
                    </span>

                    @if ($canChangeStatus)
                        <flux:button size="sm" variant="filled" icon="arrow-path"
                            x-on:click="$flux.modal('teacher-bulk-status').show()">
                            {{ __('تغيير الحالة') }}
                        </flux:button>
                    @endif

                    @if ($canManage)
                        <flux:button size="sm" variant="filled" icon="calendar-days"
                            x-on:click="$flux.modal('teacher-bulk-joined-at').show()">
                            {{ __('تاريخ الالتحاق') }}
                        </flux:button>
                    @endif

                    <flux:button size="sm" variant="ghost" wire:click="clearSelection">{{ __('إلغاء التحديد') }}</flux:button>
                </div>
            @endif
        </div>

        @php
            $statusColors = ['active' => 'green', 'registering' => 'blue', 'suspended' => 'amber', 'left' => 'red'];
            $statusLabels = [
                'active' => 'مشارك', 'registering' => 'تحت التسجيل',
                'suspended' => 'موقوف', 'left' => 'غادر الحلقات',
            ];
            $magicLink = fn ($student) => $student->access_token
                ? route('magic-link', ['token' => $student->access_token])
                : null;
        @endphp

        {{-- ═══════════ بطاقات على الجوال ═══════════ --}}
        {{-- A table of eight columns was 743px wide inside a 356px box. It
             scrolled sideways, with nothing to say it did, so the status — the
             one thing a teacher opens this page to read — sat off-screen. --}}
        <div class="sm:hidden divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($students as $student)
                <div wire:key="student-card-{{ $student->id }}" class="flex items-start gap-3 p-4">
                    <input type="checkbox" wire:model.live="selected" value="{{ $student->id }}"
                        class="mt-1 size-4 shrink-0 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500"
                        aria-label="{{ __('تحديد :name', ['name' => $student->name]) }}">

                    <button type="button" class="min-w-0 flex-1 text-start"
                        x-on:click="$flux.modal('student-details').show(); $wire.viewStudent({{ $student->id }})">
                        <div class="flex items-center gap-1.5">
                            <span class="font-medium text-zinc-900 dark:text-zinc-100 truncate">{{ $student->name }}</span>
                            @unless ($student->is_data_completed)
                                <flux:icon icon="clock" class="size-3.5 shrink-0 text-amber-500"
                                    title="{{ __('بيانات غير مكتملة') }}" />
                            @endunless
                        </div>
                        <div class="mt-1.5 flex flex-wrap items-center gap-2">
                            <flux:badge :color="$statusColors[$student->status] ?? 'zinc'" size="sm">
                                {{ $statusLabels[$student->status] ?? $student->status }}
                            </flux:badge>
                            <span class="text-xs text-zinc-400">
                                {{ $student->joined_at ? App\Support\HijriDate::dayMonth($student->joined_at) : '—' }}
                            </span>
                        </div>
                    </button>

                    <div class="flex items-center gap-1 shrink-0">
                        @if ($student->phone)
                            <flux:button as="a" href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $student->phone) }}"
                                target="_blank" size="xs" variant="ghost" icon="chat-bubble-left-ellipsis"
                                class="text-green-600" aria-label="{{ __('واتساب') }}" />
                        @endif
                        <x-student-row-menu :student="$student" :link="$magicLink($student)" />
                    </div>
                </div>
            @empty
                <x-student-roll-empty :search="$search" />
            @endforelse
        </div>

        {{-- ═══════════ جدول على الشاشات الأوسع ═══════════ --}}
        {{-- The visibility classes go on a plain wrapper, not on the Flux
             component: it puts them on an inner element, and the table simply
             never appeared. --}}
        <div class="hidden sm:block">
        <flux:table>
            <flux:table.columns>
                <flux:table.column class="w-10">
                    <input type="checkbox" wire:click="toggleSelectAll" @checked($allOnPageSelected)
                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500"
                        aria-label="{{ __('تحديد الكل') }}">
                </flux:table.column>
                <flux:table.column>{{ __('اسم الطالب') }}</flux:table.column>
                <flux:table.column>{{ __('الحالة') }}</flux:table.column>
                <flux:table.column>{{ __('تاريخ الالتحاق') }}</flux:table.column>
                <flux:table.column>{{ __('واتساب') }}</flux:table.column>
                <flux:table.column class="w-10"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($students as $student)
                    <flux:table.row wire:key="student-row-{{ $student->id }}"
                        class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
                        x-on:click="$flux.modal('student-details').show(); $wire.viewStudent({{ $student->id }})">
                        <flux:table.cell @click.stop="">
                            <input type="checkbox" wire:model.live="selected" value="{{ $student->id }}"
                                class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500"
                                aria-label="{{ __('تحديد :name', ['name' => $student->name]) }}">
                        </flux:table.cell>

                        <flux:table.cell class="font-medium whitespace-nowrap">
                            <span class="inline-flex items-center gap-1.5">
                                {{ $student->name }}
                                {{-- Incomplete paperwork was a second badge in a
                                     column of its own, the same size and shape as
                                     the enrolment status beside it, so neither read
                                     at a glance. It is a mark on the name now. --}}
                                @unless ($student->is_data_completed)
                                    <flux:icon icon="clock" class="size-3.5 text-amber-500"
                                        title="{{ __('بيانات غير مكتملة') }}" />
                                @endunless
                            </span>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge :color="$statusColors[$student->status] ?? 'zinc'" size="sm">
                                {{ $statusLabels[$student->status] ?? $student->status }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell class="whitespace-nowrap text-zinc-500 dark:text-zinc-400">
                            {{ $student->joined_at ? App\Support\HijriDate::dayMonth($student->joined_at) : '—' }}
                        </flux:table.cell>

                        <flux:table.cell @click.stop="">
                            @if ($student->phone)
                                <flux:button as="a"
                                    href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $student->phone) }}"
                                    target="_blank" size="xs" variant="ghost" icon="chat-bubble-left-ellipsis"
                                    class="text-green-600">
                                    {{ __('تواصل') }}
                                </flux:button>
                            @else
                                <span class="text-zinc-300 dark:text-zinc-700">—</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <div @click.stop>
                                <x-student-row-menu :student="$student" :link="$magicLink($student)" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <x-student-roll-empty :search="$search" />
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
        </div>

        <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
            {{ $students->links() }}
        </div>
    </flux:card>

    <!-- Student Details Modal -->
    <flux:modal name="student-details" variant="flyout" class="md:w-[500px]">
        <div wire:loading wire:target="viewStudent" class="w-full h-full flex flex-col items-center justify-center min-h-[300px] text-zinc-400">
            <flux:icon icon="arrow-path" class="size-8 animate-spin mb-4" />
            <p>{{ __('جاري تحميل بيانات الطالب...') }}</p>
        </div>

        <div wire:loading.remove wire:target="viewStudent">
            @if ($viewingStudent)
                <div class="space-y-8">
                <div>
                    <flux:heading size="xl">{{ __('ملف الطالب') }}</flux:heading>
                    <flux:subheading>{{ __('عرض وتعديل بيانات الطالب وخططه') }}</flux:subheading>
                </div>

                <form wire:submit="saveStudentInfo" class="space-y-4">
                    <flux:input wire:model="editName" label="{{ __('اسم الطالب') }}" required />

                    <flux:input wire:model="editPhone" label="{{ __('رقم الهاتف') }}"
                        placeholder="{{ __('اختياري') }}" dir="ltr" class="text-right" />
                        
                    @php
                        $canChangeStatus = Auth::guard('teacher')->user()?->effectivePermissions()['can_change_student_status'] ?? true;
                    @endphp

                    <div class="grid grid-cols-2 gap-4">
                        @if($canChangeStatus)
                        <div>
                            <div class="text-sm font-medium text-zinc-800 dark:text-white mb-1.5">{{ __('حالة الطالب') }}</div>
                            @php
                                $tStatusLabels = ['active' => 'مشارك', 'registering' => 'تحت التسجيل', 'suspended' => 'موقوف', 'left' => 'غادر الحلقات'];
                                $tStatusColors = ['active' => 'green', 'registering' => 'blue', 'suspended' => 'amber', 'left' => 'red'];
                            @endphp
                            <div class="flex items-center gap-2">
                                <flux:badge color="{{ $tStatusColors[$viewingStudent->status] ?? 'zinc' }}">
                                    {{ $tStatusLabels[$viewingStudent->status] ?? $viewingStudent->status }}
                                </flux:badge>
                                <flux:button type="button" size="sm" variant="filled" icon="adjustments-horizontal"
                                    wire:click="$dispatch('open-status-manager', { studentId: {{ $viewingStudent->id }} })">
                                    {{ __('إدارة الحالة') }}
                                </flux:button>
                            </div>
                        </div>
                        <livewire:shared.hijri-datepicker wire:model="editJoinedAt" label="{{ __('تاريخ الالتحاق') }}" />
                        @else
                        <div>
                            <div class="text-xs text-zinc-500 mb-1">{{ __('حالة الطالب') }}</div>
                            <flux:badge size="sm" class="mt-1">{{ $viewingStudent->status }}</flux:badge>
                            <div class="text-[0.65rem] text-zinc-400 mt-1">لا تملك صلاحية التعديل</div>
                        </div>
                        <div>
                            <div class="text-xs text-zinc-500 mb-1">{{ __('تاريخ الالتحاق') }}</div>
                            <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mt-1">
                                {{ $viewingStudent->joined_at?->format('Y-m-d') ?? '—' }}
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

                <!-- Guardian Info -->
                <div>
                    <flux:heading size="sm" class="mb-3">{{ __('ولي الأمر وطرق التواصل') }}</flux:heading>
                    @if ($viewingStudent->guardian)
                        <div class="flex items-center justify-between p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl">
                            <div>
                                <div class="font-medium text-sm">{{ $viewingStudent->guardian->name }}</div>
                                <div class="text-xs text-zinc-500" dir="ltr">
                                    {{ $viewingStudent->guardian->phone ?? 'لا يوجد رقم' }}</div>
                            </div>
                            @if ($viewingStudent->guardian->phone)
                                <flux:button as="a" target="_blank"
                                    href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $viewingStudent->guardian->phone) }}"
                                    size="sm" icon="chat-bubble-left-ellipsis" color="green">
                                    {{ __('واتساب') }}
                                </flux:button>
                            @endif
                        </div>
                    @else
                        <div class="text-sm text-zinc-500 p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl">
                            {{ __('لم يقم الطالب بربط حساب ولي أمر بعد.') }}
                        </div>
                    @endif
                </div>

                <flux:separator />

                <!-- Quran Plans -->
                <div>
                    <flux:heading size="sm" class="mb-3">
                        {{ __('الخطط القرآنية (' . $viewingStudent->plans->count() . ')') }}</flux:heading>

                    <div class="space-y-2 max-h-48 overflow-y-auto pr-2">
                        @forelse($viewingStudent->plans as $plan)
                            <div
                                class="flex items-center justify-between p-3 border border-zinc-200 dark:border-zinc-700/50 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium">
                                        @if ($plan->plan_type === 'hifz_review')
                                            {{ __('حفظ ومراجعة') }}
                                        @elseif($plan->plan_type === 'hifz')
                                            {{ __('حفظ') }}
                                        @else
                                            {{ __('مراجعة') }}
                                        @endif
                                    </span>
                                    <span class="text-xs text-zinc-500"><x-hijri-date :date="$plan->start_date" /> •
                                        {{ $plan->days_count }} يوم</span>
                                </div>
                                <flux:button as="a" href="{{ route('teacher.print-plan', $plan->id) }}"
                                    target="_blank" size="xs" variant="ghost" icon="eye"></flux:button>
                            </div>
                        @empty
                            <div class="text-sm text-zinc-500 text-center py-4">{{ __('ليس لديه خطط مسجلة.') }}</div>
                        @endforelse
                    </div>
                    <div class="mt-3">
                        <flux:button as="a" href="{{ route('teacher.student-recitation-log', $viewingStudent->id) }}" variant="outline" size="sm" icon="clipboard-document-list" class="w-full">
                            {{ __('سجل التسميع الدقيق (الأداء الفعلي)') }}
                        </flux:button>
                    </div>
                </div>

                <flux:separator />

                <!-- Ode Plans -->
                <div>
                    <div class="flex justify-between items-center mb-3">
                        <flux:heading size="sm">
                            {{ __('خطط المنظومات (' . $viewingStudent->odePlans->count() . ')') }}
                        </flux:heading>
                    </div>

                    <div class="space-y-2 max-h-48 overflow-y-auto pr-2">
                        @forelse($viewingStudent->odePlans as $plan)
                            <div class="flex items-center justify-between p-3 border border-zinc-200 dark:border-zinc-700/50 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
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

                <!-- Attendance Stats -->
                <div>
                    <flux:heading size="sm" class="mb-3">{{ __('سجل الحضور والغياب الإجمالي') }}
                    </flux:heading>
                    <div class="grid grid-cols-3 gap-3 text-center">
                        <div
                            class="p-3 bg-green-50 dark:bg-green-500/10 rounded-xl border border-green-100 dark:border-green-500/20">
                            <div class="text-2xl font-bold text-green-600 dark:text-green-500">
                                {{ $stats['present'] ?? 0 }}
                            </div>
                            <div class="text-xs text-green-600/70 dark:text-green-500/70 mt-1">{{ __('حضور') }}
                            </div>
                        </div>
                        <div
                            class="p-3 bg-red-50 dark:bg-red-500/10 rounded-xl border border-red-100 dark:border-red-500/20">
                            <div class="text-2xl font-bold text-red-600 dark:text-red-500">{{ $stats['absent'] ?? 0 }}
                            </div>
                            <div class="text-xs text-red-600/70 dark:text-red-500/70 mt-1">{{ __('غياب') }}</div>
                        </div>
                        <div
                            class="p-3 bg-amber-50 dark:bg-amber-500/10 rounded-xl border border-amber-100 dark:border-amber-500/20">
                            <div class="text-2xl font-bold text-amber-600 dark:text-amber-500">
                                {{ $stats['late'] ?? 0 }}
                            </div>
                            <div class="text-xs text-amber-600/70 dark:text-amber-500/70 mt-1">{{ __('تأخر') }}
                            </div>
                        </div>
                    </div>
                </div>

                <flux:separator />

                <!-- Status History -->
                <div>
                    <flux:heading size="sm" class="mb-3">{{ __('سجل الحالات') }}</flux:heading>
                    <div class="space-y-2 max-h-48 overflow-y-auto pr-2">
                        @forelse($viewingStudent->statusHistories as $history)
                            <div class="flex items-center justify-between p-3 border border-zinc-200 dark:border-zinc-700/50 rounded-xl bg-zinc-50 dark:bg-zinc-800/50">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium">
                                        @php
                                            $hStatusLabels = [
                                                'active' => 'مشارك',
                                                'registering' => 'تحت التسجيل',
                                                'suspended' => 'موقوف',
                                                'left' => 'غادر الحلقات',
                                            ];
                                            $hColor = [
                                                'active' => 'green',
                                                'registering' => 'blue',
                                                'suspended' => 'amber',
                                                'left' => 'red',
                                            ][$history->status] ?? 'zinc';
                                        @endphp
                                        <flux:badge color="{{ $hColor }}" size="sm">{{ $hStatusLabels[$history->status] ?? $history->status }}</flux:badge>
                                    </span>
                                    <span class="text-xs text-zinc-500 mt-1">
                                        <x-hijri-date :date="$history->start_date" /> 
                                        @if($history->end_date)
                                            - <x-hijri-date :date="$history->end_date" />
                                        @else
                                            - {{ __('الآن') }}
                                        @endif
                                    </span>
                                    @if($history->notes)
                                        <span class="text-xs text-zinc-400 mt-1">{{ $history->notes }}</span>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="text-sm text-zinc-500 text-center py-4">{{ __('لا يوجد سجل حالات.') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="flex justify-between pt-6">
                @if(Auth::guard('teacher')->user()?->effectivePermissions()['can_manage_students'] ?? true)
                <flux:button wire:click="removeFromCircle"
                    wire:confirm="{{ __('هل أنت متأكد من إزالة الطالب من الحلقة؟ (لن يتم حذف بياناته، بل سيتم فصله عن حلقتك فقط)') }}"
                    variant="ghost" size="sm" icon="user-minus">{{ __('إزالة من الحلقة') }}</flux:button>
                @endif
            </div>
            @endif
        </div>
    </flux:modal>

    <!-- Unassigned Students Modal -->
    <flux:modal name="unassigned-students-modal" class="md:w-[600px]">
        <flux:heading class="mb-4">{{ __('إضافة طالب للحلقة') }}</flux:heading>

        <flux:input wire:model.live.debounce.300ms="unassignedSearch" icon="magnifying-glass"
            placeholder="ابحث باسم الطالب..." class="mb-4" />

        <div class="space-y-2 max-h-80 overflow-y-auto px-1">
            @forelse($this->unassignedStudents as $unassignedStudent)
                <div
                    class="flex items-center justify-between p-3 border border-zinc-200 dark:border-zinc-700/50 rounded-lg">
                    <div>
                        <div class="font-medium text-sm">{{ $unassignedStudent->name }}</div>
                        <div class="text-xs text-zinc-500">{{ $unassignedStudent->email }}</div>
                    </div>
                    @if(Auth::guard('teacher')->user()?->effectivePermissions()['can_manage_students'] ?? true)
                    <flux:button wire:click="addToCircle({{ $unassignedStudent->id }})" size="sm"
                        icon="plus" variant="primary">{{ __('إضافة') }}</flux:button>
                    @else
                    <flux:badge size="sm" variant="neutral">لا تملك صلاحية</flux:badge>
                    @endif
                </div>
            @empty
                <div class="text-center text-sm text-zinc-500 py-6 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl">
                    {{ __('لا يوجد طلاب غير منضمين لحلقات مطابقين للبحث.') }}
                </div>
            @endforelse
        </div>
    </flux:modal>

    {{-- ─────────── تغيير حالة المحدَّدين ─────────── --}}
    <flux:modal name="teacher-bulk-status" class="md:w-[450px]">
        <form wire:submit="applyBulkStatus" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('تغيير حالة المحدَّدين') }}</flux:heading>
                <flux:subheading>{{ __(':count طالباً', ['count' => App\Support\HijriDate::arabicDigits(count($selected))]) }}</flux:subheading>
            </div>

            <flux:select wire:model="bulkStatus" label="{{ __('الحالة الجديدة') }}">
                <flux:select.option value="active">{{ __('مشارك') }}</flux:select.option>
                <flux:select.option value="registering">{{ __('تحت التسجيل') }}</flux:select.option>
                <flux:select.option value="suspended">{{ __('موقوف') }}</flux:select.option>
                <flux:select.option value="left">{{ __('غادر الحلقات') }}</flux:select.option>
            </flux:select>

            <livewire:shared.hijri-datepicker wire:model="bulkStatusDate"
                :label="__('تاريخ السريان')"
                :max-date="now('Asia/Riyadh')->format('Y-m-d')" />
            <flux:subheading class="-mt-3">{{ __('من هذا اليوم فصاعداً تُحسب الحالة الجديدة في سجل الحضور.') }}</flux:subheading>

            <flux:error name="bulkStatusDate" />

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" type="button"
                    x-on:click="$flux.modal('teacher-bulk-status').close()">{{ __('إلغاء') }}</flux:button>
                <flux:button variant="primary" type="submit">{{ __('تطبيق') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- ─────────── تاريخ التحاق المحدَّدين ─────────── --}}
    <flux:modal name="teacher-bulk-joined-at" class="md:w-[450px]">
        <form wire:submit="applyBulkJoinedAt" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('تاريخ التحاق المحدَّدين') }}</flux:heading>
                <flux:subheading>{{ __(':count طالباً', ['count' => App\Support\HijriDate::arabicDigits(count($selected))]) }}</flux:subheading>
            </div>

            <livewire:shared.hijri-datepicker wire:model="bulkJoinedAt"
                :label="__('تاريخ الالتحاق')"
                :max-date="now('Asia/Riyadh')->format('Y-m-d')" />
            <flux:subheading class="-mt-3">{{ __('لا يمكن تحضير الطالب قبل هذا اليوم.') }}</flux:subheading>

            <flux:error name="bulkJoinedAt" />

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" type="button"
                    x-on:click="$flux.modal('teacher-bulk-joined-at').close()">{{ __('إلغاء') }}</flux:button>
                <flux:button variant="primary" type="submit">{{ __('تطبيق') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <livewire:shared.student-status-manager />
</div>
