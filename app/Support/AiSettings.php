<?php

namespace App\Support;

use App\Models\AiProviderCredential;
use App\Models\Setting;

/**
 * The manager's choice of AI provider, model and API keys, and the glue that
 * feeds those choices to the Laravel AI SDK at the moment an agent runs.
 *
 * Keys entered on the settings page live in the database; keys in the
 * environment file still work and are used whenever the database has none, so
 * an existing deployment keeps running untouched.
 */
class AiSettings
{
    public const PROVIDER_KEY = 'ai_provider';

    public const MODEL_KEY = 'ai_model';

    public const FALLBACK_PROVIDER_KEY = 'ai_fallback_provider';

    /**
     * Providers of the SDK that can generate text, with the models it ships as
     * that provider's default, cheapest and smartest. Any other model id the
     * provider accepts may be typed in by hand — the SDK passes it straight
     * through.
     *
     * @var array<string, array{label: string, models: array<int, string>, needs_url: bool}>
     */
    public const TEXT_PROVIDERS = [
        'gemini' => [
            'label' => 'Google Gemini',
            'models' => ['gemini-3-flash-preview', 'gemini-3.1-flash-lite-preview', 'gemini-3.1-pro-preview'],
            'needs_url' => false,
        ],
        'anthropic' => [
            'label' => 'Anthropic Claude',
            'models' => ['claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-opus-5-5'],
            'needs_url' => false,
        ],
        'openai' => [
            'label' => 'OpenAI',
            'models' => ['gpt-5.4', 'gpt-5.4-nano', 'gpt-5.4-pro'],
            'needs_url' => true,
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'models' => ['deepseek-v4-flash', 'deepseek-v4-pro'],
            'needs_url' => false,
        ],
        'xai' => [
            'label' => 'xAI Grok',
            'models' => ['grok-4-1-fast-reasoning'],
            'needs_url' => false,
        ],
        'groq' => [
            'label' => 'Groq',
            'models' => ['openai/gpt-oss-120b', 'openai/gpt-oss-20b'],
            'needs_url' => false,
        ],
        'mistral' => [
            'label' => 'Mistral',
            'models' => ['mistral-medium-latest', 'mistral-small-latest', 'mistral-large-latest'],
            'needs_url' => false,
        ],
        'openrouter' => [
            'label' => 'OpenRouter',
            'models' => ['anthropic/claude-sonnet-4.6', 'anthropic/claude-haiku-4.5', 'anthropic/claude-opus-4.6'],
            'needs_url' => false,
        ],
        'ollama' => [
            'label' => 'Ollama (محلي)',
            'models' => ['llama3.1:8b', 'llama3.1:70b'],
            'needs_url' => true,
        ],
    ];

    /**
     * The provider whose own model list holds this model id, when it is one of
     * another provider's — a model typed for one provider fails on any other.
     */
    public static function modelOwnedElsewhere(string $provider, string $model): ?string
    {
        if ($model === '' || in_array($model, self::TEXT_PROVIDERS[$provider]['models'] ?? [], true)) {
            return null;
        }

        foreach (self::TEXT_PROVIDERS as $other => $meta) {
            if ($other !== $provider && in_array($model, $meta['models'], true)) {
                return $other;
            }
        }

        return null;
    }

    public static function isSupportedProvider(?string $provider): bool
    {
        return $provider !== null && array_key_exists($provider, self::TEXT_PROVIDERS);
    }

    /**
     * The provider the assistant should use, falling back to the application's
     * configured default when the stored choice is missing or no longer valid.
     */
    public static function provider(): string
    {
        $provider = Setting::getVal(self::PROVIDER_KEY);

        if (self::isSupportedProvider($provider)) {
            return $provider;
        }

        $default = config('ai.default');

        return self::isSupportedProvider($default) ? $default : 'gemini';
    }

    /**
     * A second provider to fall back to when the first is rate limited,
     * overloaded or out of credit. Null when none is configured.
     */
    public static function fallbackProvider(): ?string
    {
        $provider = Setting::getVal(self::FALLBACK_PROVIDER_KEY);

        if (! self::isSupportedProvider($provider) || $provider === self::provider()) {
            return null;
        }

        return $provider;
    }

    /**
     * The model id to use, or null to let the SDK pick the provider's default.
     */
    public static function model(): ?string
    {
        $model = Setting::getVal(self::MODEL_KEY);

        return blank($model) ? null : $model;
    }

    /**
     * The provider chain to hand the SDK, keyed by provider with the model to
     * use for each. The keyed form matters: given a plain list the SDK drops
     * the chosen model and falls back to every provider's own default.
     *
     * The fallback provider is mapped to null so it uses its default model —
     * a model id is only ever valid for the provider it was chosen for.
     *
     * @return array<string, string|null>
     */
    public static function providerChain(): array
    {
        $chain = [self::provider() => self::model()];

        if ($fallback = self::fallbackProvider()) {
            $chain[$fallback] = null;
        }

        return $chain;
    }

    /**
     * Push the stored API keys into the SDK's provider configuration. Called
     * right before an agent runs, so an unused AI feature costs no queries.
     */
    public static function apply(): void
    {
        foreach (self::credentials() as $provider => $credential) {
            if (filled($credential['api_key'])) {
                config(['ai.providers.'.$provider.'.key' => $credential['api_key']]);
            }

            if (filled($credential['base_url'])) {
                config(['ai.providers.'.$provider.'.url' => $credential['base_url']]);
            }
        }
    }

    /**
     * Whether the given provider can actually be used: a key is stored either
     * in the database or in the environment. Ollama runs locally and needs none.
     */
    public static function hasKey(string $provider): bool
    {
        if ($provider === 'ollama') {
            return true;
        }

        return filled(self::credentials()[$provider]['api_key'] ?? null)
            || filled(config('ai.providers.'.$provider.'.key'));
    }

    /**
     * Stored credentials keyed by provider.
     *
     * @return array<string, array{api_key: ?string, base_url: ?string}>
     */
    public static function credentials(): array
    {
        return AiProviderCredential::all()
            ->mapWithKeys(fn (AiProviderCredential $credential) => [
                $credential->provider => [
                    'api_key' => $credential->readableKey(),
                    'base_url' => $credential->base_url,
                ],
            ])->all();
    }
}
