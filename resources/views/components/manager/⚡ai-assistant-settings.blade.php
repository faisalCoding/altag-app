<?php

use Livewire\Component;
use App\Ai\Agents\KeyCheck;
use App\Models\AiProviderCredential;
use App\Models\Setting;
use App\Support\AiSettings;
use Flux\Flux;
use Illuminate\Support\Str;

new class extends Component
{
    public string $provider = '';

    public string $model = '';

    public string $fallbackProvider = '';

    /**
     * Write-only key inputs, one per provider. A stored key is never loaded
     * into these, so the browser is never sent an existing key.
     *
     * @var array<string, string>
     */
    public array $newKeys = [];

    /** @var array<string, string> */
    public array $baseUrls = [];

    public function mount(): void
    {
        $this->provider = AiSettings::provider();
        $this->model = (string) AiSettings::model();
        $this->fallbackProvider = (string) AiSettings::fallbackProvider();

        $credentials = AiSettings::credentials();

        foreach (array_keys(AiSettings::TEXT_PROVIDERS) as $provider) {
            $this->newKeys[$provider] = '';
            $this->baseUrls[$provider] = (string) ($credentials[$provider]['base_url'] ?? '');
        }
    }

    /**
     * A model id belongs to the provider it was typed for: a new provider
     * starts from its own default rather than sending it another's model.
     */
    public function updatedProvider(): void
    {
        $this->model = '';
        $this->resetErrorBag('model');

        if ($this->fallbackProvider === $this->provider) {
            $this->fallbackProvider = '';
        }
    }

    public function save(): void
    {
        $this->validate([
            'provider' => 'required|string|in:'.implode(',', array_keys(AiSettings::TEXT_PROVIDERS)),
            'model' => 'nullable|string|max:191',
            'fallbackProvider' => 'nullable|string|in:'.implode(',', array_keys(AiSettings::TEXT_PROVIDERS)),
        ], [
            'provider.in' => 'المزوّد المختار غير مدعوم.',
            'fallbackProvider.in' => 'المزوّد الاحتياطي غير مدعوم.',
        ]);

        if ($this->fallbackProvider === $this->provider) {
            $this->fallbackProvider = '';
        }

        if ($owner = AiSettings::modelOwnedElsewhere($this->provider, trim($this->model))) {
            $this->addError('model', 'هذا الموديل من موديلات '.AiSettings::TEXT_PROVIDERS[$owner]['label'].'، فلن يقبله '
                .AiSettings::TEXT_PROVIDERS[$this->provider]['label'].'. اختر موديلاً من المقترحات أو اترك الخانة فارغة.');

            return;
        }

        Setting::setVal(AiSettings::PROVIDER_KEY, $this->provider);
        Setting::setVal(AiSettings::MODEL_KEY, trim($this->model));
        Setting::setVal(AiSettings::FALLBACK_PROVIDER_KEY, $this->fallbackProvider);

        if (! AiSettings::hasKey($this->provider)) {
            Flux::toast(
                'تم الحفظ، لكن لا يوجد مفتاح لهذا المزوّد بعد — أضِفه أدناه وإلا فشلت طلبات المساعد.',
                variant: 'warning',
            );

            return;
        }

        Flux::toast('تم حفظ إعدادات المساعد الذكي', variant: 'success');
    }

    public function saveKey(string $provider): void
    {
        abort_unless(AiSettings::isSupportedProvider($provider), 404);

        $key = trim($this->newKeys[$provider] ?? '');
        $baseUrl = trim($this->baseUrls[$provider] ?? '');

        if ($key === '' && $baseUrl === '') {
            Flux::toast('أدخل المفتاح أولاً', variant: 'warning');

            return;
        }

        $credential = AiProviderCredential::firstOrNew(['provider' => $provider]);

        // An empty input means "leave the stored key alone", not "erase it".
        if ($key !== '') {
            $credential->api_key = $key;
        }

        $credential->base_url = $baseUrl ?: null;
        $credential->save();

        $this->newKeys[$provider] = '';

        Flux::toast('تم حفظ مفتاح '.AiSettings::TEXT_PROVIDERS[$provider]['label'], variant: 'success');
    }

    /**
     * Send the provider the smallest request it answers, with the stored key
     * and the model chosen on this page, and say whether it worked.
     */
    public function testKey(string $provider): void
    {
        abort_unless(AiSettings::isSupportedProvider($provider), 404);

        $label = AiSettings::TEXT_PROVIDERS[$provider]['label'];

        if (! AiSettings::hasKey($provider)) {
            Flux::toast('لا يوجد مفتاح لـ'.$label.' بعد؛ احفظه أولاً ثم اختبره.', variant: 'warning');

            return;
        }

        AiSettings::apply();
        $model = $provider === $this->provider && trim($this->model) !== '' ? trim($this->model) : null;

        try {
            $reply = KeyCheck::make()->prompt('ping', provider: $provider, model: $model, timeout: 30);
        } catch (\Throwable $e) {
            report($e);
            Flux::toast('لم ينجح الاتصال بـ'.$label.': '.Str::limit($e->getMessage(), 180), variant: 'danger', duration: 10000);

            return;
        }

        Flux::toast('يعمل مفتاح '.$label.'، وأجاب الموديل '.($reply->meta->model ?? $model ?? 'الافتراضي').'.', variant: 'success');
    }

    public function deleteKey(string $provider): void
    {
        abort_unless(AiSettings::isSupportedProvider($provider), 404);

        AiProviderCredential::where('provider', $provider)->delete();

        $this->newKeys[$provider] = '';
        $this->baseUrls[$provider] = '';

        Flux::toast('تم حذف المفتاح المخزَّن', variant: 'success');
    }

    public function with(): array
    {
        $credentials = AiSettings::credentials();

        return [
            'providers' => AiSettings::TEXT_PROVIDERS,
            'storedKeys' => AiProviderCredential::all()
                ->mapWithKeys(fn ($credential) => [$credential->provider => $credential->maskedKey()])
                ->all(),
            'unreadableKeys' => AiProviderCredential::all()
                ->filter(fn ($credential) => $credential->isUnreadable())
                ->pluck('provider')
                ->all(),
            'envKeys' => collect(AiSettings::TEXT_PROVIDERS)
                ->mapWithKeys(fn ($meta, $provider) => [
                    $provider => blank($credentials[$provider]['api_key'] ?? null)
                        && filled(config('ai.providers.'.$provider.'.key')),
                ])->all(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-6 shadow-xs space-y-5">
        <div>
            <flux:heading size="lg" class="font-bold flex items-center gap-2">
                <flux:icon.sparkles class="w-5 h-5 text-indigo-500" />
                موديل المساعد الذكي
            </flux:heading>
            <flux:subheading>
                يُستخدم هذا الموديل في محادثة المساعد وفي زر تحديث التحليل الذكي معاً.
            </flux:subheading>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <flux:select wire:model.live="provider" label="المزوّد">
                @foreach ($providers as $key => $meta)
                    <flux:select.option value="{{ $key }}">{{ $meta['label'] }}</flux:select.option>
                @endforeach
            </flux:select>

            <div>
                <flux:input wire:model="model" label="الموديل" list="ai-model-suggestions"
                    placeholder="اتركه فارغاً لاستخدام الافتراضي" />
                <flux:error name="model" />
                <datalist id="ai-model-suggestions">
                    @foreach ($providers[$provider]['models'] ?? [] as $suggestion)
                        <option value="{{ $suggestion }}"></option>
                    @endforeach
                </datalist>
                <p class="mt-1 text-xs text-zinc-500">
                    تستطيع كتابة أي معرّف موديل يقبله المزوّد، لا المقترحات فقط.
                </p>
            </div>

            <flux:select wire:model="fallbackProvider" label="مزوّد احتياطي (اختياري)">
                <flux:select.option value="">بدون</flux:select.option>
                @foreach ($providers as $key => $meta)
                    @if ($key !== $provider)
                        <flux:select.option value="{{ $key }}">{{ $meta['label'] }}</flux:select.option>
                    @endif
                @endforeach
            </flux:select>
        </div>

        <flux:text class="text-xs text-zinc-500">
            يتحوّل المساعد تلقائياً إلى المزوّد الاحتياطي عند تجاوز الحصة أو ازدحام المزوّد أو نفاد الرصيد.
            ويستخدم الاحتياطي موديله الافتراضي، لأن معرّف الموديل خاص بمزوّده.
        </flux:text>

        <div class="flex justify-end">
            <flux:button variant="primary" icon="check" wire:click="save">حفظ الإعدادات</flux:button>
        </div>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-6 shadow-xs space-y-5">
        <div>
            <flux:heading size="lg" class="font-bold flex items-center gap-2">
                <flux:icon.key class="w-5 h-5 text-indigo-500" />
                مفاتيح المزوّدين
            </flux:heading>
            <flux:subheading>
                المفاتيح تُخزَّن مشفَّرة في قاعدة البيانات، ولا تُعرض بعد حفظها — تظهر آخر أربعة رموز فقط للتأكد.
            </flux:subheading>
        </div>

        <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-100 dark:border-amber-900/50">
            <div class="flex gap-3">
                <flux:icon.exclamation-triangle class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0" />
                <flux:text class="text-sm text-amber-900 dark:text-amber-200">
                    المفتاح المحفوظ هنا يدخل ضمن النسخ الاحتياطية للقاعدة. التشفير يعتمد على مفتاح التطبيق
                    (<span class="font-mono">APP_KEY</span>)، فمن يملك النسخة الاحتياطية ومفتاح التطبيق معاً يستطيع قراءته.
                </flux:text>
            </div>
        </div>

        <div class="space-y-3">
            @foreach ($providers as $key => $meta)
                <div class="p-4 rounded-xl border border-zinc-100 dark:border-zinc-800 space-y-3">
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-sm">{{ $meta['label'] }}</span>
                            @if ($key === $provider)
                                <flux:badge size="sm" color="indigo">المزوّد الحالي</flux:badge>
                            @endif
                            @if ($key === $fallbackProvider && $key !== $provider)
                                <flux:badge size="sm" variant="neutral">احتياطي</flux:badge>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            @if (in_array($key, $unreadableKeys, true))
                                <flux:badge size="sm" color="amber">تعذّرت قراءة المفتاح المحفوظ، فأعد إدخاله</flux:badge>
                                <flux:button size="xs" variant="ghost" icon="trash"
                                    wire:click="deleteKey('{{ $key }}')">
                                    حذف
                                </flux:button>
                            @elseif (! empty($storedKeys[$key]))
                                <flux:badge size="sm" color="green">
                                    محفوظ: {{ $storedKeys[$key] }}
                                </flux:badge>
                                <flux:button size="xs" variant="ghost" icon="trash"
                                    wire:click="deleteKey('{{ $key }}')"
                                    wire:confirm="سيتوقف المساعد عن استخدام هذا المزوّد. هل تريد حذف المفتاح؟">
                                    حذف
                                </flux:button>
                            @elseif ($envKeys[$key] ?? false)
                                <flux:badge size="sm" variant="neutral">مضبوط من ملف البيئة</flux:badge>
                            @elseif ($key === 'ollama')
                                <flux:badge size="sm" variant="neutral">لا يحتاج مفتاحاً</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber">لا يوجد مفتاح</flux:badge>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-1 {{ $meta['needs_url'] ? 'md:grid-cols-3' : 'md:grid-cols-2' }} gap-3 items-end">
                        <flux:input type="password" wire:model="newKeys.{{ $key }}"
                            placeholder="{{ ! empty($storedKeys[$key]) ? 'اتركه فارغاً للإبقاء على المفتاح الحالي' : 'الصق المفتاح هنا' }}"
                            autocomplete="off" class="font-mono" />

                        @if ($meta['needs_url'])
                            <flux:input wire:model="baseUrls.{{ $key }}" placeholder="عنوان الخادم (اختياري)"
                                autocomplete="off" class="font-mono" />
                        @endif

                        <div class="flex gap-2">
                            <flux:button size="sm" variant="filled" icon="check"
                                wire:click="saveKey('{{ $key }}')">
                                حفظ المفتاح
                            </flux:button>
                            @if (! empty($storedKeys[$key]) || ($envKeys[$key] ?? false) || $key === 'ollama')
                                <flux:button size="sm" variant="ghost" icon="signal"
                                    wire:click="testKey('{{ $key }}')" wire:target="testKey('{{ $key }}')">
                                    اختبار
                                </flux:button>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
