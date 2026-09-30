<?php

namespace App\Jobs;

use App\Support\HijriDate;
use App\Support\WhatsappGateway;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWhatsappTasksJob implements ShouldQueue
{
    use Queueable;

    /**
     * The job loops over every assignee with a human-like pause between sends,
     * so it needs far more than the default 60s worker timeout.
     */
    public int $timeout = 1800;

    /**
     * This job sends to many people in one pass, so it must never be retried
     * after it has started delivering — the readiness check below happens before
     * the first message precisely so a retry costs nobody a duplicate.
     */
    public int $tries = 3;

    public $teachersTasks;

    public string $senderClientId;

    public string $format;

    /**
     * Create a new job instance.
     */
    public function __construct(array $teachersTasks, string $senderClientId, string $format = 'standard')
    {
        $this->teachersTasks = $teachersTasks;
        $this->senderClientId = $senderClientId;
        $this->format = $format;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Before the first message, not between them: a session closed for idleness
        // needs a minute to come back, and finding that out half way through the
        // list would mean either dropping the rest or re-sending to those already
        // reached when the job is retried.
        if (! WhatsappGateway::waitUntilReady($this->senderClientId)) {
            Log::warning("WhatsApp session [{$this->senderClientId}] is not ready; deferring the task broadcast.");

            $this->release(120);

            return;
        }

        foreach ($this->teachersTasks as $data) {
            $assignee = array_key_exists('assignee', $data) ? $data['assignee'] : ($data['teacher'] ?? null);
            $tasks = $data['tasks'] ?? [];

            if (! $assignee || ! $assignee->phone) {
                continue;
            }

            // معالجة رقم الهاتف (حذف الصفر وإضافة 966)
            $phone = preg_replace('/[^0-9]/', '', $assignee->phone);

            if (str_starts_with($phone, '0')) {
                $phone = '966'.substr($phone, 1);
            } elseif (str_starts_with($phone, '5')) {
                $phone = '966'.$phone;
            }

            // بناء الرسالة: تجميع المهام حسب الحدث ثم التصنيف
            $message = $this->buildMessage($assignee, $tasks);

            // إرسال الطلب لخدمة الواتساب باستخدام جلسة المُرسِل
            // مع فاصل زمني عشوائي بين الرسائل لتجنب اعتبارها سيلاً من الرسائل (Spam)
            SendGuardianWhatsappJob::humanPause();

            try {
                $url = config('services.whatsapp.url');
                $response = Http::withHeaders(['X-Api-Key' => config('services.whatsapp.key')])->timeout(10)->post("{$url}/send", [
                    'clientId' => $this->senderClientId,
                    'phone' => $phone,
                    'message' => $message,
                ]);

                if (! $response->successful()) {
                    Log::error("Failed to send WhatsApp tasks to assignee {$assignee->id}: ".$response->body());
                }
            } catch (\Exception $e) {
                Log::error("Exception while sending WhatsApp tasks to assignee {$assignee->id}: ".$e->getMessage());
            }
        }
    }

    /**
     * Build the WhatsApp message with the new format.
     *
     * Groups tasks by event > category, with numbered lists and Hijri due dates.
     */
    private function buildMessage(object $assignee, array $tasks): string
    {
        if ($this->format === 'reminder') {
            $firstName = explode(' ', trim($assignee->name))[0] ?? '';

            return "السلام عليكم ورحمة الله وبركاته\nصباح الخير\nكيف حالك ا. {$firstName}\nايش صار في المهام الباقية الي عليك ؟";
        }

        // تجميع المهام حسب الحدث ثم التصنيف
        $grouped = [];

        foreach ($tasks as $task) {
            // نحصل على اسم الحدث (قد يكون للمهمة أكثر من حدث)
            $eventName = null;
            if ($task->events && $task->events->isNotEmpty()) {
                $eventName = $task->events->first()->event_name;
            }

            $eventKey = $eventName ?? '__general__';
            $categoryName = $task->category?->name ?? 'عام';

            if (! isset($grouped[$eventKey])) {
                $grouped[$eventKey] = [
                    'name' => $eventName,
                    'categories' => [],
                ];
            }

            if (! isset($grouped[$eventKey]['categories'][$categoryName])) {
                $grouped[$eventKey]['categories'][$categoryName] = [];
            }

            $grouped[$eventKey]['categories'][$categoryName][] = $task;
        }

        $messages = [];

        foreach ($grouped as $eventData) {
            $eventName = $eventData['name'];

            $msg = 'السلام عليكم ورحمة الله وبركاته';
            if ($eventName) {
                $msg .= " اذكرك عندنا *{$eventName}* ان شاء الله";
            }
            $msg .= "\nو المهام الي عليك\n";

            foreach ($eventData['categories'] as $categoryName => $categoryTasks) {
                $msg .= "\n*{$categoryName}*\n";
                foreach ($categoryTasks as $index => $task) {
                    $num = $index + 1;
                    $dueDateHijri = $task->due_date
                        ? $this->toHijriDate($task->due_date)
                        : 'بدون موعد';
                    $msg .= "{$num}. {$task->title} اخر موعد لاتمام المهمة  {$dueDateHijri}\n";
                }
            }

            $msg .= "\nجزيت خيرا 🌹";
            $messages[] = $msg;
        }

        return implode("\n\n---\n\n", $messages);
    }

    /**
     * Convert a date to Hijri format (Arabic).
     */
    private function toHijriDate(mixed $date): string
    {
        try {
            $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

            return HijriDate::full($carbon);
        } catch (\Exception $e) {
            return $date instanceof Carbon ? $date->format('m-d') : (string) $date;
        }
    }
}
