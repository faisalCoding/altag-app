<?php

use Livewire\Component;
use App\Models\AcademicCalendarEvent;
use App\Models\Stage;
use Carbon\Carbon;
use Flux\Flux;
use Livewire\Attributes\Computed;

new class extends Component {
    public $year;
    public $selectedDate = null;
    public $selectedDateHijri = '';
    public $dayEvents = [];

    // Form properties
    public $editingEventId = null;
    public $eventName = '';
    public $startDate = '';
    public $endDate = '';
    public $color = 'indigo';

    public $isVisible = true;
    public $hasTasks = true;

    public $sharedWith = [
        'all_teachers' => false,
        'all_students' => false,
        'all_supervisors' => false,
        'all_managers' => false,
        'teacher_ids' => [],
        'student_ids' => [],
        'supervisor_ids' => [],
        'manager_ids' => [],
        'circle_ids' => [],
        'stage_ids_for_teachers' => [],
        'stage_ids_for_students' => [],
        'stage_ids_for_supervisors' => [],
    ];

    // Bulk actions
    public $selectedEvents = [];
    public $selectAll = false;

    // Attendance Period Form
    public $editingPeriodId = null;
    public $hijriFromDate = '';
    public $hijriToDate = '';
    public $description = '';
    public $selectedWeekdays = [1, 2, 3, 4, 5]; // Default to Sunday-Thursday (1=Sun, 5=Thu)

    /** Stages this period speaks for; empty means the whole academy. */
    public $periodStageIds = [];

    /** @var array<int, string> Dates the circles meet outside their usual week. */
    public $periodExtraDates = [];

    /** @var array<int, string> Dates the circles do not meet despite the week. */
    public $periodExcludedDates = [];

    /** @var array<int, array{from: string, to: string, label: string|null}> */
    public $periodSessions = [];

    /** The date being added to one of the two lists, and which list. */
    public $newPeriodDate = '';

    public function mount()
    {
        // Default to the current Hijri year or a specific year if needed
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $this->year = $cal->get(\IntlCalendar::FIELD_YEAR);
        
        $this->hijriFromDate = now()->format('Y-m-d');
        $this->hijriToDate = now()->addMonth()->format('Y-m-d');
    }

    #[Computed]
    public function availableTeachers() { return \App\Models\Teacher::all(); }
    #[Computed]
    public function availableStudents() { return \App\Models\Student::all(); }
    #[Computed]
    public function availableSupervisors() { return \App\Models\Supervisor::all(); }
    #[Computed]
    public function availableManagers() { return \App\Models\Manager::all(); }
    #[Computed]
    public function availableStages() { return \App\Models\Stage::all(); }
    #[Computed]
    public function availableCircles() { return \App\Models\Circle::all(); }

    public function createPeriod()
    {
        $this->resetPeriodForm();
        Flux::modal('attendance-period-modal')->show();
    }

    public function editPeriod($id)
    {
        $period = AcademicCalendarEvent::findOrFail($id);

        $this->editingPeriodId = $period->id;
        $this->hijriFromDate = $period->start_date->format('Y-m-d');
        $this->hijriToDate = $period->end_date?->format('Y-m-d') ?? '';
        $this->description = $period->description ?? '';
        $this->selectedWeekdays = array_map('intval', $period->weekdays ?? []);
        // Filtered against the stages that still exist: the column carries ids with
        // no foreign key behind them, so a retired stage would otherwise load into
        // a checkbox that is no longer drawn and fail `exists` on save — locking
        // the period over a stage the form never showed.
        $this->periodStageIds = Stage::whereIn('id', $period->stage_ids ?? [])
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
        $this->periodExtraDates = $period->extra_dates ?? [];
        $this->periodExcludedDates = $period->excluded_dates ?? [];
        $this->periodSessions = $period->sessions ?? [];

        Flux::modal('attendance-period-modal')->show();
    }

    public function addSession()
    {
        $this->periodSessions[] = ['from' => '16:00', 'to' => '18:00', 'label' => ''];
    }

    public function removeSession($index)
    {
        unset($this->periodSessions[$index]);
        $this->periodSessions = array_values($this->periodSessions);
    }

    /**
     * Add the picked date to the extra or excluded list. A date cannot be both,
     * so naming it on one list takes it off the other.
     */
    public function addPeriodDate($list)
    {
        $this->validate(['newPeriodDate' => 'required|date'], [], ['newPeriodDate' => 'التاريخ']);

        $date = Carbon::parse($this->newPeriodDate)->format('Y-m-d');

        $this->periodExtraDates = array_values(array_diff($this->periodExtraDates, [$date]));
        $this->periodExcludedDates = array_values(array_diff($this->periodExcludedDates, [$date]));

        if ($list === 'extra') {
            $this->periodExtraDates[] = $date;
            sort($this->periodExtraDates);
        } else {
            $this->periodExcludedDates[] = $date;
            sort($this->periodExcludedDates);
        }

        $this->newPeriodDate = '';
    }

    public function removePeriodDate($list, $date)
    {
        $property = $list === 'extra' ? 'periodExtraDates' : 'periodExcludedDates';
        $this->{$property} = array_values(array_diff($this->{$property}, [$date]));
    }

    public function saveAttendancePeriod()
    {
        $this->validate([
            'hijriFromDate' => 'required|date',
            'hijriToDate' => 'required|date|after_or_equal:hijriFromDate',
            'selectedWeekdays' => 'required|array|min:1',
            'description' => 'nullable|string|max:500',
            'periodStageIds' => 'array',
            'periodStageIds.*' => 'exists:stages,id',
            'periodSessions' => 'array',
            'periodSessions.*.from' => 'required|date_format:H:i',
            'periodSessions.*.to' => 'required|date_format:H:i|after:periodSessions.*.from',
            'periodSessions.*.label' => 'nullable|string|max:60',
        ], [
            'periodSessions.*.from.required' => 'حدد وقت بداية الدوام.',
            'periodSessions.*.to.required' => 'حدد وقت نهاية الدوام.',
            'periodSessions.*.to.after' => 'وقت النهاية يجب أن يكون بعد وقت البداية.',
        ]);

        $stageIds = array_values(array_map('intval', $this->periodStageIds));

        $period = AcademicCalendarEvent::updateOrCreate(
            ['id' => $this->editingPeriodId],
            [
                'event_name' => 'فترة دوام الحلقات',
                'description' => $this->description,
                'start_date' => $this->hijriFromDate,
                'end_date' => $this->hijriToDate,
                'color' => 'emerald',
                'is_attendance_period' => true,
                'weekdays' => array_values(array_map('intval', $this->selectedWeekdays)),
                'stage_ids' => $stageIds,
                'extra_dates' => array_values($this->periodExtraDates),
                'excluded_dates' => array_values($this->periodExcludedDates),
                'sessions' => array_values($this->periodSessions),
                'created_by_id' => auth()->id(),
                'created_by_type' => get_class(auth()->user()),
                'shared_with' => [
                    'all_teachers' => true,
                    'all_students' => true,
                    'all_supervisors' => true,
                    'all_managers' => true,
                ], // Attendance periods are shared by default
            ]
        );

        // The count has to come from the same rules everything else reads, or
        // the card would advertise a number no other page agrees with.
        AcademicCalendarEvent::forgetPeriodCache();
        $count = count(AcademicCalendarEvent::workingDaysBetween(
            $this->hijriFromDate,
            $this->hijriToDate,
            $stageIds[0] ?? null,
        ));
        $period->update(['day_count' => $count]);

        $this->dispatch('notify',
            variant: 'success',
            title: $this->editingPeriodId ? 'تم الحفظ' : 'تمت الإضافة',
            description: "فترة الدوام بإجمالي $count يوم."
        );

        $this->resetPeriodForm();
        Flux::modal('attendance-period-modal')->close();
    }

    public function deletePeriod($id)
    {
        AcademicCalendarEvent::findOrFail($id)->delete();
        AcademicCalendarEvent::forgetPeriodCache();
        $this->dispatch('notify', variant: 'success', title: 'تم الحذف', description: 'تم حذف فترة الدوام.');
    }

    private function resetPeriodForm(): void
    {
        $this->editingPeriodId = null;
        $this->hijriFromDate = now()->format('Y-m-d');
        $this->hijriToDate = now()->addMonth()->format('Y-m-d');
        $this->description = '';
        $this->selectedWeekdays = [1, 2, 3, 4, 5];
        $this->periodStageIds = [];
        $this->periodExtraDates = [];
        $this->periodExcludedDates = [];
        $this->periodSessions = [];
        $this->newPeriodDate = '';
        $this->resetValidation();
    }

    public function selectDate($date, $hijriDay, $monthName)
    {
        $this->selectedDate = $date;
        $this->selectedDateHijri = "$hijriDay $monthName $this->year";

        $user = auth()->user();
        $this->dayEvents = AcademicCalendarEvent::where(function ($query) {
            $query->whereDate('start_date', '<=', $this->selectedDate)
                ->whereDate('end_date', '>=', $this->selectedDate);
        })->visibleTo($user)->get()->toArray();

        $dayTasks = \App\Models\Task::with('category')->whereDate('due_date', $this->selectedDate)
            ->where(function ($q) use ($user) {
                $q->where('created_by_id', $user->id)
                  ->where('created_by_type', get_class($user))
                  ->orWhere(function ($sq) use ($user) {
                      $sq->where('assigned_to_id', $user->id)
                         ->where('assigned_to_type', get_class($user));
                  });
            })->get()->map(function ($task) {
                return [
                    'id' => $task->id,
                    'event_name' => 'مهمة: ' . $task->title,
                    'start_date' => $task->due_date,
                    'end_date' => $task->due_date,
                    'color' => $task->category ? $task->category->color : 'zinc',
                    'is_task' => true,
                    'status' => $task->status,
                ];
            })->toArray();

        $this->dayEvents = array_merge($this->dayEvents, $dayTasks);

        if (count($this->dayEvents) > 0) {
            Flux::modal('day-events-modal')->show();
        } else {
            $this->createNewEvent($date);
        }
    }

    public function createNewEvent($date = null)
    {
        $this->resetForm();
        if ($date) {
            $this->startDate = $date;
            $this->endDate = $date;
        }
        Flux::modal('event-form-modal')->show();
    }

    public function editEvent($id)
    {
        $event = AcademicCalendarEvent::findOrFail($id);
        $this->editingEventId = $event->id;
        $this->eventName = $event->event_name;
        $this->startDate = $event->start_date->format('Y-m-d');
        $this->endDate = $event->end_date->format('Y-m-d');
        $this->color = $event->color ?: 'indigo';
        if ($event->shared_with) {
            $this->sharedWith = array_merge($this->sharedWith, $event->shared_with);
        }
        $this->isVisible = (bool) $event->is_visible;
        $this->hasTasks = (bool) $event->has_tasks;

        Flux::modal('day-events-modal')->close();
        Flux::modal('event-form-modal')->show();
    }

    public function saveEvent()
    {
        $this->validate([
            'eventName' => 'required|string|max:255',
            'startDate' => 'required|date',
            'endDate' => 'required|date|after_or_equal:startDate',
            'color' => 'required|string',
        ]);

        $user = auth()->user();
        
        // Ensure only creator can edit
        if ($this->editingEventId) {
            $existing = AcademicCalendarEvent::findOrFail($this->editingEventId);
            if ($existing->created_by_id !== $user->id || $existing->created_by_type !== get_class($user)) {
                abort(403, 'غير مصرح لك بتعديل هذا الحدث.');
            }
        }

        $data = [
            'event_name' => $this->eventName,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'color' => $this->color,
            'shared_with' => collect($this->sharedWith)->map(function ($value) {
                return is_array($value) ? array_map('intval', $value) : (bool) $value;
            })->toArray(),
            'is_visible' => $this->isVisible,
            'has_tasks' => $this->hasTasks,
        ];

        if (!$this->editingEventId) {
            $data['created_by_id'] = $user->id;
            $data['created_by_type'] = get_class($user);
        }

        AcademicCalendarEvent::updateOrCreate(
            ['id' => $this->editingEventId],
            $data
        );

        $this->resetForm();
        Flux::modal('event-form-modal')->close();
        
        $this->dispatch('notify', 
            variant: 'success',
            title: 'تم الحفظ بنجاح',
            description: 'تم تحديث التقويم الأكاديمي.'
        );
    }

    public function completeTask($taskId)
    {
        $task = \App\Models\Task::findOrFail($taskId);
        $task->update(['status' => 'completed']);
        
        // Refresh dayEvents array
        if ($this->selectedDate) {
            $hijriParts = explode(' ', $this->selectedDateHijri);
            if(count($hijriParts) >= 2) {
                $this->selectDate($this->selectedDate, $hijriParts[0], $hijriParts[1]);
            }
        }
        
        Flux::toast('تم إكمال المهمة بنجاح', variant: 'success');
    }

    public function deleteEvent($id)
    {
        AcademicCalendarEvent::findOrFail($id)->delete();
        $this->dayEvents = array_filter($this->dayEvents, fn($e) => $e['id'] != $id);
        
        if (empty($this->dayEvents)) {
            Flux::modal('day-events-modal')->close();
        }

        $this->dispatch('notify', 
            variant: 'success',
            title: 'تم الحذف',
            description: 'تم حذف الحدث من التقويم.'
        );
    }

    private function resetForm()
    {
        $this->editingEventId = null;
        $this->eventName = '';
        $this->startDate = '';
        $this->endDate = '';
        $this->color = 'indigo';
        $this->sharedWith = [
            'all_teachers' => false,
            'all_students' => false,
            'all_supervisors' => false,
            'all_managers' => false,
            'teacher_ids' => [],
            'student_ids' => [],
            'supervisor_ids' => [],
            'manager_ids' => [],
            'circle_ids' => [],
            'stage_ids_for_teachers' => [],
            'stage_ids_for_students' => [],
            'stage_ids_for_supervisors' => [],
        ];
        $this->isVisible = true;
        $this->hasTasks = true;
    }

    public function toggleSelectAll()
    {
        if ($this->selectAll) {
            $this->selectedEvents = AcademicCalendarEvent::where(function($q) {
                $q->where('created_by_id', auth()->id())
                  ->where('created_by_type', get_class(auth()->user()));
            })->pluck('id')->map(fn($id) => (string)$id)->toArray();
        } else {
            $this->selectedEvents = [];
        }
    }

    public function bulkDelete()
    {
        AcademicCalendarEvent::whereIn('id', $this->selectedEvents)
            ->where('created_by_id', auth()->id())
            ->where('created_by_type', get_class(auth()->user()))
            ->delete();
        
        $this->selectedEvents = [];
        $this->selectAll = false;
        Flux::toast(__('تم حذف الأحداث المحددة'), variant: 'success');
    }

    public function bulkShare($status = true)
    {
        $events = AcademicCalendarEvent::whereIn('id', $this->selectedEvents)
            ->where('created_by_id', auth()->id())
            ->where('created_by_type', get_class(auth()->user()))
            ->get();
            
        foreach ($events as $e) {
            $sw = $e->shared_with ?? [
                'all_teachers' => false,
                'all_students' => false,
                'all_supervisors' => false,
                'all_managers' => false,
                'teacher_ids' => [],
                'student_ids' => [],
                'supervisor_ids' => [],
                'manager_ids' => [],
                'circle_ids' => [],
                'stage_ids_for_teachers' => [],
                'stage_ids_for_students' => [],
                'stage_ids_for_supervisors' => [],
            ];
            $sw['all_teachers'] = $status;
            $sw['all_students'] = $status;
            $sw['all_supervisors'] = $status;
            $sw['all_managers'] = $status;
            $e->update(['shared_with' => $sw]);
        }
        
        $this->selectedEvents = [];
        $this->selectAll = false;
        Flux::toast($status ? __('تمت مشاركة الأحداث المحددة مع الجميع') : __('تم إلغاء مشاركة الأحداث المحددة مع الجميع'), variant: 'success');
    }

    public function bulkVisible($status = true)
    {
        AcademicCalendarEvent::whereIn('id', $this->selectedEvents)
            ->where('created_by_id', auth()->id())
            ->where('created_by_type', get_class(auth()->user()))
            ->update(['is_visible' => $status]);
        
        $this->selectedEvents = [];
        $this->selectAll = false;
        Flux::toast($status ? __('تم إظهار الأحداث المحددة') : __('تم إخفاء الأحداث المحددة'), variant: 'success');
    }

    public function with()
    {
        $cal = \IntlCalendar::createInstance('Asia/Riyadh', 'ar_SA@calendar=islamic-umalqura');
        $cal->set(\IntlCalendar::FIELD_YEAR, $this->year);
        $cal->set(\IntlCalendar::FIELD_MONTH, 0);
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $startDate = date('Y-m-d', $cal->getTime() / 1000);

        $cal->set(\IntlCalendar::FIELD_YEAR, $this->year);
        $cal->set(\IntlCalendar::FIELD_MONTH, 11);
        $monthLength = $cal->getActualMaximum(\IntlCalendar::FIELD_DAY_OF_MONTH);
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $monthLength);
        $endDate = date('Y-m-d', $cal->getTime() / 1000);

        $user = auth()->user();
        $allEvents = AcademicCalendarEvent::where(function ($query) use ($startDate, $endDate) {
            $query->where(function($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($sq) use ($startDate, $endDate) {
                        $sq->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
            });
        })->visibleTo($user)->get();

        $tasks = \App\Models\Task::with('category')->whereNotNull('due_date')
            ->whereBetween('due_date', [$startDate, $endDate])
            ->where(function ($q) use ($user) {
                $q->where('created_by_id', $user->id)
                  ->where('created_by_type', get_class($user))
                  ->orWhere(function ($sq) use ($user) {
                      $sq->where('assigned_to_id', $user->id)
                         ->where('assigned_to_type', get_class($user));
                  });
            })->get();

        foreach ($tasks as $task) {
            $taskEvent = new AcademicCalendarEvent([
                'id' => 999000 + $task->id,
                'event_name' => 'مهمة: ' . $task->title,
                'start_date' => $task->due_date,
                'end_date' => $task->due_date,
                'color' => $task->category ? $task->category->color : 'zinc',
            ]);
            $taskEvent->is_task = true;
            $taskEvent->task_id = $task->id;
            $taskEvent->status = $task->status;
            $allEvents->push($taskEvent);
        }

        $attendancePeriods = AcademicCalendarEvent::where('is_attendance_period', true)
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate]);
            })->visibleTo($user)->get();

        $otherEventsList = AcademicCalendarEvent::where('is_attendance_period', false)
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate]);
            })->visibleTo($user)->orderBy('start_date')->get();

        $months = [];
        for ($m = 0; $m < 12; $m++) {
            $months[] = $this->getMonthData($this->year, $m, $allEvents);
        }

        return [
            'months' => $months,
            'currentYear' => $this->year,
            'attendancePeriods' => $attendancePeriods,
            'otherEventsList' => $otherEventsList,
        ];
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
        
        $sortedEvents = $allEvents->sortBy([
            ['start_date', 'asc'],
            ['end_date', 'desc'],
        ]);

        foreach ($sortedEvents as $event) {
            $assignedSlot = 0;
            $eventStart = $event->start_date->format('Y-m-d');
            while (isset($occupiedUntil[$assignedSlot]) && $occupiedUntil[$assignedSlot] >= $eventStart) {
                $assignedSlot++;
            }
            if ($assignedSlot < 8) { // Max 8 slots
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

        // Map color names to Tailwind classes
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
                // A period's extra days sit outside its range, and its excluded
                // days sit inside it, so both are settled before the range is.
                if ($event->is_attendance_period) {
                    if (in_array($gregDate, $event->excluded_dates ?? [], true)) {
                        return false;
                    }

                    if (in_array($gregDate, $event->extra_dates ?? [], true)) {
                        return true;
                    }
                }

                $dateMatch = $gregDate >= $event->start_date->format('Y-m-d') &&
                    $gregDate <= $event->end_date->format('Y-m-d');

                if (!$dateMatch) return false;

                if ($event->is_attendance_period && $event->weekdays) {
                    return in_array($dayOfWeek, $event->weekdays);
                }

                return true;
            });

            $colorClass = 'bg-white hover:bg-zinc-50 dark:bg-zinc-800 dark:hover:bg-zinc-700';
            $dayEventsData = [];
            $fullEventsData = [];

            if ($currentDayEvents->isNotEmpty()) {
                foreach ($currentDayEvents as $event) {
                    $color = $event->color ?: 'indigo';
                    $isLabeling = ($gregDate === $event->start_date->format('Y-m-d') || 
                                   $gregDate === $event->end_date->format('Y-m-d'));
                    
                    $dayEventsData[] = [
                        'id' => $event->id,
                        'colorClass' => $colorMap[$color] ?? $colorMap['indigo'],
                        'colorName' => $color,
                        'label' => $isLabeling ? $event->event_name : null,
                        'slot_index' => $event->slot_index,
                    ];
                    
                    $fullEventsData[] = [
                        'id' => $event->is_task ? $event->task_id : $event->id,
                        'event_name' => $event->event_name,
                        'start_date' => $event->start_date->format('Y-m-d'),
                        'end_date' => $event->end_date->format('Y-m-d'),
                        'formatted_start' => $event->start_date->format('Y/m/d'),
                        'formatted_end' => $event->end_date->format('Y/m/d'),
                        'color' => $color,
                        'is_task' => $event->is_task ?? false,
                        'status' => $event->status ?? null,
                        'created_by_id' => $event->created_by_id ?? null,
                        'created_by_type' => $event->created_by_type ?? null,
                        'can_edit' => ($event->created_by_id == auth()->id() && $event->created_by_type == get_class(auth()->user())),
                        'slot_index' => $event->slot_index,
                    ];
                }
                
                // Sort by slot index to keep relative order consistent
                usort($dayEventsData, fn($a, $b) => $a['slot_index'] <=> $b['slot_index']);
                usort($fullEventsData, fn($a, $b) => $a['slot_index'] <=> $b['slot_index']);
                
                $colorClass = 'bg-white dark:bg-zinc-800';
            }

            $days[] = [
                'hijriDay' => $i,
                'gregorianDate' => $gregDate,
                'colorClass' => $colorClass,
                'events' => $dayEventsData,
                'fullEvents' => $fullEventsData,
                'hasMultipleEvents' => $currentDayEvents->count() > 1,
                'isToday' => $gregDate === date('Y-m-d'),
            ];
        }

        $maxMonthSlot = 0;
        foreach ($slottedEvents as $event) {
            if ($event->slot_index > $maxMonthSlot) $maxMonthSlot = $event->slot_index;
        }

        return [
            'monthName' => $monthName,
            'days' => $days,
            'maxMonthSlot' => $maxMonthSlot,
        ];
    }
};
?>

<div x-data="calendarEvents" class="space-y-8 pb-10" dir="rtl">
    <div class="flex flex-col gap-7">
        <div>
            <flux:heading size="xl" class="font-bold">التقويم الأكاديمي</flux:heading>
            <flux:subheading>جدول الإجازات والأحداث التعليمية للعام الهجري {{ $currentYear }}</flux:subheading>
        </div>
        
        <div class="flex flex-col gap-7 sm:flex-row items-stretch sm:items-center">
            <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
                <flux:button wire:click="createNewEvent" icon="plus" size="sm" variant="primary" class="w-full sm:w-auto">إضافة حدث</flux:button>
                <flux:button wire:click="createPeriod" icon="clock" size="sm" variant="outline" class="w-full mt-6 sm:w-auto">إضافة فترة دوام</flux:button>
            </div>
            
            <div class="flex items-center justify-between sm:justify-center gap-2 bg-zinc-100 dark:bg-zinc-800 p-1 rounded-lg w-full sm:w-auto mt-6 sm:mt-0">
                <flux:button wire:click="$set('year', {{ $currentYear - 1 }})" icon="chevron-right" size="sm" variant="ghost" />
                <span class="font-bold px-2 sm:px-4 text-center grow sm:grow-0">{{ $currentYear }}</span>
                <flux:button wire:click="$set('year', {{ $currentYear + 1 }})" icon="chevron-left" size="sm" variant="ghost" />
            </div>
        </div>
    </div>
    {{-- Event Lists Section --}}
    <div x-data="{ showAttendance: true, showOtherEvents: false }" class="space-y-4">
        {{-- Attendance Periods Collapsible --}}
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden">
            <button @click="showAttendance = !showAttendance" class="w-full flex items-center justify-between p-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/50   s text-right">
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-emerald-100 dark:bg-emerald-900/30 rounded-lg">
                        <flux:icon icon="clock" class="text-emerald-600 dark:text-emerald-400 size-5" />
                    </div>
                    <div class="text-right">
                        <span class="font-bold text-zinc-800 dark:text-zinc-100 block leading-tight">أيام الدوام الرسمي</span>
                        <span class="text-[0.7rem] text-zinc-500">{{ $attendancePeriods->count() }} فترات مسجلة</span>
                    </div>
                </div>
                <flux:icon icon="chevron-down" class="size-4 text-zinc-400 transition-transform" x-bind:class="showAttendance ? 'rotate-180' : ''" />
            </button>

            <div x-show="showAttendance" x-collapse>
                <div class="p-4 pt-0">
                    @if($attendancePeriods->isEmpty())
                        <div class="py-10 text-center border-2 border-dashed border-zinc-100 dark:border-zinc-800 rounded-xl">
                            <flux:icon icon="calendar" class="size-8 text-zinc-200 dark:text-zinc-700 mx-auto mb-2" />
                            <p class="text-sm text-zinc-400">لا توجد فترات دوام مسجلة حالياً</p>
                        </div>
                    @else
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                            @foreach($attendancePeriods as $period)
                                <div class="relative flex flex-col p-4 bg-emerald-50/50 dark:bg-emerald-900/10 border border-emerald-100/50 dark:border-emerald-800/50 rounded-xl group">
                                    <div class="flex items-center justify-between mb-2">
                                        <div class="flex items-center gap-2">
                                            <div class="size-2 rounded-full bg-emerald-500"></div>
                                            <span class="font-bold text-sm text-emerald-900 dark:text-emerald-100">{{ $period->event_name }}</span>
                                        </div>
                                        <div class="flex items-center">
                                            <flux:button variant="ghost" size="xs" icon="pencil-square" class="text-emerald-600" wire:click="editPeriod({{ $period->id }})" />
                                            <flux:button variant="ghost" size="xs" icon="x-mark" class="text-emerald-600 hover:text-red-500" wire:click="deletePeriod({{ $period->id }})" wire:confirm="هل أنت متأكد من حذف فترة الدوام هذه؟" />
                                        </div>
                                    </div>

                                    @if($period->description)
                                        <p class="text-xs text-emerald-700/70 dark:text-emerald-400/70 mb-3 leading-relaxed">
                                            {{ $period->description }}
                                        </p>
                                    @endif

                                    @php
                                        $periodStages = $period->stageNames();
                                    @endphp
                                    <div class="flex flex-wrap gap-1 mb-3">
                                        <span class="px-1.5 py-0.5 rounded text-[0.6rem] font-medium bg-emerald-100 dark:bg-emerald-800/60 text-emerald-700 dark:text-emerald-300">
                                            {{ $periodStages->isEmpty() ? 'كل المراحل' : $periodStages->implode('، ') }}
                                        </span>
                                        @foreach($period->sessions ?? [] as $session)
                                            <span class="px-1.5 py-0.5 rounded text-[0.6rem] font-medium bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 border border-emerald-100 dark:border-emerald-800 dir-ltr">
                                                {{ $session['from'] }}–{{ $session['to'] }}{{ ($session['label'] ?? '') !== '' ? ' · '.$session['label'] : '' }}
                                            </span>
                                        @endforeach
                                        @if(! empty($period->extra_dates))
                                            <span class="px-1.5 py-0.5 rounded text-[0.6rem] font-medium bg-emerald-100 dark:bg-emerald-800/60 text-emerald-700 dark:text-emerald-300">
                                                +{{ count($period->extra_dates) }} يوم إضافي
                                            </span>
                                        @endif
                                        @if(! empty($period->excluded_dates))
                                            <span class="px-1.5 py-0.5 rounded text-[0.6rem] font-medium bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300">
                                                −{{ count($period->excluded_dates) }} يوم مستثنى
                                            </span>
                                        @endif
                                    </div>

                                    <div class="mt-auto flex items-center justify-between border-t border-emerald-100 dark:border-emerald-800 pt-3">
                                        <div class="text-[0.6rem] font-medium text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">
                                            <x-hijri-date :date="$period->start_date" /> - <x-hijri-date :date="$period->end_date" />
                                        </div>
                                        <div class="flex items-center gap-1.5 px-2 py-0.5 bg-emerald-100 dark:bg-emerald-800 rounded-md">
                                            <span class="text-[0.65rem] font-bold text-emerald-700 dark:text-emerald-300">{{ $period->day_count }} يوم</span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Other Events Collapsible --}}
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden">
            <div class="flex items-center justify-between p-4 bg-white dark:bg-zinc-900 border-b border-zinc-100 dark:border-zinc-800">
                <div class="flex items-center gap-4">
                     <flux:checkbox wire:model.live="selectAll" wire:click="toggleSelectAll" />
                     <div class="flex items-center gap-3">
                        <div class="p-2 bg-indigo-100 dark:bg-indigo-900/30 rounded-lg">
                            <flux:icon icon="calendar-days" class="text-indigo-600 dark:text-indigo-400 size-5" />
                        </div>
                        <div class="text-right">
                            <span class="font-bold text-zinc-800 dark:text-zinc-100 block leading-tight">باقي الأحداث والإجازات</span>
                            <span class="text-[0.7rem] text-zinc-500">{{ $otherEventsList->count() }} أحداث مسجلة</span>
                        </div>
                    </div>
                </div>
                
                <div class="flex items-center gap-3">
                    @if(!empty($selectedEvents))
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-zinc-500 ml-2">تم تحديد {{ count($selectedEvents) }}</span>
                            <flux:dropdown>
                                <flux:button size="xs" variant="outline" icon="bolt">إجراءات</flux:button>
                                <flux:menu>
                                    <flux:menu.item icon="share" wire:click="bulkShare(true)">مشاركة مع الآخرين</flux:menu.item>
                                    <flux:menu.item icon="lock-closed" wire:click="bulkShare(false)">إلغاء المشاركة</flux:menu.item>
                                    <flux:separator />
                                    <flux:menu.item icon="eye" wire:click="bulkVisible(true)">إظهار في التقويم</flux:menu.item>
                                    <flux:menu.item icon="eye-slash" wire:click="bulkVisible(false)">إخفاء من التقويم</flux:menu.item>
                                    <flux:separator />
                                    <flux:menu.item icon="trash" variant="danger" wire:click="bulkDelete" wire:confirm="هل أنت متأكد من حذف الأحداث المحددة؟">حذف المحدد</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    @endif
                    
                    <button @click="showOtherEvents = !showOtherEvents" class="hover:bg-zinc-100 dark:hover:bg-zinc-800 p-2 rounded-lg   s">
                        <flux:icon icon="chevron-down" class="size-4 text-zinc-400 transition-transform" x-bind:class="showOtherEvents ? 'rotate-180' : ''" />
                    </button>
                </div>
            </div>

            <div x-show="showOtherEvents" x-collapse>
                <div class="p-4 pt-0">
                    @if($otherEventsList->isEmpty())
                        <div class="py-10 text-center border-2 border-dashed border-zinc-100 dark:border-zinc-800 rounded-xl">
                            <flux:icon icon="sparkles" class="size-8 text-zinc-200 dark:text-zinc-700 mx-auto mb-2" />
                            <p class="text-sm text-zinc-400">لا توجد أحداث أخرى مسجلة حالياً</p>
                        </div>
                    @else
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                            @foreach($otherEventsList as $event)
                        <div class="relative flex flex-col p-3 bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-100 dark:border-zinc-800 rounded-xl group   hover:shadow-md">
                                    <div class="flex items-center justify-between mb-2">
                                        <div class="flex items-center gap-2">
                                            <flux:checkbox wire:model.live="selectedEvents" value="{{ $event->id }}" />
                                            <div class="size-2 rounded-full bg-{{ $event->color ?: 'indigo' }}-500"></div>
                                            <span class="font-bold text-sm text-zinc-800 dark:text-zinc-200 truncate max-w-[100px]">{{ $event->event_name }}</span>
                                            @if($event->is_shared)
                                                <flux:icon icon="share" class="size-3 text-zinc-400" />
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                            @if($event->created_by_id == auth()->id() && $event->created_by_type == get_class(auth()->user()))
                                                <flux:button variant="ghost" size="xs" icon="pencil" wire:click="editEvent({{ $event->id }})" />
                                                <flux:button variant="ghost" size="xs" icon="trash" wire:click="deleteEvent({{ $event->id }})" class="text-red-500" />
                                            @endif
                                        </div>
                                    </div>
                                    
                                    <div class="mt-auto flex items-center justify-between">
                                        <div class="text-[0.6rem] text-zinc-500 font-medium tracking-tight">
                                            <x-hijri-date :date="$event->start_date" />
                                            @if($event->start_date != $event->end_date)
                                                - <x-hijri-date :date="$event->end_date" style="dayMonth" />
                                            @endif
                                        </div>
                                        @php
                                            $daysCount = $event->start_date->diffInDays($event->end_date) + 1;
                                        @endphp
                                        <span class="text-[0.6rem] font-bold text-zinc-400">{{ $daysCount }} يوم</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-5xl mx-auto grid grid-cols-1 md:grid-cols-2 gap-8">
        @foreach($months as $month)
            <div
                class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden flex flex-col">
                {{-- Month Header --}}
                <div
                    class="px-4 py-3 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/50 text-center">
                    <div class="font-bold text-zinc-800 dark:text-zinc-100 text-sm">
                        {{ $month['monthName'] }}
                    </div>
                </div>

                {{-- Weekdays Header --}}
                <div class="grid grid-cols-7 gap-1 px-3 pt-3 pb-1 text-center">
                    @foreach(['أحد', 'إثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت'] as $day)
                        <div class="text-[0.6rem] font-bold text-zinc-400 dark:text-zinc-500 uppercase">{{ $day }}</div>
                    @endforeach
                </div>

                {{-- Days Grid --}}
                <div class="grid grid-cols-7 gap-px bg-zinc-100 dark:bg-zinc-800 grow border-t border-zinc-100 dark:border-zinc-800">
                    @foreach($month['days'] as $day)
                        @if($day === null)
                            <div class="h-20 w-full bg-white dark:bg-zinc-900/50"></div>
                        @else
                            <button
                                x-on:click="openDay('{{ $day['gregorianDate'] }}', {{ $day['hijriDay'] }}, '{{ $month['monthName'] }}', @js($day['fullEvents']))"
                                type="button" class="group relative flex flex-col justify-start p-1 h-20 w-full   duration-200 text-right overflow-hidden
                                            {{ $day['colorClass'] }}
                                            {{ $day['isToday'] ? 'ring-2 ring-inset ring-indigo-500 z-10 shadow-sm' : '' }}
                                            ">

                                {{-- Hijri Day Number --}}
                                <div class="relative z-10 flex justify-between items-start w-full leading-none mb-1">
                                    <span
                                        class="text-[0.7rem] font-bold {{ $day['isToday'] ? 'text-indigo-700 dark:text-indigo-400' : 'text-zinc-600 dark:text-zinc-300' }}">
                                        {{ $day['hijriDay'] }}
                                    </span>

                                    @if ($day['isToday'])
                                        <div class="size-1 rounded-full bg-indigo-500 mt-0.5"></div>
                                    @endif
                                </div>

                                {{-- Events --}}
                                @if(count($day['events']) > 0)
                                    <div class="relative z-10 flex flex-col gap-0.5 w-full">
                                        @php
                                            $evCount = count($day['events']);
                                        @endphp
                                        @if($evCount <= 3)
                                            @foreach($day['events'] as $ev)
                                                <div class="{{ $ev['colorClass'] }} rounded px-1 py-px flex items-center overflow-hidden min-h-[14px]">
                                                    @if($ev['label'])
                                                        <span class="text-[0.55rem] leading-none font-bold text-white line-clamp-1 drop-shadow-sm truncate w-full text-right">
                                                            {{ $ev['label'] }}
                                                        </span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        @else
                                            {{-- More than 3 events --}}
                                            <div class="{{ $day['events'][0]['colorClass'] }} rounded px-1 py-px flex items-center overflow-hidden min-h-[14px]">
                                                @if($day['events'][0]['label'])
                                                    <span class="text-[0.55rem] leading-none font-bold text-white line-clamp-1 drop-shadow-sm truncate w-full text-right">
                                                        {{ $day['events'][0]['label'] }}
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="{{ $day['events'][1]['colorClass'] }} rounded px-1 py-px flex items-center overflow-hidden min-h-[14px]">
                                                @if($day['events'][1]['label'])
                                                    <span class="text-[0.55rem] leading-none font-bold text-white line-clamp-1 drop-shadow-sm truncate w-full text-right">
                                                        {{ $day['events'][1]['label'] }}
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="flex items-center gap-1 mt-0.5 px-1">
                                                <div class="size-1.5 rounded-full bg-{{ $day['events'][2]['colorName'] }}-500"></div>
                                                <div class="size-1.5 rounded-full bg-{{ $day['events'][3]['colorName'] }}-500"></div>
                                                @if($evCount == 5)
                                                    <div class="size-1.5 rounded-full bg-{{ $day['events'][4]['colorName'] }}-500"></div>
                                                @elseif($evCount > 5)
                                                    <span class="text-[1rem] leading-none font-bold text-zinc-400 mr-0.5">+</span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </button>
                        @endif
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    {{-- Event Details Modal --}}
    <flux:modal name="day-events-modal" class="max-w-md">
        <div class="space-y-6">
            <div class="flex justify-between items-start">
                <div>
                    <flux:heading size="lg">أحداث اليوم</flux:heading>
                    <flux:subheading><span x-text="selectedDateHijri"></span> هـ</flux:subheading>
                    <div class="text-xs text-zinc-400 mt-1"><span x-text="selectedDate"></span> م</div>
                </div>
                <flux:button variant="ghost" size="sm" icon="plus" x-on:click="$wire.createNewEvent(selectedDate)">إضافة حدث</flux:button>
            </div>

            <div class="space-y-3">
                <template x-for="event in dayEvents" :key="event.id">
                    <div class="group relative flex items-start gap-3 p-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-100 dark:border-zinc-800">
                        <div class="size-3 rounded-full mt-1.5 shrink-0" :class="'bg-' + (event.color || 'indigo') + '-500'"></div>
                        <div class="flex-1">
                            <div class="font-bold text-zinc-900 dark:text-white" :class="event.is_task && event.status === 'completed' ? 'line-through text-zinc-500' : ''" x-text="event.event_name"></div>
                            <div class="text-xs text-zinc-500 mt-1">
                                <span x-text="event.formatted_start"></span>
                                <template x-if="event.formatted_start !== event.formatted_end">
                                    <span> - <span x-text="event.formatted_end"></span></span>
                                </template>
                            </div>
                        </div>
                        
                        <template x-if="!event.is_task">
                            <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
                                <template x-if="event.can_edit">
                                    <flux:button variant="ghost" size="xs" icon="pencil" x-on:click="$wire.editEvent(event.id)" />
                                </template>
                                <template x-if="event.can_edit">
                                    <flux:button variant="ghost" size="xs" icon="trash" x-on:click="$wire.deleteEvent(event.id)" class="text-red-500" />
                                </template>
                            </div>
                        </template>
                        <template x-if="event.is_task">
                            <div class="flex items-center gap-1 shrink-0">
                                <template x-if="event.status === 'completed'">
                                    <span class="text-[0.65rem] text-emerald-600 font-bold bg-emerald-100 dark:bg-emerald-900/30 px-2 py-1 rounded">مكتملة</span>
                                </template>
                                <template x-if="event.status !== 'completed'">
                                    <flux:button variant="ghost" size="xs" icon="check-circle" x-on:click="$wire.completeTask(event.id)" class="text-zinc-400 hover:text-emerald-500" />
                                </template>
                                <flux:button variant="ghost" size="xs" icon="arrow-top-right-on-square" href="{{ route(auth()->guard('manager')->check() ? 'manager.tasks' : 'supervisor.tasks') }}" wire:navigate />
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <div class="flex justify-end pt-2">
                <flux:modal.close>
                    <flux:button variant="ghost" size="sm">إغلاق</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

    {{-- Event Form Modal --}}
    <flux:modal name="event-form-modal" class="max-w-md">
        <form wire:submit="saveEvent" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingEventId ? 'تعديل حدث' : 'إضافة حدث جديد' }}</flux:heading>
                <flux:subheading>أدخل تفاصيل الحدث الأكاديمي ليظهر في التقويم.</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:input wire:model="eventName" label="اسم الحدث" placeholder="مثال: إجازة مطولة" required />
                
                <div class="grid grid-cols-2 gap-4">
                    <livewire:shared.hijri-datepicker wire:model="startDate" label="تاريخ البداية (هجري)" />
                    <livewire:shared.hijri-datepicker wire:model="endDate" label="تاريخ النهاية (هجري)" />
                </div>

                <div class="space-y-2">
                    <flux:label>لون الحدث</flux:label>
                    <div class="flex flex-wrap gap-2.5">
                        @foreach(['indigo', 'blue', 'green', 'emerald', 'zinc', 'orange', 'sky', 'rose', 'amber', 'teal', 'red', 'lime'] as $c)
                            <button 
                                type="button" 
                                wire:click="$set('color', '{{ $c }}')"
                                class="size-8 rounded-full bg-{{ $c }}-500 border-4 {{ $color === $c ? 'border-zinc-200 dark:border-zinc-700 shadow-md scale-110' : 'border-transparent hover:scale-105' }}   flex items-center justify-center group"
                            >
                                @if($color === $c)
                                    <flux:icon icon="check" variant="micro" class="text-white" />
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="space-y-4 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                    <flux:heading size="sm">إعدادات الظهور والمشاركة</flux:heading>
                    
                    <flux:switch wire:model="isVisible" label="ظهور الحدث في التقويم الخاص بي" />
                    <flux:switch wire:model="hasTasks" label="يرتبط بمهام لاحقاً" description="تفعيل لكي يظهر هذا الحدث عند إضافة مهام جديدة" />
                    
                    <div class="grid grid-cols-2 gap-3 mt-3">
                        <flux:checkbox wire:model="sharedWith.all_teachers" label="جميع المعلمين" />
                        <flux:checkbox wire:model="sharedWith.all_students" label="جميع الطلاب" />
                        <flux:checkbox wire:model="sharedWith.all_supervisors" label="جميع المشرفين" />
                        <flux:checkbox wire:model="sharedWith.all_managers" label="جميع المدراء" />
                    </div>

                    <div class="space-y-3 mt-4" x-data="{ advancedSharing: false }">
                        <button type="button" @click="advancedSharing = !advancedSharing" class="text-xs text-indigo-600 font-bold flex items-center gap-1">
                            <flux:icon icon="adjustments-horizontal" variant="micro" />
                            مشاركة متقدمة (مع محددين)
                        </button>
                        
                        <div x-show="advancedSharing" class="space-y-3 p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl border border-zinc-100 dark:border-zinc-800">
                            <x-alpine-multiselect 
                                wire:model="sharedWith.teacher_ids" 
                                label="معلمين محددين"
                                placeholder="اختر المعلمين..."
                                :options="collect($this->availableTeachers)->map(fn($t) => ['value' => $t->id, 'label' => $t->name])->toArray()" 
                            />
                            
                            <x-alpine-multiselect 
                                wire:model="sharedWith.supervisor_ids" 
                                label="مشرفين محددين"
                                placeholder="اختر المشرفين..."
                                :options="collect($this->availableSupervisors)->map(fn($s) => ['value' => $s->id, 'label' => $s->name])->toArray()" 
                            />
                            
                            <flux:select wire:model="sharedWith.stage_ids_for_students" multiple placeholder="اختر المراحل...">
                                <x-slot:label>مراحل دراسية (للطلاب)</x-slot:label>
                                @foreach($this->availableStages as $st)
                                    <flux:select.option value="{{ $st->id }}">{{ $st->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            
                            <x-alpine-multiselect 
                                wire:model="sharedWith.circle_ids" 
                                label="حلقات محددة"
                                placeholder="اختر الحلقات..."
                                :options="collect($this->availableCircles)->map(fn($c) => ['value' => $c->id, 'label' => $c->name])->toArray()" 
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <flux:modal.close>
                    <flux:button variant="ghost">إلغاء</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">حفظ الحدث</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Attendance Period Modal --}}
    <flux:modal name="attendance-period-modal" class="max-w-lg">
        <form wire:submit="saveAttendancePeriod" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingPeriodId ? 'تعديل فترة دوام الحلقات' : 'إضافة فترة دوام الحلقات' }}</flux:heading>
                <flux:subheading>حدد المدة، والمراحل التي تتبعها، وأيام الأسبوع وأوقات الدوام.</flux:subheading>
            </div>

            <div class="space-y-5 max-h-[65vh] overflow-y-auto pe-1">
                <flux:textarea wire:model="description" label="وصف الفترة" placeholder="مثال: الفصل الدراسي الأول" rows="2" />

                <div class="grid grid-cols-1 gap-4">
                    <livewire:shared.hijri-datepicker wire:model="hijriFromDate" label="من تاريخ (هجري)" />
                    <livewire:shared.hijri-datepicker wire:model="hijriToDate" label="إلى تاريخ (هجري)" />
                </div>

                {{-- Stages: none chosen means the whole academy follows this period. --}}
                <div class="space-y-2">
                    <flux:label>المراحل التي تتبع هذه الفترة</flux:label>
                    <div class="flex flex-wrap gap-2">
                        @foreach($this->availableStages as $stage)
                            <label class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <input type="checkbox" wire:model="periodStageIds" value="{{ $stage->id }}" class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm">{{ $stage->name }}</span>
                            </label>
                        @endforeach
                    </div>
                    <flux:text class="text-xs text-zinc-500">
                        اترك الجميع دون تحديد لتسري الفترة على المجمع كله.
                    </flux:text>
                    <flux:error name="periodStageIds" />
                </div>

                <div class="space-y-2">
                    <flux:label>أيام الدوام في الأسبوع</flux:label>
                    <div class="flex flex-wrap gap-2">
                        @foreach([
                            1 => 'الأحد',
                            2 => 'الإثنين',
                            3 => 'الثلاثاء',
                            4 => 'الأربعاء',
                            5 => 'الخميس',
                            6 => 'الجمعة',
                            7 => 'السبت'
                        ] as $value => $label)
                            <label class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <input type="checkbox" wire:model="selectedWeekdays" value="{{ $value }}" class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    <flux:error name="selectedWeekdays" />
                </div>

                {{-- Single dates that override the weekly pattern in either direction. --}}
                <div class="space-y-3 p-3 rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <flux:label>أيام محددة</flux:label>
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="flex-1 min-w-[10rem]">
                            <livewire:shared.hijri-datepicker wire:model="newPeriodDate" label="اختر التاريخ" />
                        </div>
                        <flux:button type="button" size="sm" variant="filled" icon="plus" wire:click="addPeriodDate('extra')">
                            يوم دوام إضافي
                        </flux:button>
                        <flux:button type="button" size="sm" variant="filled" icon="minus" wire:click="addPeriodDate('excluded')">
                            يوم مستثنى
                        </flux:button>
                    </div>
                    <flux:error name="newPeriodDate" />

                    @foreach([
                        ['list' => 'extra', 'dates' => $periodExtraDates, 'title' => 'أيام دوام إضافية', 'tone' => 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800'],
                        ['list' => 'excluded', 'dates' => $periodExcludedDates, 'title' => 'أيام مستثناة من الدوام', 'tone' => 'bg-rose-50 dark:bg-rose-900/20 text-rose-700 dark:text-rose-300 border-rose-200 dark:border-rose-800'],
                    ] as $group)
                        @continue(empty($group['dates']))
                        <div class="space-y-1.5">
                            <p class="text-xs font-medium text-zinc-500">{{ $group['title'] }}</p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($group['dates'] as $date)
                                    <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg border text-xs {{ $group['tone'] }}">
                                        <span class="dir-ltr">{{ $date }}</span>
                                        <button type="button" wire:click="removePeriodDate('{{ $group['list'] }}', '{{ $date }}')" class="opacity-60 hover:opacity-100">
                                            <flux:icon icon="x-mark" class="size-3.5" />
                                        </button>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <flux:text class="text-xs text-zinc-500">
                        اليوم المستثنى يُلغي الدوام دائماً، حتى لو وافق يوماً من أيام الأسبوع المحددة.
                    </flux:text>
                </div>

                {{-- Working times, for display: a circle may meet more than once a day. --}}
                <div class="space-y-3 p-3 rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-center justify-between">
                        <flux:label>أوقات الدوام</flux:label>
                        <flux:button type="button" size="xs" variant="ghost" icon="plus" wire:click="addSession">إضافة وقت</flux:button>
                    </div>

                    @forelse($periodSessions as $index => $session)
                        <div class="flex items-end gap-2" wire:key="session-{{ $index }}">
                            <flux:input type="time" wire:model="periodSessions.{{ $index }}.from" label="من" class="w-28" />
                            <flux:input type="time" wire:model="periodSessions.{{ $index }}.to" label="إلى" class="w-28" />
                            <flux:input wire:model="periodSessions.{{ $index }}.label" label="التسمية (اختياري)" placeholder="الفترة الصباحية" class="flex-1" />
                            <flux:button type="button" size="sm" variant="subtle" icon="trash" wire:click="removeSession({{ $index }})" />
                        </div>
                        <flux:error name="periodSessions.{{ $index }}.to" />
                    @empty
                        <flux:text class="text-xs text-zinc-500">لم تُحدَّد أوقات. يمكن إضافة أكثر من وقت في اليوم الواحد.</flux:text>
                    @endforelse
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <flux:modal.close>
                    <flux:button variant="ghost">إلغاء</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">حفظ الفترة</flux:button>
            </div>
        </form>
    </flux:modal>

    @script
    <script>
        Alpine.data('calendarEvents', () => ({
            selectedDate: '',
            selectedDateHijri: '',
            dayEvents: [],
            
            openDay(date, hijriDay, monthName, events) {
                this.selectedDate = date;
                this.selectedDateHijri = `${hijriDay} ${monthName} ${$wire.year}`;
                this.dayEvents = events;
                
                if (this.dayEvents.length > 0) {
                    Flux.modal('day-events-modal').show();
                } else {
                    $wire.createNewEvent(date);
                }
            }
        }));
    </script>
    @endscript
</div>