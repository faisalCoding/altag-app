<div @if(!in_array($status, ['ready', 'error', 'stopped'])) wire:poll.5s="checkStatus" @endif class="space-y-6">
    <div class="flex items-center gap-3">
        <div class="p-2.5 rounded-xl bg-maroon/10 text-maroon dark:bg-white/10 dark:text-white">
            <flux:icon icon="chat-bubble-left-right" />
        </div>
        <div>
            <flux:heading size="xl" class="font-bold text-zinc-900 dark:text-white">إعدادات ربط الواتساب</flux:heading>
            <flux:subheading class="text-zinc-400">الرئيسية</flux:subheading>
        </div>
    </div>

    <flux:card class="max-w-2xl">
        <div class="flex items-center gap-4 mb-6">
            <div class="size-12 rounded-full flex items-center justify-center shrink-0
                {{ $status === 'ready' ? 'bg-emerald-100 text-emerald-600' : ($status === 'error' ? 'bg-red-100 text-red-600' : ($status === 'stopped' ? 'bg-zinc-100 text-zinc-500' : 'bg-amber-100 text-amber-600')) }}">
                @if($status === 'ready')
                    <flux:icon icon="check-circle" variant="solid" class="size-8" />
                @elseif($status === 'error')
                    <flux:icon icon="exclamation-circle" variant="solid" class="size-8" />
                @elseif($status === 'stopped')
                    <flux:icon icon="power" class="size-7" />
                @else
                    <flux:icon icon="arrow-path" class="size-6 animate-spin" />
                @endif
            </div>

            <div class="flex-1">
                <h3 class="text-lg font-bold">
                    @if($status === 'ready') متصل بالواتساب
                    @elseif($status === 'needs_scan') بانتظار مسح الرمز (QR Code)
                    @elseif($status === 'loading') جاري مزامنة المحادثات...
                    @elseif($status === 'starting') جاري التهيئة...
                    @elseif($status === 'disconnected') انقطع الاتصال
                    @elseif($status === 'stopped') الجلسة متوقفة
                    @else خدمة الواتساب غير متصلة @endif
                </h3>
                <p class="text-sm text-zinc-500">{{ $message }}</p>
                <p class="text-xs text-zinc-400 mt-1">معرف الجلسة: <code class="font-mono">{{ $clientId }}</code></p>
            </div>

            <div class="flex flex-col gap-2 shrink-0">
                @if(in_array($status, ['stopped', 'disconnected']))
                    <flux:button wire:click="connect" size="sm" variant="primary" class="w-full">
                        ربط الواتساب
                    </flux:button>
                @endif
                @if($status === 'ready')
                    <flux:button wire:click="disconnect" size="sm" variant="ghost" class="text-zinc-500 hover:text-zinc-700 w-full text-right">
                        إيقاف الاتصال
                    </flux:button>
                @endif
                <flux:button wire:click="resetSession" wire:confirm="هل أنت متأكد؟ سيتم حذف الجلسة بالكامل وستحتاج إلى مسح رمز QR من جديد." size="sm" variant="danger" class="w-full">
                    إعادة تعيين الجلسة (إصلاح التعليق)
                </flux:button>
            </div>
        </div>

        @if($status === 'needs_scan' && $qrCode)
            <div class="flex flex-col items-center justify-center p-8 bg-zinc-50 dark:bg-zinc-800/50 rounded-xl border border-zinc-200 dark:border-zinc-700">
                <p class="mb-4 text-center text-zinc-600 dark:text-zinc-400 font-medium">افتح تطبيق واتساب على هاتفك، اذهب إلى "الأجهزة المرتبطة" وامسح الرمز أدناه:</p>
                <div class="bg-white p-4 rounded-xl shadow-sm inline-block">
                    <img src="{{ $qrCode }}" alt="WhatsApp QR Code" class="size-64">
                </div>
            </div>
        @endif

        @if($status === 'ready')
            <div class="p-4 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-800 dark:text-emerald-300 rounded-xl border border-emerald-200 dark:border-emerald-800">
                الخدمة جاهزة الآن. يمكنك إرسال المهام للمعلمين عبر زر "إرسال المهام" في صفحة إدارة المهام.
            </div>
        @endif

        @if($status === 'error')
            <div class="p-4 bg-red-50 dark:bg-red-900/20 text-red-800 dark:text-red-300 rounded-xl border border-red-200 dark:border-red-800 text-sm flex flex-col gap-3">
                <p>يرجى التأكد من تشغيل خادم الواتساب (Node.js).</p>
                <flux:button wire:click="startNodeServer" size="sm" variant="danger" class="w-fit" icon="play">
                    محاولة تشغيل الخادم الآن
                </flux:button>
            </div>
        @endif
    </flux:card>

    <div class="max-w-2xl">
        <livewire:shared.whatsapp-bulk-links :client-id="$clientId" />
    </div>
</div>