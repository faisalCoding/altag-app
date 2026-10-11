<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * An API key for one AI provider, entered from the manager's AI settings page
 * rather than the environment file. The key is encrypted with the application
 * key, so it is unreadable in a database dump or a downloaded backup.
 */
#[Hidden(['api_key'])]
class AiProviderCredential extends Model
{
    protected $fillable = ['provider', 'api_key', 'base_url'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
        ];
    }

    /**
     * The key, or null when it was encrypted with another application key —
     * a backup restored elsewhere, or APP_KEY changed. Such a key is as good
     * as missing, and must not take the settings page down with it.
     */
    public function readableKey(): ?string
    {
        try {
            return $this->api_key;
        } catch (DecryptException) {
            return null;
        }
    }

    /** A key is stored but can no longer be read with this application key. */
    public function isUnreadable(): bool
    {
        return filled($this->getRawOriginal('api_key')) && $this->readableKey() === null;
    }

    /**
     * The last four characters, for confirming which key is stored without
     * ever sending the key itself to the browser.
     */
    public function maskedKey(): ?string
    {
        $key = $this->readableKey();

        if (blank($key)) {
            return null;
        }

        return '••••••••'.mb_substr($key, -4);
    }
}
