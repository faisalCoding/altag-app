<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendGuardianWhatsappJob implements ShouldQueue
{
    use Queueable;

    /**
     * Sending waits a random human-like pause first, so the job needs more than
     * the default 60s before the worker considers it stuck.
     */
    public int $timeout = 120;

    /**
     * Sessions are closed when idle and woken by the first message, so a refusal
     * is usually "not warm yet" rather than "will never work". The message waits
     * rather than being dropped, which is what the old swallow-and-log did.
     */
    public int $tries = 5;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function __construct(
        public string $phone,
        public string $message,
        public string $senderClientId,
    ) {}

    /**
     * Deliver the guardian message through the WhatsApp gateway, reusing the same
     * phone normalization and session-based send as SendWhatsappTasksJob.
     */
    public function handle(): void
    {
        $phone = preg_replace('/[^0-9]/', '', $this->phone);

        if ($phone === '') {
            return;
        }

        self::humanPause();

        if (str_starts_with($phone, '0')) {
            $phone = '966'.substr($phone, 1);
        } elseif (str_starts_with($phone, '5')) {
            $phone = '966'.$phone;
        }

        try {
            $url = config('services.whatsapp.url');
            $response = Http::withHeaders(['X-Api-Key' => config('services.whatsapp.key')])->timeout(10)->post("{$url}/send", [
                'clientId' => $this->senderClientId,
                'phone' => $phone,
                'message' => $this->message,
            ]);

            if ($response->successful()) {
                return;
            }

            // The gateway says so itself when a session is merely warming up.
            if ((bool) $response->json('retryable') && $this->attempts() < $this->tries) {
                $this->release($this->backoff()[$this->attempts() - 1] ?? 300);

                return;
            }

            Log::error("Failed to send WhatsApp to guardian phone {$phone}: ".$response->body());
        } catch (\Exception $e) {
            Log::error("Exception while sending WhatsApp to guardian phone {$phone}: ".$e->getMessage());
        }
    }

    /**
     * Pause for a random interval between sends so bulk notifications (e.g. marking
     * a whole class absent) trickle out at a human pace instead of a burst that
     * WhatsApp flags as spam. The queue runs a single worker, so this pause
     * naturally spaces consecutive messages. Set both config values to 0 to disable.
     */
    public static function humanPause(): void
    {
        $min = max(0, (int) config('services.whatsapp.send_delay_min'));
        $max = max($min, (int) config('services.whatsapp.send_delay_max'));

        if ($max > 0) {
            sleep(random_int($min, $max));
        }
    }
}
