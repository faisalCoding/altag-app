<?php

use App\Models\ScheduleActivity;
use App\Models\ScheduleTrack;
use App\Models\Setting;
use App\Models\Stage;
use App\Services\ProgramScheduleService;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds «نوابغ المستقبل» as its first week was printed: the activity library
 * with its topic syllabi, the three programme tracks, week one of each, and
 * the poster settings. Each track is linked to the school stage whose name
 * matches it; «المتوسطة» has no namesake yet and waits to be linked by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (ScheduleTrack::query()->exists()) {
            return;
        }

        $activities = $this->seedActivities();
        $cell = fn (string $key, array $extra = []) => ['activity' => $activities[$key]] + $extra;
        $lesson = fn (string $topic) => $cell('lesson', ['detail' => $topic]);
        $skill = $cell('skill', ['detail' => 'مهارة التعاون مع الآخرين']);
        $story = $cell('story', ['title' => 'قصة نبي الله نوح عليه السلام']);
        $halqa = $cell('halqa');
        $maghrib = $cell('maghrib');
        $isha = $cell('isha');
        $challenge = $cell('challenge');
        $fun = fn (int $span) => $cell('fun', ['span' => $span]);

        $memo = [
            'wird' => 'الورد القرآني حسب الخطة الشخصية لكل طالب',
            'poem' => 'المنظومة البيضاء',
            'unit' => 'البيتان',
            'reps' => '٢٠',
            'verses' => ['١ - ٢', '٣ - ٤', '٥ - ٦', '٧ - ٨', '٩ - ١٠'],
        ];

        $this->seedTrack('المرحلة الأولية', 1, 7, ['اولي'], $memo, [
            0 => [$halqa, $challenge, $lesson('معنى الإيمان بالله'), $maghrib, $lesson('معنى الإيمان بالله'), $cell('lab'), $isha],
            1 => [$halqa, $challenge, $lesson('أركان الإسلام الخمسة'), $maghrib, $lesson('مصادر الإيمان: القرآن والسنة'), $cell('league'), $isha],
            2 => [$halqa, $challenge, $story, $maghrib, $story, $cell('genius'), $isha],
            3 => [$halqa, $challenge, $skill, $maghrib, $skill, $cell('hall'), $isha],
            4 => [$halqa, $fun(6)],
        ]);

        $this->seedTrack('المرحلة المتوسطة', 2, 5, ['متوسط'], $memo, [
            0 => [$halqa, $maghrib, $lesson('معنى الإيمان بالله'), $cell('hall'), $isha],
            1 => [$halqa, $maghrib, $lesson('مصادر الإيمان: القرآن والسنة'), $cell('lab'), $isha],
            2 => [$halqa, $maghrib, $story, $cell('league'), $isha],
            3 => [$halqa, $maghrib, $skill, $cell('genius'), $isha],
            4 => [$halqa, $fun(4)],
        ]);

        $this->seedTrack('المرحلة العليا', 3, 5, ['عليا'], $memo, [
            0 => [$halqa, $maghrib, $lesson('معنى الإيمان بالله'), $cell('league'), $isha],
            1 => [$halqa, $maghrib, $lesson('مصادر الإيمان: القرآن والسنة'), $cell('genius'), $isha],
            2 => [$halqa, $maghrib, $skill, $cell('hall'), $isha],
            3 => [$halqa, $maghrib, $story, $cell('lab'), $isha],
            4 => [$halqa, $fun(4)],
        ]);

        Setting::setVal(ProgramScheduleService::SETTING_KEY, json_encode([
            'title' => 'نوابغ المستقبل',
            'tagline' => "هنا تبدأ رحلة التعلم،\nوتنطلق طاقات التميّز",
            'start_date' => '2026-09-27',
            'weekdays' => [0, 1, 2, 3, 4],
            'logo' => ['path' => 'images/schedule/nawabegh.png', 'height' => 74],
            'partners' => [
                ['path' => 'images/schedule/rasikh.png', 'alt' => 'شركة راسخ', 'height' => 172],
                ['path' => 'images/schedule/sahel.png', 'alt' => 'مدارس الساحل الأهلية', 'height' => 86],
            ],
        ], JSON_UNESCAPED_UNICODE));
    }

    public function down(): void
    {
        ScheduleTrack::query()->delete();
        ScheduleActivity::query()->delete();
        Setting::where('key', ProgramScheduleService::SETTING_KEY)->delete();
    }

    /**
     * @return array<string, int>
     */
    private function seedActivities(): array
    {
        $prophets = ['آدم', 'إدريس', 'نوح', 'هود', 'صالح', 'إبراهيم', 'لوط', 'إسماعيل', 'إسحاق', 'يعقوب', 'يوسف',
            'أيوب', 'ذي الكفل', 'يونس', 'شعيب', 'موسى', 'هارون', 'إلياس', 'اليسع', 'داود', 'سليمان', 'زكريا', 'يحيى', 'عيسى'];

        $definitions = [
            'halqa' => ['name' => 'الحلقة القرآنية والأبيات الشعرية', 'icon' => 'book', 'color' => 'teal', 'is_routine' => true],
            'maghrib' => ['name' => 'صلاة المغرب', 'icon' => 'mosque', 'color' => 'brown', 'is_routine' => true],
            'isha' => ['name' => 'صلاة العشاء ثم الانصراف', 'icon' => 'mosque', 'color' => 'brown', 'second_icon' => 'door', 'second_color' => 'teal', 'default_time' => '٨:٣٠', 'is_routine' => true],
            'lesson' => ['name' => 'الدرس العلمي', 'icon' => 'bulb', 'color' => 'yellow', 'topics' => [
                'معنى الإيمان بالله', 'مصادر الإيمان: القرآن والسنة', 'أركان الإسلام الخمسة', 'أركان الإيمان الستة',
                'الإيمان بالملائكة', 'الإيمان بالكتب', 'الإيمان بالرسل', 'الإيمان باليوم الآخر',
                'الإيمان بالقضاء والقدر', 'توحيد الربوبية', 'توحيد الألوهية', 'توحيد الأسماء والصفات',
            ]],
            'story' => ['name' => 'قصص الأنبياء', 'icon' => 'book', 'color' => 'red', 'topics' => [
                ...array_map(fn (string $prophet) => "قصة نبي الله {$prophet} عليه السلام", $prophets),
                'سيرة نبينا محمد ﷺ',
            ]],
            'skill' => ['name' => 'الدورة المهارية', 'icon' => 'people', 'color' => 'teal', 'topics' => [
                'مهارة التعاون مع الآخرين', 'مهارة التواصل الفعّال', 'مهارة إدارة الوقت', 'مهارة حل المشكلات',
                'مهارة التفكير الإبداعي', 'مهارة القيادة', 'مهارة الحوار والإقناع', 'مهارة اتخاذ القرار',
            ]],
            'challenge' => ['name' => 'تحدي نابغة الثقافي', 'icon' => 'trophy', 'color' => 'gold'],
            'league' => ['name' => 'دوري نوابغ الرياضي', 'icon' => 'soccer', 'color' => 'green'],
            'hall' => ['name' => 'صالة نوابغ', 'icon' => 'basket', 'color' => 'orange'],
            'lab' => ['name' => 'معمل نوابغ للابتكار', 'icon' => 'flask', 'color' => 'teal'],
            'genius' => ['name' => 'عباقرة نوابغ', 'icon' => 'brain', 'color' => 'red'],
            'fun' => ['name' => 'البرنامج الترفيهي', 'icon' => 'party', 'color' => 'gold'],
        ];

        $ids = [];
        $order = 0;

        foreach ($definitions as $key => $attributes) {
            $ids[$key] = ScheduleActivity::create($attributes + ['sort_order' => $order++])->id;
        }

        return $ids;
    }

    /**
     * @param  array<int, string>  $stageNameHints
     * @param  array<string, mixed>  $memo
     * @param  array<int, array<int, array<string, mixed>>>  $days
     */
    private function seedTrack(string $name, int $order, int $columns, array $stageNameHints, array $memo, array $days): void
    {
        $track = ScheduleTrack::create(['name' => $name, 'columns' => $columns, 'sort_order' => $order]);

        $normalize = fn (string $text) => preg_replace('/[أإآ]/u', 'ا', $text);
        // The stage ordering scope sorts by a column a later migration adds.
        $stageIds = Stage::query()->withoutGlobalScope('ordered')->get(['id', 'name'])
            ->filter(fn (Stage $stage) => collect($stageNameHints)->contains(fn (string $hint) => str_contains($normalize($stage->name), $hint)))
            ->pluck('id');
        $track->stages()->sync($stageIds);

        $week = $track->weeks()->create(['week_number' => 1, 'memo' => $memo]);

        foreach ($days as $weekday => $cells) {
            foreach ($cells as $position => $cell) {
                $week->cells()->create([
                    'weekday' => $weekday,
                    'position' => $position,
                    'span' => $cell['span'] ?? 1,
                    'schedule_activity_id' => $cell['activity'],
                    'title' => $cell['title'] ?? null,
                    'detail' => $cell['detail'] ?? null,
                ]);
            }
        }
    }
};
