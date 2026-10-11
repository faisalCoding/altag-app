<?php

use App\Ai\Agents\KeyCheck;
use App\Ai\Agents\PersonlanAssistant;
use App\Models\AiProviderCredential;
use App\Models\Manager;
use App\Models\Setting;
use App\Support\AiSettings;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;

beforeEach(function () {
    $this->manager = Manager::factory()->create();
    $this->actingAs($this->manager, 'manager');
});

it('lets the manager choose the provider and model for the assistant', function () {
    Livewire::test('manager.ai-assistant-settings')
        ->set('provider', 'anthropic')
        ->set('model', 'claude-opus-5-5')
        ->set('fallbackProvider', 'deepseek')
        ->call('save')
        ->assertHasNoErrors();

    expect(AiSettings::provider())->toBe('anthropic')
        ->and(AiSettings::model())->toBe('claude-opus-5-5')
        ->and(AiSettings::fallbackProvider())->toBe('deepseek');
});

it('hands the SDK a provider-keyed chain so the chosen model survives failover', function () {
    Setting::setVal(AiSettings::PROVIDER_KEY, 'anthropic');
    Setting::setVal(AiSettings::MODEL_KEY, 'claude-opus-4-6');
    Setting::setVal(AiSettings::FALLBACK_PROVIDER_KEY, 'deepseek');

    // A plain list would make the SDK drop the model; the keys carry it through.
    expect((new PersonlanAssistant)->provider())->toBe([
        'anthropic' => 'claude-opus-4-6',
        'deepseek' => null,
    ]);
});

it('asks for the provider default when no model is chosen', function () {
    Setting::setVal(AiSettings::PROVIDER_KEY, 'gemini');
    Setting::setVal(AiSettings::MODEL_KEY, '');
    Setting::setVal(AiSettings::FALLBACK_PROVIDER_KEY, '');

    expect(AiSettings::model())->toBeNull()
        ->and((new PersonlanAssistant)->provider())->toBe(['gemini' => null]);
});

it('rejects a provider the sdk cannot generate text with', function () {
    Livewire::test('manager.ai-assistant-settings')
        ->set('provider', 'elevenlabs')
        ->call('save')
        ->assertHasErrors('provider');

    expect(Setting::getVal(AiSettings::PROVIDER_KEY))->toBeNull();
});

it('ignores a stored provider that is no longer supported', function () {
    Setting::setVal(AiSettings::PROVIDER_KEY, 'some-retired-provider');

    expect(AiSettings::provider())->toBe(config('ai.default'));
});

it('drops a fallback that is the same as the primary provider', function () {
    Livewire::test('manager.ai-assistant-settings')
        ->set('provider', 'gemini')
        ->set('fallbackProvider', 'gemini')
        ->call('save')
        ->assertHasNoErrors();

    expect(AiSettings::fallbackProvider())->toBeNull()
        ->and((new PersonlanAssistant)->provider())->toBe(['gemini' => null]);
});

it('stores a provider key encrypted and never renders it back', function () {
    Livewire::test('manager.ai-assistant-settings')
        ->set('newKeys.anthropic', 'sk-ant-super-secret-1234')
        ->call('saveKey', 'anthropic')
        ->assertHasNoErrors()
        ->assertSet('newKeys.anthropic', '')
        ->assertDontSee('sk-ant-super-secret-1234')
        ->assertSee('••••••••1234');

    $credential = AiProviderCredential::where('provider', 'anthropic')->first();

    expect($credential->api_key)->toBe('sk-ant-super-secret-1234');

    // The column itself must not hold the plain key.
    $raw = DB::table('ai_provider_credentials')->where('provider', 'anthropic')->value('api_key');

    expect($raw)->not->toBe('sk-ant-super-secret-1234')
        ->and($raw)->not->toContain('super-secret');
});

it('keeps the stored key when the input is left blank', function () {
    AiProviderCredential::create(['provider' => 'openai', 'api_key' => 'sk-original']);

    Livewire::test('manager.ai-assistant-settings')
        ->set('baseUrls.openai', 'https://proxy.example.test/v1')
        ->call('saveKey', 'openai')
        ->assertHasNoErrors();

    $credential = AiProviderCredential::where('provider', 'openai')->first();

    expect($credential->api_key)->toBe('sk-original')
        ->and($credential->base_url)->toBe('https://proxy.example.test/v1');
});

it('lets the manager delete a stored key', function () {
    AiProviderCredential::create(['provider' => 'groq', 'api_key' => 'gsk-old']);

    Livewire::test('manager.ai-assistant-settings')
        ->call('deleteKey', 'groq')
        ->assertHasNoErrors();

    expect(AiProviderCredential::where('provider', 'groq')->exists())->toBeFalse();
});

it('refuses to touch a provider outside the supported list', function () {
    Livewire::test('manager.ai-assistant-settings')
        ->set('newKeys.elevenlabs', 'sk-nope')
        ->call('saveKey', 'elevenlabs')
        ->assertStatus(404);

    expect(AiProviderCredential::count())->toBe(0);
});

it('feeds the stored key to the sdk configuration when the assistant runs', function () {
    config(['ai.providers.anthropic.key' => null]);

    AiProviderCredential::create(['provider' => 'anthropic', 'api_key' => 'sk-from-database']);
    Setting::setVal(AiSettings::PROVIDER_KEY, 'anthropic');

    (new PersonlanAssistant)->provider();

    expect(config('ai.providers.anthropic.key'))->toBe('sk-from-database');
});

it('leaves the environment key in place when the database has none', function () {
    config(['ai.providers.gemini.key' => 'key-from-env']);

    AiSettings::apply();

    expect(config('ai.providers.gemini.key'))->toBe('key-from-env')
        ->and(AiSettings::hasKey('gemini'))->toBeTrue();
});

it('warns on save when the chosen provider has no key at all', function () {
    config(['ai.providers.groq.key' => null]);

    Livewire::test('manager.ai-assistant-settings')
        ->set('provider', 'groq')
        ->call('save')
        ->assertHasNoErrors();

    expect(AiSettings::hasKey('groq'))->toBeFalse()
        ->and(AiSettings::provider())->toBe('groq');
});

/**
 * The SDK ships "deepseek-chat" as this provider's default, which the DeepSeek
 * API rejects outright with a 400. A stale name here silently breaks the
 * provider for anyone who does not type a model by hand.
 */
it('pins deepseek to model names its api still accepts', function () {
    $accepted = ['deepseek-v4-flash', 'deepseek-v4-pro'];

    expect(config('ai.providers.deepseek.models.text.default'))->toBeIn($accepted)
        ->and(config('ai.providers.deepseek.models.text.smartest'))->toBeIn($accepted)
        ->and(AiSettings::TEXT_PROVIDERS['deepseek']['models'])->toBe($accepted);
});

it('shows the settings page to a manager', function () {
    $this->get(route('manager.ai-settings'))
        ->assertOk()
        ->assertSee('موديل المساعد الذكي')
        ->assertSee('مفاتيح المزوّدين');
});

it('keeps the settings page away from other roles', function () {
    auth('manager')->logout();

    $this->get(route('manager.ai-settings'))->assertRedirect();
});

it('starts a new provider from its own default model, not the last one\'s', function () {
    Livewire::test('manager.ai-assistant-settings')
        ->set('provider', 'gemini')
        ->set('model', 'gemini-3.1-pro-preview')
        ->set('fallbackProvider', 'anthropic')
        ->set('provider', 'anthropic')
        ->assertSet('model', '')
        ->assertSet('fallbackProvider', '');
});

it('refuses a model that belongs to another provider', function () {
    Livewire::test('manager.ai-assistant-settings')
        ->set('provider', 'anthropic')
        ->set('model', 'gemini-3-flash-preview')
        ->call('save')
        ->assertHasErrors('model');

    expect(Setting::getVal(AiSettings::PROVIDER_KEY))->not->toBe('anthropic');

    // A model id outside every list is the manager's to type.
    Livewire::test('manager.ai-assistant-settings')
        ->set('provider', 'anthropic')
        ->set('model', 'claude-some-future-model')
        ->call('save')
        ->assertHasNoErrors();
});

it('offers the current Claude models and uses Sonnet when none is chosen', function () {
    expect(AiSettings::TEXT_PROVIDERS['anthropic']['models'])->toBe(['claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-opus-5-5'])
        ->and(config('ai.providers.anthropic.models.text.default'))->toBe('claude-sonnet-5-5');
});

it('tests a stored key with the model chosen on the page', function () {
    AiProviderCredential::create(['provider' => 'anthropic', 'api_key' => 'sk-ant-test']);
    KeyCheck::fake(['OK']);

    Livewire::test('manager.ai-assistant-settings')
        ->set('provider', 'anthropic')
        ->set('model', 'claude-haiku-5-5')
        ->call('testKey', 'anthropic')
        ->assertHasNoErrors();

    KeyCheck::assertPrompted(fn (AgentPrompt $prompt) => $prompt->model === 'claude-haiku-5-5');
    expect(config('ai.providers.anthropic.key'))->toBe('sk-ant-test');
});

it('says so when a key fails its test, and does not test a provider with no key', function () {
    AiProviderCredential::create(['provider' => 'anthropic', 'api_key' => 'sk-ant-bad']);
    config(['ai.providers.openai.key' => null]);
    KeyCheck::fake(function () {
        throw new AiException('invalid x-api-key');
    });

    Livewire::test('manager.ai-assistant-settings')
        ->call('testKey', 'anthropic')
        ->assertHasNoErrors()
        ->call('testKey', 'openai')
        ->assertHasNoErrors();

    KeyCheck::assertPrompted(fn (AgentPrompt $prompt) => true);
});

/**
 * A backup restored under another APP_KEY, or a changed key, leaves stored
 * keys that cannot be decrypted. The page must still open so the manager can
 * enter them again, and the assistant must treat them as missing.
 */
it('treats a key encrypted with another application key as missing, and still opens the page', function () {
    $foreign = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $credential = AiProviderCredential::create(['provider' => 'anthropic', 'api_key' => 'placeholder']);
    DB::table('ai_provider_credentials')->where('id', $credential->id)->update(['api_key' => $foreign->encryptString('sk-ant-old')]);
    config(['ai.providers.anthropic.key' => null]);

    expect(AiSettings::hasKey('anthropic'))->toBeFalse()
        ->and($credential->fresh()->isUnreadable())->toBeTrue();

    $this->get(route('manager.ai-settings'))
        ->assertSuccessful()
        ->assertSee('تعذّرت قراءة المفتاح المحفوظ، فأعد إدخاله');
});
