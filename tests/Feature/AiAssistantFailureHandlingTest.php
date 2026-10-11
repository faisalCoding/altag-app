<?php

use App\Ai\Agents\AttendanceInsights;
use App\Ai\Agents\PersonlanAssistant;
use App\Models\AiInsight;
use App\Models\Attendance;
use App\Models\Circle;
use App\Models\Manager;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\AiSettings;
use App\Support\MagicLinkTray;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;

beforeEach(function () {
    $this->manager = Manager::factory()->create();
    $this->actingAs($this->manager, 'manager');

    // A July with a record on its last day, which is stored with a time.
    $this->circle = Circle::factory()->create(['name' => 'حلقة النور']);
    $this->teacher = Teacher::factory()->create();
    $this->student = Student::factory()->create(['name' => 'سالم', 'circle_id' => $this->circle->id]);

    foreach (['2026-07-01' => 'present', '2026-07-15' => 'late', '2026-07-31' => 'absent'] as $date => $status) {
        Attendance::create([
            'student_id' => $this->student->id, 'circle_id' => $this->circle->id, 'teacher_id' => $this->teacher->id,
            'date' => $date, 'status' => $status,
        ]);
    }
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

    AttendanceInsights::fake(function () {
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

    AttendanceInsights::fake(function () {
        throw new AiException('provider exploded');
    });

    Livewire::test('manager.ai-analysis')
        ->set('period', '2026-07')
        ->call('generateInsights')
        ->assertHasNoErrors();

    expect(AiInsight::where('period', '2026-07')->count())->toBe(1);
});

it('discards an analysis with nothing usable in it rather than wiping the saved one', function () {
    AiInsight::create([
        'period' => '2026-07',
        'category' => 'الطلاب',
        'title' => 'تحليل سابق',
        'description' => 'يجب أن يبقى كما هو',
        'type' => 'neutral',
    ]);

    AttendanceInsights::fake([['insights' => [['category' => 'الطلاب', 'title' => '', 'description' => 'بلا عنوان', 'type' => 'neutral']]]]);

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

    AttendanceInsights::fake([['insights' => [[
        'category' => 'الحلقات',
        'title' => 'أعلى الحلقات التزاماً',
        'description' => 'حلقة النور هي الأعلى حضوراً هذا الشهر.',
        'type' => 'positive',
    ]]]]);

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
        ->call('respond')
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
        ->call('respond')
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

it('sends the whole month, its last day included, and every student, the most absent first', function () {
    $quiet = Student::factory()->create(['name' => 'ملتزم', 'circle_id' => $this->circle->id]);
    Attendance::create(['student_id' => $quiet->id, 'circle_id' => $this->circle->id, 'teacher_id' => $this->teacher->id, 'date' => '2026-07-31', 'status' => 'present']);

    AttendanceInsights::fake([['insights' => [['category' => 'الطلاب', 'title' => 'نمط', 'description' => 'وصف', 'type' => 'neutral']]]]);

    Livewire::test('manager.ai-analysis')->set('period', '2026-07')->call('generateInsights');

    AttendanceInsights::assertPrompted(function (AgentPrompt $prompt) {
        $data = json_decode(Str::after($prompt->prompt, "\n"), true);

        return $data['totals'] === ['present' => 2, 'absent' => 1, 'late' => 1, 'excused' => 0]
            && array_column($data['students'], 'name') === ['سالم', 'ملتزم']
            && $data['students'][0]['circle'] === 'حلقة النور'
            && $data['circles'][0]['present'] === 2;
    });
});

it('asks for no analysis of a month without attendance', function () {
    AttendanceInsights::fake();

    Livewire::test('manager.ai-analysis')->set('period', '2026-03')->call('generateInsights');

    AttendanceInsights::assertNeverPrompted();
});

it('shows the question at once and streams the answer in a second request', function () {
    $asked = 0;
    PersonlanAssistant::fake(function () use (&$asked) {
        $asked++;

        return 'الجواب';
    });

    $component = Livewire::test('manager.ai-assistant')
        ->set('input', 'كم عدد الطلاب؟')
        ->call('ask');

    // The reply waits empty on the page, for the stream to land in.
    expect(array_slice($component->get('messages'), -2))->toBe([
        ['role' => 'user', 'content' => 'كم عدد الطلاب؟'],
        ['role' => 'ai', 'content' => ''],
    ]);
    $component->assertSeeHtml('wire:stream="chat-reply-2"')->assertSet('input', '');
    PersonlanAssistant::assertNeverPrompted();

    $component->call('respond');

    $messages = $component->get('messages');

    expect(end($messages)['content'])->toBe('الجواب');

    // Asked again with nothing waiting, it does not answer twice.
    $component->call('respond');

    expect($asked)->toBe(1);
});

it('reads the earlier turns, so a follow-up question means what it says', function () {
    PersonlanAssistant::fake(['في حلقة النور ١٢ طالباً.', 'وفي الشهر الماضي ١٠.']);

    Livewire::test('manager.ai-assistant')
        ->set('input', 'كم طالباً في حلقة النور؟')->call('ask')->call('respond')
        ->set('input', 'وماذا عن الشهر الماضي؟')->call('ask')->call('respond');

    PersonlanAssistant::assertPrompted(function (AgentPrompt $prompt) {
        $history = collect($prompt->agent->messages())->map(fn ($message) => [$message->role->value, $message->content])->all();

        return $prompt->prompt === 'وماذا عن الشهر الماضي؟'
            && $history === [['user', 'كم طالباً في حلقة النور؟'], ['assistant', 'في حلقة النور ١٢ طالباً.']];
    });
});

it('leaves a turn whose reply failed out of what the assistant reads', function () {
    PersonlanAssistant::fake(function (AgentPrompt $prompt) {
        if ($prompt->prompt === 'سؤال أول') {
            throw new AiException('down');
        }

        return 'جواب';
    });

    Livewire::test('manager.ai-assistant')
        ->set('input', 'سؤال أول')->call('ask')->call('respond')
        ->set('input', 'سؤال ثان')->call('ask')->call('respond');

    PersonlanAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === 'سؤال ثان' && collect($prompt->agent->messages())->isEmpty());
});

it('draws a finished reply from its markdown, tables included', function () {
    Livewire::test('manager.ai-assistant')
        ->set('messages', [['role' => 'ai', 'content' => "**الأعلى حضوراً**\n\n| الحلقة | الحضور |\n| --- | --- |\n| النور | ٩٥٪ |"]])
        ->assertSeeHtml('<strong>الأعلى حضوراً</strong>')
        ->assertSeeHtml('<th>الحلقة</th>')
        ->assertSeeHtml('<td>النور</td>');
});

it('shows the login links the assistant chose on the page, never in its reply', function () {
    $this->student->update(['access_token' => 'secret-token']);

    PersonlanAssistant::fake(function () {
        // What the tool does when the model calls it: it picks the people, the page makes the links.
        app(MagicLinkTray::class)->add('student', [$this->student->id]);

        return 'تجد رابط سالم أدناه.';
    });

    $component = Livewire::test('manager.ai-assistant')
        ->set('input', 'أعطني رابط سالم')->call('ask')->call('respond');

    $messages = $component->get('messages');
    $reply = end($messages);

    expect($reply['content'])->not->toContain('secret-token')
        ->and($reply['links'])->toBe([['role' => 'student', 'ids' => [$this->student->id]]]);

    $component->assertSee('روابط دخول طلاب')
        ->assertSeeHtml((string) Js::from(route('magic-link', ['token' => 'secret-token'])));
});
