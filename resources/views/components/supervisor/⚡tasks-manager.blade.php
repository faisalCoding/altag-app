<?php

use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\AcademicCalendarEvent;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Attributes\Locked;

new class extends Component {
    #[Locked]
    public $selectingDueDateTaskId = null;
    public $hijriYear;
    public $hijriMonth;
    public $taskDueDates = [];

    public function mount()
    {
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $this->hijriYear = $cal->get(\IntlCalendar::FIELD_YEAR);
        $this->hijriMonth = $cal->get(\IntlCalendar::FIELD_MONTH);

        $user = auth()->user();
        $tasks = Task::where(function ($q) use ($user) {
            $q->where('created_by_id', $user->id)
                ->where('created_by_type', get_class($user))
                ->orWhere(function ($sq) use ($user) {
                    $sq->where('assigned_to_id', $user->id)->where('assigned_to_type', get_class($user));
                });
        })
            ->whereNotNull('due_date')
            ->get();

        foreach ($tasks as $task) {
            $this->taskDueDates[$task->id] = $task->due_date->format('Y-m-d');
        }
    }

    public function updatedTaskDueDates($value, $key)
    {
        $this->updateTaskDueDate($key, $value);
    }

    #[Computed]
    public function categories()
    {
        return TaskCategory::all();
    }

    #[Computed]
    public function availableEvents()
    {
        // Only events that have 'has_tasks' enabled
        return AcademicCalendarEvent::visibleTo(auth()->user())
            ->where('has_tasks', true)
            ->get();
    }

    #[Computed]
    public function groupedAvailableEvents()
    {
        $events = $this->availableEvents->sortBy('start_date');
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $grouped = [];

        foreach ($events as $event) {
            $cal->setTime($event->start_date->timestamp * 1000);
            $year = $cal->get(\IntlCalendar::FIELD_YEAR);
            $monthNum = $cal->get(\IntlCalendar::FIELD_MONTH);
            $monthName = \App\Support\HijriDate::format($cal->getTime() / 1000, 'MMMM');

            if (!isset($grouped[$year])) {
                $grouped[$year] = [];
            }
            if (!isset($grouped[$year][$monthNum])) {
                $grouped[$year][$monthNum] = [
                    'name' => $monthName,
                    'events' => [],
                ];
            }

            $grouped[$year][$monthNum]['events'][] = $event;
        }

        return $grouped;
    }

    #[Computed]
    public function assignableUsers()
    {
        $supervisors = \App\Models\Supervisor::all();
        $teachers = \App\Models\Teacher::all();

        return [
            'App\Models\Supervisor' => [
                'label' => 'المشرفون',
                'users' => $supervisors,
            ],
            'App\Models\Teacher' => [
                'label' => 'المعلمون',
                'users' => $teachers,
            ],
        ];
    }

    public function sendTasksToTeachers()
    {
        $user = auth()->user();

        $pendingTasks = Task::with(['category', 'assignedTo'])
            ->where(function ($q) use ($user) {
                $q->where('created_by_id', $user->id)
                    ->where('created_by_type', get_class($user));
            })
            ->where('status', 'pending')
            ->whereIn('assigned_to_type', ['App\Models\Teacher', 'App\Models\Supervisor'])
            ->whereNotNull('assigned_to_id')
            ->get();

        if ($pendingTasks->isEmpty()) {
            Flux::toast('لا توجد مهام قيد الانتظار لمعلمين أو مشرفين لإرسالها.', variant: 'warning');
            return;
        }

        $assigneesTasks = [];
        foreach ($pendingTasks as $task) {
            $assignee = $task->assignedTo;
            if (!$assignee || empty($assignee->phone))
                continue;

            $key = class_basename($assignee) . '_' . $assignee->id;

            if (!isset($assigneesTasks[$key])) {
                $assigneesTasks[$key] = [
                    'assignee' => $assignee,
                    'tasks' => []
                ];
            }
            $assigneesTasks[$key]['tasks'][] = $task;
        }

        if (empty($assigneesTasks)) {
            Flux::toast('لا يوجد مستخدمين لديهم أرقام هواتف مسجلة لإرسال المهام إليهم.', variant: 'warning');
            return;
        }

        $senderClientId = strtolower(class_basename(get_class($user))) . '_' . $user->id;
        \App\Jobs\SendWhatsappTasksJob::dispatch(array_values($assigneesTasks), $senderClientId);

        Flux::toast('تم جدولة إرسال رسائل الواتساب بنجاح!', variant: 'success');
    }

    #[Computed]
    public function groupedTasks()
    {
        $user = auth()->user();
        $allTasks = Task::with(['category', 'events', 'assignedTo', 'createdBy'])
            ->where(function ($q) use ($user) {
                $q->where('created_by_id', $user->id)
                    ->where('created_by_type', get_class($user))
                    ->orWhere(function ($sq) use ($user) {
                        $sq->where('assigned_to_id', $user->id)->whereIn('assigned_to_type', ['App\Models\Teacher', 'App\Models\Supervisor']);
                    });
            })
            ->latest()
            ->get();

        $groups = [
            'general' => [
                'event' => null,
                'categories' => [
                    'uncategorized' => [
                        'category' => null,
                        'tasks' => [],
                    ],
                ],
            ],
        ];

        // Get IDs of events that actually have tasks
        $eventIdsWithTasks = [];
        foreach ($allTasks as $task) {
            foreach ($task->events as $event) {
                $eventIdsWithTasks[] = $event->id;
            }
        }
        $eventIdsWithTasks = array_unique($eventIdsWithTasks);

        // Ensure we load the categories that belong to events
        $events = AcademicCalendarEvent::whereIn('id', $eventIdsWithTasks)->with('taskCategories')->get();
        foreach ($events as $event) {
            $groups[$event->id] = [
                'event' => $event,
                'categories' => [
                    'uncategorized' => [
                        'category' => null,
                        'tasks' => [],
                    ],
                ],
            ];
            foreach ($event->taskCategories as $cat) {
                $groups[$event->id]['categories'][$cat->id] = [
                    'category' => $cat,
                    'tasks' => [],
                ];
            }
        }

        // Capture global categories for general block
        $globalCats = TaskCategory::whereNull('event_id')->where('created_by_id', $user->id)->get();
        foreach ($globalCats as $cat) {
            $groups['general']['categories'][$cat->id] = [
                'category' => $cat,
                'tasks' => [],
            ];
        }

        foreach ($allTasks as $task) {
            $catId = $task->task_category_id;
            if ($task->events->isEmpty()) {
                if ($catId && isset($groups['general']['categories'][$catId])) {
                    $groups['general']['categories'][$catId]['tasks'][] = $task;
                } else {
                    $groups['general']['categories']['uncategorized']['tasks'][] = $task;
                }
            } else {
                foreach ($task->events as $event) {
                    if (!isset($groups[$event->id])) {
                        $groups[$event->id] = [
                            'event' => $event,
                            'categories' => [
                                'uncategorized' => [
                                    'category' => null,
                                    'tasks' => [],
                                ],
                            ],
                        ];
                    }
                    if ($catId && isset($groups[$event->id]['categories'][$catId])) {
                        $groups[$event->id]['categories'][$catId]['tasks'][] = $task;
                    } elseif ($catId && $task->category) {
                        if (!isset($groups[$event->id]['categories'][$catId])) {
                            $groups[$event->id]['categories'][$catId] = [
                                'category' => $task->category,
                                'tasks' => [],
                            ];
                        }
                        $groups[$event->id]['categories'][$catId]['tasks'][] = $task;
                    } else {
                        $groups[$event->id]['categories']['uncategorized']['tasks'][] = $task;
                    }
                }
            }
        }

        $eventGroups = collect($groups)
            ->except(['general'])
            ->sortBy(function ($group) {
                return $group['event']->start_date;
            })
            ->values()
            ->all();

        return array_merge([$groups['general']], $eventGroups);
    }

    public function addGroupForEvent($eventId)
    {
        $user = auth()->user();
        $task = Task::create([
            'title' => 'المهمة الأولى',
            'status' => 'pending',
            'created_by_id' => $user->id,
            'created_by_type' => get_class($user),
        ]);

        $task->events()->sync([$eventId]);
        Flux::toast('تم إضافة مجموعة الحدث وإنشاء المهمة الأولى', variant: 'success');
    }

    public function addTaskInline($title, $eventId = null, $categoryId = null)
    {
        if (empty(trim($title))) {
            return;
        }

        $user = auth()->user();
        $task = Task::create([
            'title' => trim($title),
            'status' => 'pending',
            'task_category_id' => $categoryId ?: null,
            'created_by_id' => $user->id,
            'created_by_type' => get_class($user),
        ]);

        if ($eventId) {
            $task->events()->sync([$eventId]);
        }
    }

    public function updateTaskTitle($taskId, $title)
    {
        if (empty(trim($title))) {
            return;
        }
        $task = Task::findOrFail($taskId);
        if ($this->canEdit($task)) {
            $task->update(['title' => $title]);
        }
    }

    public function updateTaskStatus($taskId, $status)
    {
        $task = Task::findOrFail($taskId);
        if ($this->canEdit($task) || $this->isAssignee($task)) {
            $task->update(['status' => $status]);
        }
    }

    public function updateTaskAssignee($taskId, $type, $id)
    {
        // The type is stored as a morph class and later instantiated, so it
        // may only be one the picker offers, naming someone it lists.
        if ($type || $id) {
            $group = $this->assignableUsers()[$type] ?? null;

            if (! $group || ! $group['users']->contains('id', (int) $id)) {
                return;
            }
        }

        $task = Task::findOrFail($taskId);
        if ($this->canEdit($task)) {
            $task->update([
                'assigned_to_type' => $type ?: null,
                'assigned_to_id' => $id ?: null,
            ]);

            // Auto-share events
            if ($type && $id) {
                foreach ($task->events as $event) {
                    $sw = $event->shared_with ?? [];
                    if ($type === 'App\Models\Teacher') {
                        $teachers = $sw['teacher_ids'] ?? [];
                        if (!in_array($id, $teachers)) {
                            $teachers[] = (int) $id;
                            $sw['teacher_ids'] = $teachers;
                            $event->update(['shared_with' => $sw]);
                        }
                    } elseif ($type === 'App\Models\Supervisor') {
                        $supervisors = $sw['supervisor_ids'] ?? [];
                        if (!in_array($id, $supervisors)) {
                            $supervisors[] = (int) $id;
                            $sw['supervisor_ids'] = $supervisors;
                            $event->update(['shared_with' => $sw]);
                        }
                    }
                }
            }
        }
    }

    public function updateTaskDueDate($taskId, $date)
    {
        $task = Task::findOrFail($taskId);
        if ($this->canEdit($task)) {
            $task->update(['due_date' => $date ?: null]);
        }
    }

    public function syncTaskEvents($taskId, $eventIds)
    {
        $task = Task::findOrFail($taskId);
        if ($this->canEdit($task)) {
            $task->events()->sync($eventIds);
        }
    }

    public function updateTaskDetails($taskId, $description, $categoryId)
    {
        $task = Task::findOrFail($taskId);
        if ($this->canEdit($task)) {
            $task->update([
                'description' => $description,
                'task_category_id' => $categoryId ?: null,
            ]);
        }
    }

    public function createCategory($name, $color, $icon = 'tag', $eventId = null)
    {
        if (empty(trim($name))) {
            return;
        }

        $user = auth()->user();
        TaskCategory::create([
            'name' => trim($name),
            'color' => $color,
            'icon' => $icon,
            'event_id' => $eventId,
            'created_by_id' => $user->id,
            'created_by_type' => get_class($user),
        ]);
        Flux::toast('تم إضافة التصنيف بنجاح', variant: 'success');
    }

    public function updateCategoryName($categoryId, $name)
    {
        if (empty(trim($name))) {
            return;
        }
        $category = TaskCategory::findOrFail($categoryId);
        $user = auth()->user();
        if ($category->created_by_id === $user->id && $category->created_by_type === get_class($user)) {
            $category->update(['name' => trim($name)]);
        }
    }

    public function deleteCategory($categoryId)
    {
        $category = TaskCategory::findOrFail($categoryId);
        $user = auth()->user();
        if ($category->created_by_id === $user->id && $category->created_by_type === get_class($user)) {
            Task::where('task_category_id', $categoryId)->update(['task_category_id' => null]);
            $category->delete();
            Flux::toast('تم حذف التصنيف، وبقيت المهام متوفرة', variant: 'success');
        }
    }

    public function deleteTask($taskId)
    {
        $task = Task::findOrFail($taskId);
        if ($this->canEdit($task)) {
            $task->delete();
        }
    }

    public function openDueDateModal($taskId)
    {
        $this->selectingDueDateTaskId = $taskId;
        Flux::modal('due-date-modal')->show();
    }

    public function selectDueDate($date)
    {
        if ($this->selectingDueDateTaskId) {
            $this->updateTaskDueDate($this->selectingDueDateTaskId, $date);
            $this->selectingDueDateTaskId = null;
            Flux::modal('due-date-modal')->close();
        }
    }

    public function changeMonth($offset)
    {
        $this->hijriMonth += $offset;
        if ($this->hijriMonth > 11) {
            $this->hijriMonth = 0;
            $this->hijriYear++;
        } elseif ($this->hijriMonth < 0) {
            $this->hijriMonth = 11;
            $this->hijriYear--;
        }
    }

    #[Computed]
    public function calendarData()
    {
        $allEvents = AcademicCalendarEvent::visibleTo(auth()->user())->get();
        return $this->getMonthData($this->hijriYear, $this->hijriMonth, $allEvents);
    }

    private function getMonthData($year, $monthIndex, $allEvents)
    {
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->set(\IntlCalendar::FIELD_YEAR, $year);
        $cal->set(\IntlCalendar::FIELD_MONTH, $monthIndex);
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);

        $monthLength = $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH);
        $startDayOfWeek = $cal->get(\IntlCalendar::FIELD_DAY_OF_WEEK); // 1 = Sunday

        $monthName = \App\Support\HijriDate::format($cal->getTime() / 1000, 'MMMM');

        // Assign fixed slots to events for this month to ensure visual continuity
        $slottedEvents = [];
        $occupiedUntil = []; // slot_index => date string

        $sortedEvents = $allEvents->sortBy([['start_date', 'asc'], ['end_date', 'desc']]);

        foreach ($sortedEvents as $event) {
            $assignedSlot = 0;
            $eventStart = $event->start_date->format('Y-m-d');
            while (isset($occupiedUntil[$assignedSlot]) && $occupiedUntil[$assignedSlot] >= $eventStart) {
                $assignedSlot++;
            }
            if ($assignedSlot < 8) {
                // Max 8 slots
                $occupiedUntil[$assignedSlot] = $event->end_date->format('Y-m-d');
                $event->slot_index = $assignedSlot;
                $slottedEvents[] = $event;
            }
        }
        $allEvents = collect($slottedEvents);

        $days = [];
        $emptySlots = $startDayOfWeek - 1;

        for ($i = 0; $i < $emptySlots; $i++) {
            $days[] = null;
        }

        $colorMap = [
            'indigo' => 'bg-indigo-500/70',
            'blue' => 'bg-blue-500/70',
            'green' => 'bg-green-500/70',
            'emerald' => 'bg-emerald-500/70',
            'zinc' => 'bg-zinc-500/70',
            'orange' => 'bg-orange-500/70',
            'sky' => 'bg-sky-500/70',
            'rose' => 'bg-rose-500/70',
            'amber' => 'bg-amber-500/70',
            'teal' => 'bg-teal-500/70',
            'red' => 'bg-red-500/70',
            'lime' => 'bg-lime-500/70',
        ];

        for ($i = 1; $i <= $monthLength; $i++) {
            $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $i);
            $dayOfWeek = $cal->get(\IntlCalendar::FIELD_DAY_OF_WEEK);
            $dayTimestamp = $cal->getTime() / 1000;
            $gregDate = date('Y-m-d', $dayTimestamp);

            $currentDayEvents = $allEvents->filter(function ($event) use ($gregDate, $dayOfWeek) {
                $dateMatch = $gregDate >= $event->start_date->format('Y-m-d') && $gregDate <= $event->end_date->format('Y-m-d');

                if (!$dateMatch) {
                    return false;
                }

                if ($event->is_attendance_period && $event->weekdays) {
                    return in_array($dayOfWeek, $event->weekdays);
                }

                return true;
            });

            $colorClass = 'bg-white hover:bg-zinc-50 dark:bg-zinc-800 dark:hover:bg-zinc-700';
            $dayLabels = [];
            $dayColors = array_fill(0, 8, null);

            if ($currentDayEvents->isNotEmpty()) {
                foreach ($currentDayEvents as $event) {
                    $color = $event->color ?: 'indigo';
                    $dayColors[$event->slot_index] = $colorMap[$color] ?? $colorMap['indigo'];
                }

                $labelingEvents = $currentDayEvents->filter(fn($e) => $gregDate === $e->start_date->format('Y-m-d') || $gregDate === $e->end_date->format('Y-m-d'));

                foreach ($labelingEvents as $event) {
                    $dayLabels[$event->slot_index] = $event->event_name;
                }

                $colorClass = 'bg-zinc-50 dark:bg-zinc-800/80';
            }

            $days[] = [
                'hijriDay' => $i,
                'gregorianDate' => $gregDate,
                'colorClass' => $colorClass,
                'dayColors' => $dayColors,
                'dayLabels' => $dayLabels,
                'hasMultipleEvents' => $currentDayEvents->count() > 1,
                'isToday' => $gregDate === date('Y-m-d'),
            ];
        }

        $maxMonthSlot = 0;
        foreach ($slottedEvents as $event) {
            if ($event->slot_index > $maxMonthSlot) {
                $maxMonthSlot = $event->slot_index;
            }
        }

        return [
            'monthName' => $monthName,
            'days' => $days,
            'maxMonthSlot' => $maxMonthSlot,
        ];
    }

    private function canEdit($task)
    {
        $user = auth()->user();
        return $task->created_by_id === $user->id && $task->created_by_type === get_class($user);
    }

    private function isAssignee($task)
    {
        $user = auth()->user();
        return $task->assigned_to_id === $user->id && $task->assigned_to_type === get_class($user);
    }
    public function sendReminderTasksToTeachers()
    {
        $user = auth()->user();
        $today = \Carbon\Carbon::today()->format('Y-m-d');

        $pendingTasks = Task::with(['assignedTo'])
            ->where(function ($q) use ($user) {
                $q->where('created_by_id', $user->id)
                    ->where('created_by_type', get_class($user));
            })
            ->where('status', 'pending')
            ->whereIn('assigned_to_type', ['App\Models\Teacher', 'App\Models\Supervisor'])
            ->whereNotNull('assigned_to_id')
            ->whereDate('due_date', '>=', $today)
            ->get();

        if ($pendingTasks->isEmpty()) {
            Flux::toast('لا توجد مهام قيد الانتظار لم يحن موعدها لإرسال رسالة تذكير.', variant: 'warning');
            return;
        }

        $assigneesTasks = [];
        foreach ($pendingTasks as $task) {
            $assignee = $task->assignedTo;
            if (!$assignee || empty($assignee->phone))
                continue;

            $key = class_basename($assignee) . '_' . $assignee->id;

            if (!isset($assigneesTasks[$key])) {
                $assigneesTasks[$key] = [
                    'assignee' => $assignee,
                    'tasks' => []
                ];
            }
            $assigneesTasks[$key]['tasks'][] = $task;
        }

        if (empty($assigneesTasks)) {
            Flux::toast('لا يوجد مستخدمين لديهم أرقام هواتف مسجلة لإرسال التذكير إليهم.', variant: 'warning');
            return;
        }

        $senderClientId = strtolower(class_basename(get_class($user))) . '_' . $user->id;
        \App\Jobs\SendWhatsappTasksJob::dispatch(array_values($assigneesTasks), $senderClientId, 'reminder');

        Flux::toast('تم جدولة إرسال رسائل التذكير عبر الواتساب بنجاح!', variant: 'success');
    }
};
?>

<div class="space-y-8 pb-20 text-zinc-800 dark:text-zinc-200" dir="rtl">
    {{-- Top Bar --}}
    <div
        class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-zinc-200 dark:border-zinc-800 pb-4 mb-6">
        <h2 class="text-2xl font-bold">إدارة المهام</h2>
        <div class="flex flex-col sm:flex-row w-full md:w-auto items-stretch sm:items-center gap-3 sm:gap-4">
            <x-flux::button wire:click="sendReminderTasksToTeachers" icon="bell"
                class="!bg-amber-500 hover:!bg-amber-600 !text-white border-0 w-full sm:w-auto min-h-[44px] sm:min-h-0"
                size="sm">
                تذكير بالمهام (WhatsApp)
            </x-flux::button>
            <x-flux::button wire:click="sendTasksToTeachers" icon="chat-bubble-left-right"
                class="!bg-emerald-500 hover:!bg-emerald-600 !text-white border-0 w-full sm:w-auto min-h-[44px] sm:min-h-0"
                size="sm">
                إرسال المهام (WhatsApp)
            </x-flux::button>
        </div>
    </div>

    <div class="space-y-10">
        @foreach ($this->groupedTasks as $group)
            @php
                $isGeneral = $group['event'] === null;
                $categories = $group['categories'] ?? [];
            @endphp

            <div class="space-y-4 bg-gray-500/15 p-5 rounded-2xl " wire:key="group-{{ $group['event']->id ?? 'general' }}">
                {{-- Group Header --}}
                <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-2">
                    <div class="flex items-center gap-2">
                        @if ($isGeneral)
                            <flux:icon icon="inbox" class="size-5 text-indigo-500" />
                            <h3 class="font-bold text-lg">المهام العامة</h3>
                        @else
                            <div class="size-3 rounded-full bg-{{ $group['event']->color ?? 'zinc' }}-500"></div>
                            <h3 class="font-bold text-lg">{{ $group['event']->event_name }}</h3>
                            <span class="text-xs text-zinc-500 bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 rounded-full">
                                <x-hijri-date :date="$group['event']->start_date" />
                            </span>
                        @endif
                    </div>

                    {{-- Add Category --}}
                    <div x-data="{ isAddingCat: false, newCatName: '', newCatColor: 'indigo', newCatIcon: 'tag' }"
                        wire:key="add-category-{{ $group['event']->id ?? 'general' }}" class="relative">
                        <flux:button size="sm" variant="ghost" @click="isAddingCat = !isAddingCat" icon="plus"
                            class="text-zinc-500 text-xs">إضافة تصنيف</flux:button>

                        <div x-show="isAddingCat" x-transition style="display: none;"
                            class="absolute z-[100] left-0 top-full mt-2 w-[320px] sm:w-[420px] bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-xl shadow-xl p-5 space-y-5"
                            @click.away="isAddingCat = false">

                            <div
                                class="flex justify-between items-center border-b border-zinc-100 dark:border-zinc-800 pb-2">
                                <span class="font-bold text-zinc-700 dark:text-zinc-200">إضافة تصنيف جديد</span>
                                <button @click="isAddingCat = false" type="button"
                                    class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300">
                                    <flux:icon icon="x-mark" class="size-5" />
                                </button>
                            </div>

                            <div>
                                <flux:label class="text-sm font-bold mb-2">اسم التصنيف</flux:label>
                                <input type="text" x-model="newCatName" placeholder="اكتب اسم التصنيف هنا..."
                                    class="w-full text-sm py-2 px-3 border-zinc-300 dark:border-zinc-700 rounded-md shadow-sm bg-white dark:bg-zinc-900 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div>
                                <flux:label class="text-sm font-bold mb-2">اللون</flux:label>
                                <div class="flex flex-wrap gap-2">
                                    @foreach (['indigo', 'blue', 'emerald', 'amber', 'rose', 'purple', 'zinc', 'red', 'orange', 'green', 'teal', 'cyan'] as $color)
                                        <button @click="newCatColor = '{{ $color }}'" type="button"
                                            class="size-6 sm:size-7 rounded-full bg-{{ $color }}-500 border-2 transition-transform shadow-sm"
                                            :class="newCatColor === '{{ $color }}' ?
                                                                                                                                        'border-zinc-800 dark:border-white scale-110' : 'border-transparent'">
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            <div>
                                <flux:label class="text-sm font-bold mb-2">الأيقونة</flux:label>
                                <div class="grid grid-cols-6 sm:grid-cols-8 gap-2 max-h-48 overflow-y-auto pr-1">
                                    @foreach (['tag', 'star', 'bookmark', 'folder', 'briefcase', 'academic-cap', 'sparkles', 'bolt', 'bell', 'calendar', 'clipboard-document-check', 'clock', 'cloud', 'cpu-chip', 'document-text', 'exclamation-circle', 'fire', 'flag', 'globe-alt', 'heart', 'key', 'light-bulb', 'map-pin', 'paper-clip', 'pencil', 'rocket-launch', 'shield-check', 'trophy', 'users', 'check-badge'] as $icon)
                                        <button @click="newCatIcon = '{{ $icon }}'" type="button"
                                            class="p-2 flex items-center justify-center rounded-lg   s border"
                                            :class="newCatIcon === '{{ $icon }}' ?
                                                                                                                                        'bg-indigo-50 dark:bg-indigo-900/50 border-indigo-500 text-indigo-600 dark:text-indigo-400' :
                                                                                                                                        'border-zinc-200 dark:border-zinc-700 text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800'">
                                            <flux:icon icon="{{ $icon }}" class="size-5" />
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            <flux:button size="sm" variant="primary" class="w-full mt-2"
                                @click="$wire.createCategory(newCatName, newCatColor, newCatIcon, {{ $isGeneral ? 'null' : $group['event']->id }}).then(() => { isAddingCat = false; newCatName = ''; })">
                                حفظ التصنيف</flux:button>
                        </div>
                    </div>
                </div>

                {{-- Categories Loop --}}
                <div class="space-y-6">
                    @foreach ($categories as $catId => $catData)
                        @php
                            $category = $catData['category'];
                            $tasks = $catData['tasks'];

                            // Hide uncategorized if empty and it's an event
                            if ($catId === 'uncategorized' && empty($tasks) && !$isGeneral) {
                                continue;
                            }
                        @endphp

                        <div wire:key="group-{{ $group['event']->id ?? 'general' }}-cat-{{ $catId }}"
                            class="space-y-1 {{ $catId !== 'uncategorized' ? 'pr-3 border-r-2 border-' . ($category->color ?? 'zinc') . '-200 dark:border-' . ($category->color ?? 'zinc') . '-800/50' : '' }}">
                            @if ($catId !== 'uncategorized')
                                <div x-data="{
                                                                                                                                                                        isHovered: false,
                                                                                                                                                                        isEditingCat: false,
                                                                                                                                                                        catName: '{{ addslashes($category->name) }}',
                                                                                                                                                                        save() {
                                                                                                                                                                            if (this.catName.trim() !== '' && this.catName.trim() !== '{{ addslashes($category->name) }}') {
                                                                                                                                                                                $wire.updateCategoryName({{ $category->id }}, this.catName);
                                                                                                                                                                            } else {
                                                                                                                                                                                this.catName = '{{ addslashes($category->name) }}';
                                                                                                                                                                            }
                                                                                                                                                                            this.isEditingCat = false;
                                                                                                                                                                        }
                                                                                                                                                                    }"
                                    @mouseenter="isHovered = true" @mouseleave="isHovered = false"
                                    class="group flex items-center justify-between text-sm font-bold text-{{ $category->color ?? 'zinc' }}-600 dark:text-{{ $category->color ?? 'zinc' }}-400 mb-2">

                                    <div class="flex items-center justify-center gap-2 flex-1 min-w-0">
                                        <flux:icon icon="{{ $category->icon ?? 'tag' }}" class="size-5 shrink-0" />

                                        <div class="flex-1 cursor-pointer min-w-0" @click="isEditingCat = true"
                                            x-show="!isEditingCat">
                                            <span
                                                class="truncate flex items-center text-[15px] border-b border-transparent hover:border-{{ $category->color ?? 'zinc' }}-400   s pb-0.5">
                                                {{ $category->name }}
                                            </span>
                                        </div>

                                        <input x-show="isEditingCat" x-model="catName" @keydown.enter="save()"
                                            @keydown.escape="isEditingCat = false; catName = '{{ addslashes($category->name) }}'"
                                            @blur="save()"
                                            x-init="$watch('isEditingCat', val => { if (val) setTimeout(() => { $el.focus() }, 50) })"
                                            class="flex-1 bg-transparent border-b border-{{ $category->color ?? 'zinc' }}-500 focus:outline-none py-0.5 px-0 min-w-0"
                                            style="display: none;" />
                                    </div>

                                    <div class="flex items-center gap-1 shrink-0 transition-opacity"
                                        :class="isHovered ? 'opacity-100' : 'opacity-0 lg:opacity-0 opacity-100'">
                                        <x-flux::button variant="ghost" size="sm" class="!px-1.5 text-zinc-400 hover:text-red-600"
                                            wire:click="deleteCategory({{ $category->id }})"
                                            wire:confirm="هل أنت متأكد من حذف هذا التصنيف؟ لن يتم حذف المهام بداخله بل ستصبح غير مصنفة."
                                            x-tooltip="'حذف التصنيف'">
                                            <flux:icon icon="trash" class="size-3.5" />
                                        </x-flux::button>
                                    </div>
                                </div>
                            @endif

                            {{-- Tasks List --}}
                            <div class="space-y-1">
                                @foreach ($tasks as $task)
                                    <div x-data="{
                                                                                                                                                                            isHovered: false,
                                                                                                                                                                            isEditing: false,
                                                                                                                                                                            title: '{{ addslashes($task->title) }}',
                                                                                                                                                                            save() {
                                                                                                                                                                                if (this.title.trim() !== '{{ addslashes($task->title) }}') {
                                                                                                                                                                                    $wire.updateTaskTitle({{ $task->id }}, this.title);
                                                                                                                                                                                }
                                                                                                                                                                                this.isEditing = false;
                                                                                                                                                                            }
                                                                                                                                                                        }"
                                        @mouseenter="isHovered = true" @mouseleave="isHovered = false"
                                        wire:key="task-{{ $task->id }}-group-{{ $group['event']->id ?? 'general' }}"
                                        class="group flex items-center justify-between py-2 border-b border-transparent hover:border-zinc-100 dark:hover:border-zinc-800   s">
                                        {{-- Right side: Status and Title --}}
                                        <div class="flex items-center gap-3 flex-1 min-w-0">
                                            <button
                                                wire:click="updateTaskStatus({{ $task->id }}, '{{ $task->status === 'completed' ? 'pending' : 'completed' }}')"
                                                class="shrink-0 flex items-center justify-center size-5 rounded-full border-2   s focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 dark:focus:ring-offset-zinc-900 {{ $task->status === 'completed' ? 'bg-emerald-500 border-emerald-500 text-white' : 'border-zinc-300 dark:border-zinc-600 text-transparent hover:border-emerald-400' }}">
                                                <flux:icon icon="check" class="size-3" />
                                            </button>

                                            <div class="flex-1 cursor-pointer min-w-0" @click="isEditing = true"
                                                x-show="!isEditing">
                                                <span
                                                    class="truncate block text-[18px] font-medium {{ $task->status === 'completed' ? 'line-through text-zinc-400 dark:text-zinc-500' : 'text-zinc-800 dark:text-zinc-200' }}">
                                                    {{ $task->title }}
                                                </span>
                                                @if ($task->assignedTo)
                                                    <div class="flex items-center gap-1 text-[16px] text-zinc-500 mt-0.5" @click.stop>
                                                        <flux:icon icon="user" class="size-5" />
                                                        <span>{{ $task->assignedTo->name }}</span>
                                                    </div>
                                                @endif
                                            </div>

                                            <input x-show="isEditing" x-model="title" x-ref="titleInput" @keydown.enter="save()"
                                                @keydown.escape="isEditing = false; title = '{{ addslashes($task->title) }}'"
                                                @blur="save()"
                                                class="flex-1 bg-transparent border-b border-indigo-500 focus:outline-none text-sm font-medium py-1 px-0 min-w-0 text-zinc-800 dark:text-zinc-200"
                                                style="display: none;" />
                                        </div>

                                        {{-- Left side: Actions (Visible on hover or mobile) --}}
                                        <div class="flex items-center gap-1 shrink-0 transition-opacity"
                                            :class="isHovered ? 'opacity-100' : 'opacity-0 lg:opacity-0'">

                                            {{-- Assignee --}}
                                            <x-flux::dropdown>
                                                <x-flux::button variant="ghost" size="sm"
                                                    class="!px-2 text-zinc-400 hover:text-indigo-600" x-tooltip="'إسناد المهمة'">
                                                    <flux:icon icon="user" class="size-4" />
                                                    @if ($task->assignedTo)
                                                        <span
                                                            class="text-xs mr-1 truncate max-w-[60px]">{{ explode(' ', $task->assignedTo->name)[0] }}</span>
                                                    @endif
                                                </x-flux::button>
                                                <x-flux::menu class="w-64 max-h-60 overflow-y-auto">
                                                    <div class="px-2 py-1 text-xs font-semibold text-zinc-500">اختر
                                                        المسؤول</div>
                                                    <x-flux::menu.item
                                                        wire:click="updateTaskAssignee({{ $task->id }}, null, null)">أنا
                                                        (المنشئ)
                                                    </x-flux::menu.item>
                                                    <x-flux::menu.separator />
                                                    @foreach ($this->assignableUsers as $type => $groupData)
                                                        <div class="px-2 py-1 text-xs font-bold text-zinc-800 dark:text-zinc-200">
                                                            {{ $groupData['label'] }}
                                                        </div>
                                                        @foreach ($groupData['users'] as $u)
                                                            <x-flux::menu.item
                                                                wire:click="updateTaskAssignee({{ $task->id }}, '{{ addslashes($type) }}', {{ $u->id }})">
                                                                {{ $u->name }}
                                                            </x-flux::menu.item>
                                                        @endforeach
                                                    @endforeach
                                                </x-flux::menu>
                                            </x-flux::dropdown>

                                            {{-- Due Date --}}
                                            <div>
                                                <x-flux::button wire:click="openDueDateModal({{ $task->id }})" variant="ghost"
                                                    size="sm" class="!px-2 text-zinc-400 hover:text-rose-600"
                                                    x-tooltip="'تاريخ الإتمام'">
                                                    <flux:icon icon="calendar" class="size-4" />
                                                    @if ($task->due_date)
                                                        <span
                                                            class="text-xs mr-1 {{ $task->due_date->isPast() && $task->status !== 'completed' ? 'text-red-500 font-bold' : '' }}"><x-hijri-date :date="$task->due_date" style="dayMonth" /></span>
                                                    @endif
                                                </x-flux::button>
                                            </div>

                                            {{-- Events Link --}}
                                            <div class="relative"
                                                wire:key="task-events-{{ $task->id }}-group-{{ $group['event']->id ?? 'general' }}"
                                                x-data="{ open: false, selected: {{ $task->events->first() ? $task->events->first()->id : 'null' }}, expandedYear: {{ $hijriYear }}, expandedMonth: {{ $hijriMonth }} }"
                                                @click.away="open = false">
                                                <x-flux::button @click="open = !open" variant="ghost" size="sm"
                                                    class="!px-2 text-zinc-400 hover:text-amber-600" x-tooltip="'ربط بحدث'">
                                                    <flux:icon icon="ticket" class="size-4" />
                                                </x-flux::button>

                                                <div x-show="open" x-transition style="display: none;"
                                                    class="absolute z-[100] top-full mt-2 left-0 w-[320px] sm:w-[450px] max-h-[500px] overflow-y-auto bg-white dark:bg-zinc-900 rounded-xl shadow-xl border border-zinc-200 dark:border-zinc-700"
                                                    dir="rtl">
                                                    <div
                                                        class="px-4 py-3 text-sm font-semibold text-zinc-600 dark:text-zinc-300 sticky top-0 bg-white dark:bg-zinc-900 z-10 border-b border-zinc-100 dark:border-zinc-800 flex justify-between items-center">
                                                        <span>اختر الأحداث</span>
                                                        <button @click="open = false" type="button"
                                                            class="p-1 rounded-md text-zinc-400 hover:text-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800   s">
                                                            <flux:icon icon="x-mark" class="size-5" />
                                                        </button>
                                                    </div>

                                                    @forelse($this->groupedAvailableEvents as $year => $months)
                                                        <div class="mt-1">
                                                            <div
                                                                class="px-4 py-2 text-sm font-bold text-indigo-700 dark:text-indigo-400 bg-indigo-50/80 dark:bg-indigo-900/30">
                                                                عام {{ $year }}
                                                            </div>
                                                            @foreach ($months as $monthNum => $monthData)
                                                                <div class="border-b border-zinc-100 dark:border-zinc-800/50 last:border-0">
                                                                    <button type="button"
                                                                        @click="expandedYear = {{ $year }}; expandedMonth = expandedMonth === {{ $monthNum }} ? null : {{ $monthNum }}"
                                                                        class="w-full flex items-center justify-between px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/50   s">
                                                                        <span
                                                                            class="text-sm font-bold text-zinc-800 dark:text-zinc-200">{{ $monthData['name'] }}</span>
                                                                        <flux:icon icon="chevron-down"
                                                                            class="size-4 text-zinc-400 transition-transform"
                                                                            x-bind:class="expandedYear === {{ $year }} &&
                                                                                                                                                                                                                                                                                                            expandedMonth === {{ $monthNum }} ?
                                                                                                                                                                                                                                                                                                            'rotate-180' : ''" />
                                                                    </button>

                                                                    <div
                                                                        x-show="expandedYear === {{ $year }} && expandedMonth === {{ $monthNum }}">
                                                                        <div
                                                                            class="pl-4 pr-3 py-2 bg-zinc-50/50 dark:bg-zinc-800/20 space-y-1">
                                                                            @foreach ($monthData['events'] as $e)
                                                                                <div class="flex items-center gap-3 px-3 py-2.5 hover:bg-white dark:hover:bg-zinc-800 cursor-pointer rounded-lg border border-transparent hover:border-zinc-200 dark:hover:border-zinc-700 hover:shadow-sm  "
                                                                                    @click="selected = (selected === {{ $e->id }}) ? null : {{ $e->id }}; $wire.syncTaskEvents({{ $task->id }}, selected ? [selected] : []);">
                                                                                    <input type="radio"
                                                                                        :checked="selected ===
                                                                                                                                                                                                                                                                                                                                                                    {{ $e->id }}"
                                                                                        class="rounded-full size-4 border-zinc-300 text-indigo-600 focus:ring-indigo-600 pointer-events-none">
                                                                                    <div
                                                                                        class="size-3 rounded-full {{ ['indigo' => 'bg-indigo-500', 'blue' => 'bg-blue-500', 'green' => 'bg-green-500', 'emerald' => 'bg-emerald-500', 'zinc' => 'bg-zinc-500', 'orange' => 'bg-orange-500', 'sky' => 'bg-sky-500', 'rose' => 'bg-rose-500', 'amber' => 'bg-amber-500', 'teal' => 'bg-teal-500', 'red' => 'bg-red-500', 'lime' => 'bg-lime-500'][$e->color ?? 'indigo'] ?? 'bg-indigo-500' }}">
                                                                                    </div>
                                                                                    <span
                                                                                        class="text-sm font-medium text-zinc-700 dark:text-zinc-300 truncate"
                                                                                        title="{{ $e->event_name }}">{{ $e->event_name }}</span>
                                                                                </div>
                                                                            @endforeach
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @empty
                                                        <div class="px-4 py-6 text-sm text-center text-zinc-400">لا
                                                            توجد أحداث</div>
                                                    @endforelse
                                                </div>
                                            </div>

                                            {{-- More Options --}}
                                            <div class="relative" x-data="{ openMore: false }" @click.away="openMore = false"
                                                wire:key="more-{{ $task->id }}-{{ $group['event']->id ?? 'general' }}">
                                                <x-flux::button @click="openMore = !openMore" variant="ghost" size="sm"
                                                    class="!px-2 text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200"
                                                    x-tooltip="'المزيد'">
                                                    <flux:icon icon="ellipsis-horizontal" class="size-4" />
                                                </x-flux::button>

                                                <div x-show="openMore" x-transition style="display: none;"
                                                    class="absolute z-[100] left-0 top-full mt-2 w-[300px] sm:w-[400px] bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-xl shadow-xl p-4 space-y-5"
                                                    dir="rtl">

                                                    <div
                                                        class="flex justify-between items-center border-b border-zinc-100 dark:border-zinc-800 pb-2">
                                                        <span class="font-bold text-zinc-700 dark:text-zinc-200">تفاصيل
                                                            المهمة</span>
                                                        <button @click="openMore = false" type="button"
                                                            class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300">
                                                            <flux:icon icon="x-mark" class="size-5" />
                                                        </button>
                                                    </div>

                                                    {{-- Categories list --}}
                                                    <div>
                                                        <flux:label class="text-sm font-bold mb-2">تصنيف المهمة
                                                        </flux:label>
                                                        <div class="grid grid-cols-2 gap-2 max-h-48 overflow-y-auto pr-1">
                                                            <button type="button"
                                                                @click="$wire.updateTaskDetails({{ $task->id }}, '{{ addslashes($task->description) }}', null); openMore = false"
                                                                class="flex items-center gap-2 p-2 rounded-lg border {{ $task->task_category_id === null ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/30' : 'border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800' }}   s text-right">
                                                                <div
                                                                    class="flex items-center justify-center size-6 shrink-0 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-500">
                                                                    <flux:icon icon="no-symbol" class="size-3" />
                                                                </div>
                                                                <span
                                                                    class="text-xs font-medium text-zinc-700 dark:text-zinc-300">بلا
                                                                    تصنيف</span>
                                                            </button>
                                                            @foreach ($categories as $dropdownCatId => $dropdownCatData)
                                                                @if ($dropdownCatData['category'])
                                                                    @php $c = $dropdownCatData['category']; @endphp
                                                                    <button type="button"
                                                                        @click="$wire.updateTaskDetails({{ $task->id }}, '{{ addslashes($task->description) }}', {{ $c->id }}); openMore = false"
                                                                        class="flex items-center gap-2 p-2 rounded-lg border {{ $task->task_category_id === $c->id ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/30' : 'border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800' }}   s text-right">
                                                                        <div
                                                                            class="flex items-center justify-center size-6 shrink-0 rounded-full bg-{{ $c->color ?? 'zinc' }}-100 dark:bg-{{ $c->color ?? 'zinc' }}-900/30 text-{{ $c->color ?? 'zinc' }}-600 dark:text-{{ $c->color ?? 'zinc' }}-400">
                                                                            <flux:icon icon="{{ $c->icon ?? 'tag' }}" class="size-3" />
                                                                        </div>
                                                                        <span
                                                                            class="text-xs font-medium text-zinc-700 dark:text-zinc-300 truncate">{{ $c->name }}</span>
                                                                    </button>
                                                                @endif
                                                            @endforeach
                                                        </div>
                                                    </div>

                                                    {{-- Description --}}
                                                    <div>
                                                        <flux:label class="text-sm font-bold mb-2">الوصف</flux:label>
                                                        <textarea
                                                            class="w-full text-sm border-zinc-300 dark:border-zinc-700 rounded-md shadow-sm bg-white dark:bg-zinc-900 focus:ring-indigo-500 focus:border-indigo-500"
                                                            rows="3" placeholder="أضف وصفاً للمهمة..."
                                                            @change="$wire.updateTaskDetails({{ $task->id }}, $event.target.value, '{{ $task->task_category_id }}')">{{ $task->description }}</textarea>
                                                    </div>

                                                    {{-- Delete button --}}
                                                    <flux:button variant="danger" size="sm" class="w-full"
                                                        wire:click="deleteTask({{ $task->id }})" wire:confirm="هل أنت متأكد؟">
                                                        حذف المهمة
                                                    </flux:button>
                                                </div>
                                            </div>

                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div wire:key="add-task-{{ $group['event']->id ?? 'general' }}-{{ $catId }}" x-data="{
                                                                                                                            isAdding: false,
                                                                                                                            newTaskTitle: '',
                                                                                                                            submit(fromBlur = false) {
                                                                                                                                if (this.newTaskTitle.trim() === '') {
                                                                                                                                    this.isAdding = false;
                                                                                                                                    return;
                                                                                                                                }
                                                                                                                                $wire.addTaskInline(this.newTaskTitle, {{ $isGeneral ? 'null' : $group['event']->id }}, {{ $catId === 'uncategorized' ? 'null' : $category->id }});
                                                                                                                                this.newTaskTitle = '';
                                                                                                                                if (!fromBlur) {
                                                                                                                                    this.isAdding = true; // Keep open to add another
                                                                                                                                    setTimeout(() => { $refs.input.focus(); }, 50);
                                                                                                                                } else {
                                                                                                                                    this.isAdding = false;
                                                                                                                                }
                                                                                                                            }
                                                                                                                        }"
                                x-init="$watch('isAdding', val => { if (val) setTimeout(() => { $refs.input.focus(); }, 50) })"
                                class="mt-2">
                                <button type="button" @click="isAdding = true" x-show="!isAdding"
                                    class="flex items-center gap-2 text-sm font-medium text-zinc-500 hover:text-indigo-600   s w-full py-2 {{ $catId !== 'uncategorized' ? 'pr-2' : '' }}">
                                    <flux:icon icon="plus" class="size-4" />
                                    إضافة مهمة...
                                </button>

                                <div x-show="isAdding" style="display: none;"
                                    class="flex items-center gap-3 py-2 border-b border-zinc-200 dark:border-zinc-800 {{ $catId !== 'uncategorized' ? 'pr-2' : '' }}">
                                    <div class="shrink-0 size-5 rounded-full border-2 border-zinc-300 dark:border-zinc-600">
                                    </div>
                                    <input x-ref="input" type="text" x-model="newTaskTitle" @keydown.enter="submit(false)"
                                        @keydown.escape="isAdding = false; newTaskTitle = ''" @blur="submit(true)"
                                        placeholder="اكتب اسم المهمة، ثم اضغط Enter أو انقر خارجاً للحفظ..."
                                        class="flex-1 bg-transparent border-none p-0 focus:ring-0 text-sm font-medium placeholder-zinc-400" />
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($isGeneral)
                    <div class="pt-6 mt-6 border-t border-dashed border-zinc-200 dark:border-zinc-800">
                        <div wire:key="add-task-group-menu" class="relative"
                            x-data="{ open: false, expandedYear: {{ $hijriYear }}, expandedMonth: {{ $hijriMonth }} }"
                            @click.away="open = false">
                            <x-flux::button @click="open = !open" variant="outline" icon="plus"
                                class="w-full justify-center border-dashed text-zinc-500 hover:text-indigo-600 bg-zinc-50 hover:bg-zinc-100 dark:bg-zinc-800/30 dark:hover:bg-zinc-800/80   s">
                                إضافة مجموعة مهام مرتبطة بحدث...
                            </x-flux::button>

                            <div x-show="open" x-transition style="display: none;"
                                class="absolute z-[100] bottom-full mb-2 right-0 w-[320px] sm:w-[450px] max-h-[500px] overflow-y-auto bg-white dark:bg-zinc-900 rounded-xl shadow-xl border border-zinc-200 dark:border-zinc-700"
                                dir="rtl">
                                <div
                                    class="px-4 py-3 text-sm font-semibold text-zinc-600 dark:text-zinc-300 sticky top-0 bg-white dark:bg-zinc-900 z-10 border-b border-zinc-100 dark:border-zinc-800 flex justify-between items-center">
                                    <span>اختر الحدث لربط المهام به</span>
                                    <button @click="open = false" type="button"
                                        class="p-1 rounded-md text-zinc-400 hover:text-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800   s">
                                        <flux:icon icon="x-mark" class="size-5" />
                                    </button>
                                </div>

                                @forelse($this->groupedAvailableEvents as $year => $months)
                                    <div class="mt-1">
                                        <div
                                            class="px-4 py-2 text-sm font-bold text-indigo-700 dark:text-indigo-400 bg-indigo-50/80 dark:bg-indigo-900/30">
                                            عام {{ $year }}
                                        </div>
                                        @foreach ($months as $monthNum => $monthData)
                                            <div class="border-b border-zinc-100 dark:border-zinc-800/50 last:border-0">
                                                <button type="button"
                                                    @click="expandedYear = {{ $year }}; expandedMonth = expandedMonth === {{ $monthNum }} ? null : {{ $monthNum }}"
                                                    class="w-full flex items-center justify-between px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/50   s">
                                                    <span
                                                        class="text-sm font-bold text-zinc-800 dark:text-zinc-200">{{ $monthData['name'] }}</span>
                                                    <flux:icon icon="chevron-down" class="size-4 text-zinc-400 transition-transform"
                                                        x-bind:class="expandedYear === {{ $year }} && expandedMonth ===
                                                                                                                                                                                                                                            {{ $monthNum }} ? 'rotate-180' : ''" />
                                                </button>

                                                <div x-show="expandedYear === {{ $year }} && expandedMonth === {{ $monthNum }}">
                                                    <div class="pl-4 pr-3 py-2 bg-zinc-50/50 dark:bg-zinc-800/20 space-y-1">
                                                        @foreach ($monthData['events'] as $event)
                                                            <button type="button" wire:click="addGroupForEvent({{ $event->id }})"
                                                                class="w-full text-right flex items-center gap-3 px-3 py-2.5 hover:bg-white dark:hover:bg-zinc-800 rounded-lg border border-transparent hover:border-zinc-200 dark:hover:border-zinc-700 hover:shadow-sm  ">
                                                                <div
                                                                    class="size-3 rounded-full {{ ['indigo' => 'bg-indigo-500', 'blue' => 'bg-blue-500', 'green' => 'bg-green-500', 'emerald' => 'bg-emerald-500', 'zinc' => 'bg-zinc-500', 'orange' => 'bg-orange-500', 'sky' => 'bg-sky-500', 'rose' => 'bg-rose-500', 'amber' => 'bg-amber-500', 'teal' => 'bg-teal-500', 'red' => 'bg-red-500', 'lime' => 'bg-lime-500'][$event->color ?? 'indigo'] ?? 'bg-indigo-500' }}">
                                                                </div>
                                                                <span
                                                                    class="text-sm font-medium text-zinc-700 dark:text-zinc-300 truncate">{{ $event->event_name }}</span>
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @empty
                                    <div class="px-4 py-6 text-sm text-center text-zinc-400">لا توجد أحداث متاحة لربط
                                        المهام بها
                                        (يجب تفعيل 'يرتبط بمهام لاحقاً' في الحدث)
                                        .</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Due Date Modal (Large Calendar) --}}
    <x-flux::modal name="due-date-modal" :closable="false"
        class="w-full sm:w-[95%] md:w-11/12 lg:w-3/4 max-w-5xl px-4 sm:px-6">
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">تحديد تاريخ الإتمام</flux:heading>
                <div class="flex items-center gap-2 bg-zinc-100 dark:bg-zinc-800 p-1 rounded-lg">
                    <flux:button wire:click="changeMonth(1)" icon="chevron-right" size="sm" variant="ghost" />
                    <span class="font-bold px-2 text-sm">{{ $this->calendarData['monthName'] ?? '' }}
                        {{ $hijriYear }}</span>
                    <flux:button wire:click="changeMonth(-1)" icon="chevron-left" size="sm" variant="ghost" />
                </div>
            </div>

            <div
                class="bg-white dark:bg-zinc-900 rounded-xl overflow-hidden border border-zinc-200 dark:border-zinc-800 shadow-sm flex flex-col min-h-[400px]">
                {{-- Weekdays Header --}}
                <div class="grid grid-cols-7 gap-1 px-3 pt-3 pb-1 text-center bg-zinc-50 dark:bg-zinc-800/50">
                    @foreach (['أحد', 'إثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت'] as $day)
                        <div class="text-[0.6rem] sm:text-xs font-bold text-zinc-400 dark:text-zinc-500 uppercase">
                            {{ $day }}
                        </div>
                    @endforeach
                </div>

                {{-- Days Grid --}}
                <div
                    class="grid grid-cols-7 gap-px bg-zinc-200 dark:bg-zinc-700 grow border-t border-zinc-200 dark:border-zinc-700">
                    @if (isset($this->calendarData['days']))
                        @foreach ($this->calendarData['days'] as $day)
                            @if ($day === null)
                                <div class="h-20 sm:h-24 md:h-28 w-full bg-white dark:bg-zinc-900/50"></div>
                            @else
                                <button wire:click="selectDueDate('{{ $day['gregorianDate'] }}')" type="button"
                                    class="group relative flex flex-col justify-start p-1 h-20 sm:h-24 md:h-28 w-full   duration-200 text-right overflow-hidden bg-white hover:bg-zinc-50 dark:bg-zinc-800 dark:hover:bg-zinc-700 {{ $day['isToday'] ? 'ring-2 ring-inset ring-indigo-500 z-10 shadow-sm' : '' }}">

                                    {{-- Event Background Slices --}}
                                    @if (count(array_filter($day['dayColors'])) > 0)
                                        <div class="absolute inset-0 flex flex-col -z-0 opacity-60">
                                            @for ($idx = 0; $idx <= $this->calendarData['maxMonthSlot']; $idx++)
                                                <div
                                                    class="flex-1 {{ $day['dayColors'][$idx] ?? 'bg-transparent' }} flex items-center justify-center overflow-hidden px-0.5 sm:px-1 {{ isset($day['dayColors'][$idx]) ? 'border-b border-white/5 last:border-b-0' : '' }}">
                                                    @if (isset($day['dayLabels'][$idx]))
                                                        <span
                                                            class="text-[0.45rem] sm:text-[0.6rem] leading-tight font-bold text-white text-center line-clamp-1 sm:line-clamp-2 drop-shadow-sm">
                                                            {{ $day['dayLabels'][$idx] }}
                                                        </span>
                                                    @endif
                                                </div>
                                            @endfor
                                        </div>
                                    @endif

                                    {{-- Hijri Day Number --}}
                                    <div class="relative z-10 flex justify-between items-start w-full leading-none mb-1">
                                        <span
                                            class="text-[0.6rem] sm:text-[0.7rem] font-bold {{ count(array_filter($day['dayColors'])) > 0 ? 'text-white' : ($day['isToday'] ? 'text-indigo-700 dark:text-indigo-400' : 'text-zinc-500 dark:text-zinc-400') }}">
                                            {{ $day['hijriDay'] }}
                                        </span>

                                        @if ($day['isToday'])
                                            <div class="size-1 sm:size-1.5 rounded-full bg-indigo-500 mt-0.5"></div>
                                        @endif
                                    </div>

                                    {{-- More Events Indicator --}}
                                    @if ($day['hasMultipleEvents'] && !empty($day['dayLabels']))
                                        <div class="absolute bottom-0.5 left-0.5 z-10 hidden sm:block">
                                            <div class="size-1 sm:size-1.5 rounded-full bg-white/50 animate-pulse">
                                            </div>
                                        </div>
                                    @endif
                                </button>
                            @endif
                        @endforeach
                    @endif
                </div>
            </div>

            <div class="flex justify-between mt-4">
                <flux:button wire:click="selectDueDate('')" variant="danger" size="sm">إزالة التاريخ
                </flux:button>
                <flux:button x-on:click="$flux.modal('due-date-modal').close()" variant="ghost" size="sm">إغلاق
                </flux:button>
            </div>
        </div>
    </x-flux::modal>
</div>