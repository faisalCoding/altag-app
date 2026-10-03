<?php

use App\Rules\WhatsappGroupLink;

function whatsappGroupLinkFails(mixed $value): bool
{
    $failed = false;

    (new WhatsappGroupLink)->validate('link', $value, function () use (&$failed) {
        $failed = true;
    });

    return $failed;
}

it('tidies a pasted link before it is checked', function (string $pasted, ?string $stored) {
    expect(WhatsappGroupLink::format($pasted))->toBe($stored);
})->with([
    'a bare link' => ['https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv', 'https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv'],
    'without its scheme' => ['chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv', 'https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv'],
    'over plain http' => ['http://Chat.WhatsApp.com/AbCdEfGhIjKlMnOpQrStUv', 'https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv'],
    'inside WhatsApp\'s invitation' => [
        'انضم إلى مجموعتي في واتساب: https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv?mode=ems_copy_t',
        'https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv?mode=ems_copy_t',
    ],
    'with spaces around it' => ['  https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv  ', 'https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv'],
    'emptied' => ['   ', null],
    'another site, left for the rule to refuse' => ['https://example.com/group', 'https://example.com/group'],
]);

it('accepts a WhatsApp group invitation', function (string $link) {
    expect(whatsappGroupLinkFails($link))->toBeFalse();
})->with([
    'https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv',
    'https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv?mode=ems_copy_t',
]);

it('refuses anything else a teacher\'s phone would be sent to', function (mixed $link) {
    expect(whatsappGroupLinkFails($link))->toBeTrue();
})->with([
    'another site' => 'https://example.com/group',
    'a chat with one number' => 'https://wa.me/966500000000',
    'an invitation with no code' => 'https://chat.whatsapp.com/',
    'a look-alike host' => 'https://chat.whatsapp.com.example.com/AbCdEfGhIjKlMnOpQrStUv',
    'plain http' => 'http://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv',
    'markup smuggled in' => 'https://chat.whatsapp.com/AbCdEfGhIjKl"onclick="alert(1)',
    'a script' => 'javascript:alert(1)',
    'not text' => [['https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv']],
]);
