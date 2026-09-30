<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * The WhatsApp side-service, as seen from Laravel.
 *
 * Sessions there are browsers, so they are started deliberately and closed when
 * idle. That makes "is it up?" a question callers have to ask rather than
 * assume, and this is where the asking lives instead of a fourth copy of the
 * same Http call.
 */
class WhatsappGateway
{
    public static function status(string $clientId): ?array
    {
        return self::request()->get(self::url()."/status/{$clientId}")->json();
    }

    /**
     * Ask for a session to be started. Returns once the request is accepted —
     * the browser behind it may still be warming up.
     */
    public static function connect(string $clientId): bool
    {
        return self::request(5)->post(self::url()."/connect/{$clientId}")->successful();
    }

    /**
     * Start the session if needed and wait for it to be usable.
     *
     * A cold start is a Chromium boot plus a WhatsApp handshake, so the wait is
     * generous — but bounded, because a session that never comes up must not
     * hold a queue worker for ever.
     */
    public static function waitUntilReady(string $clientId, int $seconds = 90, int $pollSeconds = 3): bool
    {
        $deadline = time() + $seconds;

        do {
            $status = self::status($clientId)['status'] ?? null;

            if ($status === 'ready') {
                return true;
            }

            // Nothing is running, and nothing else will start it.
            if ($status === 'stopped') {
                self::connect($clientId);
            }

            // A session waiting to be scanned will never become ready on its own.
            if ($status === 'needs_scan' || $status === null) {
                return false;
            }

            if ($pollSeconds > 0) {
                sleep($pollSeconds);
            }
        } while (time() < $deadline);

        return false;
    }

    private static function request(int $timeout = 3)
    {
        return Http::withHeaders(['X-Api-Key' => config('services.whatsapp.key')])->timeout($timeout);
    }

    private static function url(): string
    {
        return (string) config('services.whatsapp.url');
    }
}
