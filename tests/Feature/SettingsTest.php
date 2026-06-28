<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Services\Settings;

it('round-trips native values through JSON', function (): void {
    $s = app(Settings::class);

    $s->set('home_page', 'welcome');
    $s->set('flag', true);
    $s->set('list', ['a', 'b']);

    $s->flush();

    expect($s->get('home_page'))->toBe('welcome')
        ->and($s->get('flag'))->toBeTrue()
        ->and($s->get('list'))->toBe(['a', 'b'])
        ->and($s->get('missing', 'fallback'))->toBe('fallback');
});

it('encrypts and decrypts a secret without storing plaintext', function (): void {
    $s = app(Settings::class);

    $s->setEncrypted('mail_password', 'super-secret');
    $s->flush();

    // The decrypted value round-trips.
    expect($s->getEncrypted('mail_password'))->toBe('super-secret');

    // The stored envelope is not the plaintext.
    $raw = $s->get('mail_password');
    expect($raw)->toBeArray()->toHaveKey('__enc')
        ->and($raw['__enc'])->not->toBe('super-secret');
});

it('clears a secret when set to null/empty', function (): void {
    $s = app(Settings::class);

    $s->setEncrypted('mail_password', 'x');
    $s->flush();
    expect($s->getEncrypted('mail_password'))->toBe('x');

    $s->setEncrypted('mail_password', null);
    $s->flush();
    expect($s->getEncrypted('mail_password'))->toBeNull()
        ->and($s->has('mail_password'))->toBeFalse();
});

it('forgets a key', function (): void {
    $s = app(Settings::class);

    $s->set('home_page', 'welcome');
    $s->flush();
    expect($s->has('home_page'))->toBeTrue();

    $s->forget('home_page');
    $s->flush();
    expect($s->has('home_page'))->toBeFalse()
        ->and($s->get('home_page'))->toBeNull();
});
