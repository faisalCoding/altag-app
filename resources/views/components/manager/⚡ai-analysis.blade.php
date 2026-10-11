<?php

use Livewire\Component;
use App\Models\AiInsight;
use App\Models\Attendance;
use App\Ai\Agents\AttendanceInsights;
use App\Models\Circle;
use App\Models\Student;
use Flux\Flux;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;

new class extends Component
{
    public string $period = '';
    public $insights = [];

    public function mount()
    {
        $this->period = Carbon::now()->format('Y-m');
        $this->loadInsights();
    }

    public function updatedPeriod()
    {
        $this->loadInsights();
    }

    public function loadInsights()
    {
        $this->insights = AiInsight::where('period', $this->period)->get();
    }

    /** The most students sent for analysis: the most absent and late first. */
    private const MAX_STUDENTS = 400;

    private const STATUSES = ['present', 'absent', 'late', 'excused'];

    public function generateInsights()
    {
        $month = Carbon::createFromFormat('Y-m', $this->period);

        // Dates are stored with a time, so whereDate keeps the month's last day in.
        $counts = Attendance::query()
            ->whereDate('date', '>=', $month->copy()->startOfMonth()->toDateString())
            ->whereDate('date', '<=', $month->copy()->endOfMonth()->toDateString())
            ->select('student_id', 'circle_id', 'status', DB::raw('count(*) as count'))
            ->groupBy('student_id', 'circle_id', 'status')
            ->get();

        if ($counts->isEmpty()) {
            Flux::toast(__('لا سجلات حضور في هذا الشهر لتحليلها.'), variant: 'warning');

            return;
        }

        $studentNames = Student::whereIn('id', $counts->pluck('student_id')->unique())->pluck('name', 'id');
        $circleNames = Circle::whereIn('id', $counts->pluck('circle_id')->unique())->pluck('name', 'id');

        $circles = $counts->groupBy('circle_id')
            ->map(fn ($rows, $circleId) => ['name' => trim($circleNames[$circleId] ?? 'حلقة محذوفة')] + $this->tally($rows))
            ->values();

        // Every student, each under the circle most of their days were in; the
        // most absent and late first, so a cap drops the least telling.
        $students = $counts->groupBy('student_id')
            ->map(function ($rows, $studentId) use ($studentNames, $circleNames) {
                $circleId = $rows->groupBy('circle_id')->sortByDesc(fn ($days) => $days->sum('count'))->keys()->first();

                return [
                    'name' => trim($studentNames[$studentId] ?? 'طالب محذوف'),
                    'circle' => trim($circleNames[$circleId] ?? ''),
                ] + $this->tally($rows);
            })
            ->sortByDesc(fn ($student) => [$student['absent'] + $student['late'], $student['absent']])
            ->values();

        $data = [
            'month' => $this->period,
            'totals' => $this->tally($counts),
            'circles' => $circles->all(),
            'students' => $students->take(self::MAX_STUDENTS)->all(),
            'students_capped' => $students->count() > self::MAX_STUDENTS
                ? 'Only the '.self::MAX_STUDENTS.' students with the most absences and lateness are listed, of '.$students->count().'.'
                : null,
        ];

        try {
            $response = AttendanceInsights::make()->prompt(
                'بيانات حضور شهر '.$this->period.":\n".json_encode($data, JSON_UNESCAPED_UNICODE)
            );
        } catch (RateLimitedException $e) {
            report($e);
            Flux::toast(
                __('تجاوزت حصة الطلبات لدى مزوّد الذكاء الاصطناعي. أعد المحاولة بعد قليل.'),
                variant: 'warning',
            );

            return;
        } catch (AiException | ConnectionException $e) {
            report($e);
            Flux::toast(
                __('تعذّر الاتصال بمزوّد الذكاء الاصطناعي، ولم يتم تعديل التحليلات الحالية.'),
                variant: 'danger',
            );

            return;
        } catch (\Throwable $e) {
            report($e);
            Flux::toast(
                __('حدث خطأ أثناء توليد التحليل، ولم يتم تعديل التحليلات الحالية.'),
                variant: 'danger',
            );

            return;
        }

        $insights = collect($response['insights'] ?? [])
            ->filter(fn ($insight) => is_array($insight) && filled($insight['title'] ?? null))
            ->values();

        if ($insights->isEmpty()) {
            Flux::toast(
                __('لم يُرجِع المساعد الذكي تحليلاً مفهوماً. أعد المحاولة.'),
                variant: 'warning',
            );

            return;
        }

        DB::transaction(function () use ($insights) {
            AiInsight::where('period', $this->period)->delete();

            foreach ($insights as $insight) {
                AiInsight::create([
                    'period' => $this->period,
                    'category' => filled($insight['category'] ?? null) ? $insight['category'] : 'عام',
                    'title' => $insight['title'],
                    'description' => (string) ($insight['description'] ?? ''),
                    'type' => in_array($insight['type'] ?? '', ['positive', 'negative', 'neutral'], true) ? $insight['type'] : 'neutral',
                ]);
            }
        });

        $this->loadInsights();

        Flux::toast(__('تم تحديث التحليل الذكي'), variant: 'success');
    }

    /**
     * Counts by status for a set of grouped attendance rows.
     *
     * @return array<string, int>
     */
    private function tally($rows): array
    {
        $tally = array_fill_keys(self::STATUSES, 0);

        foreach ($rows as $row) {
            if (array_key_exists($row->status, $tally)) {
                $tally[$row->status] += (int) $row->count;
            }
        }

        return $tally;
    }
};
?>

<div class="space-y-6">
    <!-- Header & Action -->
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-6 flex flex-col md:flex-row gap-4 items-center justify-between shadow-xs">
        <div>
            <flux:heading size="lg" class="font-bold flex items-center gap-2">
                <flux:icon.chart-bar class="w-6 h-6 text-indigo-500" />
                تحليل البيانات الذكي
            </flux:heading>
            <flux:subheading>اكتشاف الأنماط والتقييمات الذكية من خلال بيانات المجمع</flux:subheading>
        </div>

        <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
                <flux:text class="font-medium whitespace-nowrap">الفترة:</flux:text>
                <input type="month" wire:model.live="period" class="block w-full rounded-lg border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm focus:border-indigo-500 focus:ring-indigo-500 shadow-sm" />
            </div>

            <flux:button class="!bg-indigo-500 !hover:bg-indigo-600 text-white" icon="sparkles" wire:click="generateInsights" wire:loading.attr="disabled">
                تحديث التحليل الذكي
            </flux:button>
        </div>
    </div>

    <!-- Insights Display -->
    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="generateInsights" class="transition-opacity">
        @if(count($insights) > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($insights as $insight)
                    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-6 shadow-xs flex flex-col relative overflow-hidden">
                        <!-- Icon representation based on type -->
                        <div class="absolute top-0 left-0 p-4 opacity-10">
                            @if($insight->type === 'positive')
                                <flux:icon.check-badge class="w-12 h-12 text-emerald-500" />
                            @elseif($insight->type === 'negative')
                                <flux:icon.exclamation-triangle class="w-12 h-12 text-red-500" />
                            @else
                                <flux:icon.information-circle class="w-12 h-12 text-blue-500" />
                            @endif
                        </div>

                        <div class="flex items-center gap-2 mb-3 relative z-10">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                {{ $insight->type === 'positive' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400' : '' }}
                                {{ $insight->type === 'negative' ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400' : '' }}
                                {{ $insight->type === 'neutral' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' : '' }}
                            ">
                                {{ $insight->category }}
                            </span>
                        </div>
                        
                        <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-2 relative z-10">{{ $insight->title }}</h3>
                        <p class="text-zinc-600 dark:text-zinc-400 text-sm leading-relaxed relative z-10 wrap-break-word">{{ $insight->description }}</p>
                    </div>
                @endforeach
            </div>
        @else
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-12 flex flex-col items-center justify-center text-center shadow-xs">
                <div class="w-16 h-16 bg-zinc-50 dark:bg-zinc-800 rounded-full flex items-center justify-center mb-4 text-zinc-400 dark:text-zinc-500">
                    <flux:icon.magnifying-glass class="w-8 h-8" />
                </div>
                <flux:heading size="md" class="font-bold mb-1">لا توجد تحليلات لهذه الفترة</flux:heading>
                <flux:text class="max-w-md">اضغط على زر التحديث، وسيقوم المساعد الذكي بقراءة بيانات هذه الفترة وتحليلها لك.</flux:text>
            </div>
        @endif
    </div>

    <!-- AI Chat Box At the Bottom -->
    <div class="mt-8 border-t border-zinc-200 dark:border-zinc-800 pt-8">
        <div class="mb-4">
            <flux:heading size="lg" class="font-bold">هل لديك استفسار آخر؟</flux:heading>
            <flux:text>يمكنك سؤال المساعد الذكي حول أي بيانات أخرى محددة، أو التوسّع في القراءة عن المخرجات.</flux:text>
        </div>
        
<livewire:manager.ai-assistant />
    </div>

</div>