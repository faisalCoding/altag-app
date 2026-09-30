<?php

namespace App\Livewire\Manager;

use App\Support\WhatsappGateway;
use Flux\Flux;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class WhatsappSettings extends Component
{
    public $status = 'loading';

    public $message = 'جاري التحقق من حالة الواتساب...';

    public $qrCode = null;

    public string $clientId = '';

    public function mount(): void
    {
        $user = auth()->user();
        $role = class_basename(get_class($user));
        $this->clientId = strtolower($role).'_'.$user->id;
        $this->checkStatus();
    }

    public function checkStatus(): void
    {
        try {
            $url = config('services.whatsapp.url');
            $response = Http::withHeaders(['X-Api-Key' => config('services.whatsapp.key')])->timeout(1)->get("{$url}/status/{$this->clientId}");
            if ($response->successful()) {
                $data = $response->json();
                $this->status = $data['status'] ?? 'unknown';
                $this->message = $data['message'] ?? '';
                $this->qrCode = $data['qr_image'] ?? null;
            } else {
                $this->status = 'error';
                $this->message = 'لا يمكن الاتصال بخدمة الواتساب.';
            }
        } catch (\Exception $e) {
            $this->status = 'error';
            $this->message = 'تأكد من تشغيل خادم Node.js الخاص بالواتساب.';
        }
    }

    /**
     * Start the session deliberately. Reading the status no longer launches a
     * browser, so this is the only way a person brings one up.
     */
    public function connect(): void
    {
        try {
            WhatsappGateway::connect($this->clientId);
            $this->status = 'starting';
            $this->message = 'جاري تشغيل الجلسة...';
            $this->qrCode = null;
        } catch (\Exception $e) {
            $this->status = 'error';
            $this->message = 'تعذّر الاتصال بخدمة الواتساب.';
        }
    }

    public function disconnect(): void
    {
        try {
            $url = config('services.whatsapp.url');
            Http::withHeaders(['X-Api-Key' => config('services.whatsapp.key')])->timeout(5)->post("{$url}/disconnect/{$this->clientId}");
            $this->status = 'starting';
            $this->message = 'جاري إعادة التهيئة...';
            $this->qrCode = null;
        } catch (\Exception $e) {
            // ignore
        }
    }

    public function resetSession(): void
    {
        try {
            $url = config('services.whatsapp.url');
            Http::withHeaders(['X-Api-Key' => config('services.whatsapp.key')])->timeout(5)->post("{$url}/reset/{$this->clientId}");
            $this->status = 'starting';
            $this->message = 'جاري إعادة التهيئة بالكامل...';
            $this->qrCode = null;
        } catch (\Exception $e) {
            // ignore
        }
    }

    public function startNodeServer(): void
    {
        $basePath = base_path('whatsapp-service');

        // Check if running on port 3000
        $output = shell_exec('lsof -nP -i :3000 2>/dev/null');
        $isRunning = $output && strpos($output, 'node') !== false;

        if (! $isRunning) {
            // Using pclose(popen()) guarantees that PHP will not wait for the process to finish
            pclose(popen("cd {$basePath} && nohup node index.js > node.log 2>&1 &", 'r'));
            sleep(2); // Wait a moment for it to initialize
            $this->checkStatus();
            Flux::toast('تم إرسال أمر تشغيل الخادم.', variant: 'success');
        } else {
            Flux::toast('الخادم يعمل مسبقاً!', variant: 'warning');
            $this->checkStatus();
        }
    }

    public function render()
    {
        return view('livewire.manager.whatsapp-settings');
    }
}
