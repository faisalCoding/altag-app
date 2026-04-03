<?php

use Livewire\Component;
use App\Models\AiInsight;
use App\Models\Attendance;
use App\Ai\Agents\PersonlanAssistant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    public function generateInsights()
    {
        // 1. Gather Data for the selected period
        $startDate = Carbon::createFromFormat('Y-m', $this->period)->startOfMonth()->format('Y-m-d');
        $endDate = Carbon::createFromFormat('Y-m', $this->period)->endOfMonth()->format('Y-m-d');

        // Attendance stats by student
        $studentStats = Attendance::with('student:id,name')
            ->whereBetween('date', [$startDate, $endDate])
            ->select('student_id', 'status', DB::raw('count(*) as count'))
            ->groupBy('student_id', 'status')
            ->get()
            ->groupBy('student_id')
            ->map(function ($group) {
                $studentName = $group->first()->student->name ?? 'مجهول';
                $statuses = $group->pluck('count', 'status')->toArray();
                return [
                    'name' => $studentName,
                    'present' => $statuses['present'] ?? 0,
                    'absent' => $statuses['absent'] ?? 0,
                    'late' => $statuses['late'] ?? 0,
                    'excused' => $statuses['excused'] ?? 0,
                ];
            })->values()->toArray();

        // Attendance stats by circle
        $circleStats = Attendance::with('circle:id,name')
            ->whereBetween('date', [$startDate, $endDate])
            ->select('circle_id', 'status', DB::raw('count(*) as count'))
            ->groupBy('circle_id', 'status')
            ->get()
            ->groupBy('circle_id')
            ->map(function ($group) {
                $circleName = $group->first()->circle->name ?? 'مجهولة';
                $statuses = $group->pluck('count', 'status')->toArray();
                return [
                    'name' => $circleName,
                    'present' => $statuses['present'] ?? 0,
                    'absent' => $statuses['absent'] ?? 0,
                    'late' => $statuses['late'] ?? 0,
                    'excused' => $statuses['excused'] ?? 0,
                ];
            })->values()->toArray();

        // 2. Prepare AI Prompt
        $dataToAnalyze = [
            'period' => $this->period,
            'students' => array_slice($studentStats, 0, 100), // limit to avoid token limit if very large
            'circles' => $circleStats,
        ];

        $prompt = "قم بتحليل بيانات الحضور والانصراف التالية لشهر {$this->period} واستخرج الأنماط. ابحث عن الأشياء المميزة مثل: الطلاب الأكثر التزامًا، الحلقات الأفضل أو الأسوأ أداءً، والطلاب الذين يتأخرون كثيرًا. \n\n"
            . "يجب أن يكون الرد عبارة عن مصفوفة JSON صالحة تفقط دون أي نص إضافي ، كل عنصر يحتوي على:\n"
            . "- category: (مثل: 'الطلاب', 'الحلقات')\n"
            . "- title: عنوان النمط (مثل: 'النخبة الملتزمون', 'نسب غياب مرتفعة')\n"
            . "- description: تفاصيل التحليل باللغة العربية بأسلوب رسمي وواضح\n"
            . "- type: (يجب أن يكون أحد هذه القيم فقط: 'positive', 'negative', 'neutral')\n\n"
            . json_encode($dataToAnalyze, JSON_UNESCAPED_UNICODE);

        // 3. Request AI Insight
        $assistant = PersonlanAssistant::make();
        $response = $assistant->prompt($prompt);
        $jsonText = $response->text;

        // Clean up markdown json tags if present
        $jsonText = str_replace(['```json', '```'], '', $jsonText);
        $jsonText = trim($jsonText);

        $parsed = json_decode($jsonText, true);

        if (is_array($parsed)) {
            // Remove old insights
            AiInsight::where('period', $this->period)->delete();

            // Save new insights
            foreach ($parsed as $insight) {
                AiInsight::create([
                    'period' => $this->period,
                    'category' => $insight['category'] ?? 'عام',
                    'title' => $insight['title'] ?? 'تحليل ذكي',
                    'description' => $insight['description'] ?? '',
                    'type' => in_array($insight['type'] ?? '', ['positive', 'negative', 'neutral']) ? $insight['type'] : 'neutral',
                ]);
            }
        }

        $this->loadInsights();
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