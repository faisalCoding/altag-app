<?php

use App\Ai\Agents\PersonlanAssistant;
use App\Models\AiInsight;
use App\Models\Manager;
use App\Models\Setting;
use App\Support\AiSettings;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Livewire\Livewire;

beforeEach(function () {
    $this->manager = Manager::factory()->create();
    $this->actingAs($this->manager, 'manager');
});

it('falls back to the configured default provider when nothing is chosen', function () {
    Setting::setVal(AiSettings::FALLBACK_PROVIDER_KEY, '');

    expect((new PersonlanAssistant)->provider())->toBe([config('ai.default') => null]);
});

it('fails over to the second provider chosen in settings', function () {
    Setting::setVal(AiSettings::PROVIDER_KEY, 'gemini');
    Setting::setVal(AiSettings::FALLBACK_PROVIDER_KEY, 'deepseek');

    expect(array_keys((new PersonlanAssistant)->provider()))->toBe(['gemini', 'deepseek']);
});

it('shows a rate limit notice instead of failing when generating insights', function () {
    AiInsight::create([
        'period' => '2026-07',
        'category' => 'الطلاب',
        'title' => 'تحليل سابق',
        'description' => 'يجب أن يبقى كما هو',
        'type' => 'neutral',
    ]);

    PersonlanAssistant::fake(function () {
        throw RateLimitedException::forProvider('gemini', 429);
    });

    Livewire::test('manager.ai-analysis')
        ->set('period', '2026-07')
        ->call('generateInsights')
        ->assertHasNoErrors();

    // The existing analysis must survive a failed refresh.
    expect(AiInsight::where('period', '2026-07')->count())->toBe(1)
        ->and(AiInsight::first()->title)->toBe('تحليل سابق');
});

it('keeps the existing insights when the provider errors', function () {
    AiInsight::create([
        'period' => '2026-07',
        'category' => 'الحلقات',
        'title' => 'تحليل سابق',
        'description' => 'يجب أن يبقى كما هو',
        'type' => 'positive',
    ]);

    PersonlanAssistant::fake(function () {
        throw new AiException('provider exploded');
    });

    Livewire::test('manager.ai-analysis')
        ->set('period', '2026-07')
        ->call('generateInsights')
        ->assertHasNoErrors();

    expect(AiInsight::where('period', '2026-07')->count())->toBe(1);
});

it('discards an unparsable analysis rather than wiping the saved one', function () {
    AiInsight::create([
        'period' => '2026-07',
        'category' => 'الطلاب',
        'title' => 'تحليل سابق',
        'description' => 'يجب أن يبقى كما هو',
        'type' => 'neutral',
    ]);

    PersonlanAssistant::fake(['this is not json at all']);

    Livewire::test('manager.ai-analysis')
        ->set('period', '2026-07')
        ->call('generateInsights')
        ->assertHasNoErrors();

    expect(AiInsight::where('period', '2026-07')->count())->toBe(1)
        ->and(AiInsight::first()->title)->toBe('تحليل سابق');
});

it('replaces the insights when the analysis parses', function () {
    AiInsight::create([
        'period' => '2026-07',
        'category' => 'الطلاب',
        'title' => 'تحليل قديم',
        'description' => 'سيُستبدل',
        'type' => 'neutral',
    ]);

    PersonlanAssistant::fake([json_encode([[
        'category' => 'الحلقات',
        'title' => 'أعلى الحلقات التزاماً',
        'description' => 'حلقة النور هي الأعلى حضوراً هذا الشهر.',
        'type' => 'positive',
    ]], JSON_UNESCAPED_UNICODE)]);

    Livewire::test('manager.ai-analysis')
        ->set('period', '2026-07')
        ->call('generateInsights')
        ->assertHasNoErrors();

    $insights = AiInsight::where('period', '2026-07')->get();

    expect($insights)->toHaveCount(1)
        ->and($insights->first()->title)->toBe('أعلى الحلقات التزاماً')
        ->and($insights->first()->type)->toBe('positive');
});

it('answers with a readable notice when the chat hits a rate limit', function () {
    PersonlanAssistant::fake(function () {
        throw RateLimitedException::forProvider('gemini', 429);
    });

    $component = Livewire::test('manager.ai-assistant')
        ->set('input', 'كم عدد الطلاب؟')
        ->call('ask')
        ->assertHasNoErrors();

    $messages = $component->get('messages');
    $reply = end($messages);

    expect($reply['role'])->toBe('ai')
        ->and($reply['content'])->toContain('تجاوزت حصة الطلبات')
        ->and($messages[count($messages) - 2]['content'])->toBe('كم عدد الطلاب؟');
});

it('answers with a readable notice when the chat errors for any other reason', function () {
    PersonlanAssistant::fake(function () {
        throw new RuntimeException('a tool blew up');
    });

    $component = Livewire::test('manager.ai-assistant')
        ->set('input', 'كم عدد الطلاب؟')
        ->call('ask')
        ->assertHasNoErrors();

    $messages = $component->get('messages');

    expect(end($messages)['content'])->toContain('حدث خطأ أثناء تجهيز الإجابة');
});

it('ignores an empty chat submission', function () {
    Livewire::test('manager.ai-assistant')
        ->set('input', '   ')
        ->call('ask')
        ->assertCount('messages', 1);
});

it('shows chat text as text, never as markup', function () {
    Livewire::test('manager.ai-assistant')
        ->set('messages', [['role' => 'ai', 'content' => '<img src=x onerror=alert(1)>']])
        ->assertDontSeeHtml('<img src=x onerror=alert(1)>')
        ->assertSeeHtml('&lt;img src=x onerror=alert(1)&gt;');
});
