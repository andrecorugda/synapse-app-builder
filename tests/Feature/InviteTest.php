<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Auth\InviteService;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Models\PbUserInvite;

/** The shared invite service under test. */
function invites(): InviteService
{
    return app(InviteService::class);
}

/**
 * Insert an invite with a KNOWN plaintext token so the redeem/route flows can be
 * exercised (createAndSend only emails the plaintext, it is never returned).
 */
function seedInvite(array $attrs = [], string $plainToken = 'KNOWNTOKEN'): PbUserInvite
{
    return PbUserInvite::query()->create(array_merge([
        'email' => 'invitee@example.com',
        'token' => password_hash($plainToken, PASSWORD_BCRYPT),
        'role_id' => null,
        'invited_by' => null,
        'expires_at' => now()->addDay(),
        'accepted_at' => null,
    ], $attrs));
}

// --- 1. createAndSend -------------------------------------------------------

it('createAndSend persists a hashed, pending invite with the invited role', function (): void {
    $invite = invites()->createAndSend('  Invitee@Example.com  ', roleId: 7, invitedBy: 3, ttlDays: 7);

    expect($invite->exists)->toBeTrue()
        // Email is normalised (trimmed + lowercased).
        ->and($invite->getAttribute('email'))->toBe('invitee@example.com')
        // Token is stored hashed, never as the plaintext.
        ->and($invite->getAttribute('token'))->not->toBeEmpty()
        ->and($invite->getAttribute('role_id'))->toBe(7)
        ->and($invite->getAttribute('accepted_at'))->toBeNull()
        ->and($invite->getAttribute('expires_at')->isFuture())->toBeTrue()
        ->and($invite->isPending())->toBeTrue();

    // The DB row carries the hash, not any recognisable plaintext.
    $fresh = PbUserInvite::query()->find($invite->getKey());
    expect($fresh->getAttribute('token'))->toBe($invite->getAttribute('token'))
        ->and(strlen((string) $fresh->getAttribute('token')))->toBeGreaterThan(20);
});

// --- 2. findValid -----------------------------------------------------------

it('findValid returns the invite for the right email + token', function (): void {
    seedInvite();

    $found = invites()->findValid('invitee@example.com', 'KNOWNTOKEN');

    expect($found)->not->toBeNull()
        ->and($found->getAttribute('email'))->toBe('invitee@example.com');
});

it('findValid returns null for a wrong token', function (): void {
    seedInvite();

    expect(invites()->findValid('invitee@example.com', 'WRONGTOKEN'))->toBeNull();
});

it('findValid returns null for an expired invite', function (): void {
    seedInvite(['expires_at' => now()->subDay()]);

    expect(invites()->findValid('invitee@example.com', 'KNOWNTOKEN'))->toBeNull();
});

it('findValid returns null for an already-accepted invite', function (): void {
    seedInvite(['accepted_at' => now()]);

    expect(invites()->findValid('invitee@example.com', 'KNOWNTOKEN'))->toBeNull();
});

// --- 3. accept (new user) ---------------------------------------------------

it('accept creates an active user with the invited role and burns the invite', function (): void {
    $invite = seedInvite(['role_id' => 9]);

    $user = invites()->accept($invite, 'Grace Hopper', 'hopper-pass');

    expect($user->exists)->toBeTrue()
        ->and($user->getAttribute('status'))->toBe(PbUser::STATUS_ACTIVE)
        ->and((bool) $user->getAttribute('is_active'))->toBeTrue()
        ->and($user->getAttribute('role_id'))->toBe(9)
        ->and($user->getAttribute('email'))->toBe('invitee@example.com')
        ->and($user->getAttribute('email_verified_at'))->not->toBeNull();

    // The password is usable through the pb guard.
    expect(Auth::guard('pb')->attempt(['email' => 'invitee@example.com', 'password' => 'hopper-pass']))->toBeTrue();

    // Invite is now marked accepted...
    expect($invite->fresh()->getAttribute('accepted_at'))->not->toBeNull();

    // ...so the same token no longer redeems.
    expect(invites()->findValid('invitee@example.com', 'KNOWNTOKEN'))->toBeNull();
});

// --- 4. accept (existing user) ----------------------------------------------

it('accept activates an existing user without duplicating it', function (): void {
    // A pre-existing, inactive account with the same email.
    PbUser::query()->create([
        'name' => 'Old Name',
        'email' => 'invitee@example.com',
        'password' => 'old-pass',
        'is_active' => false,
        'status' => PbUser::STATUS_PENDING,
        'role_id' => 1,
    ]);

    $invite = seedInvite(['role_id' => 4]);

    $user = invites()->accept($invite, 'New Name', 'new-pass');

    // No duplicate — the single row was updated.
    expect(PbUser::query()->where('email', 'invitee@example.com')->count())->toBe(1)
        ->and($user->getAttribute('name'))->toBe('New Name')
        ->and($user->getAttribute('status'))->toBe(PbUser::STATUS_ACTIVE)
        ->and((bool) $user->getAttribute('is_active'))->toBeTrue()
        ->and($user->getAttribute('role_id'))->toBe(4);

    // The new password works; the old one does not.
    expect(Auth::guard('pb')->attempt(['email' => 'invitee@example.com', 'password' => 'new-pass']))->toBeTrue();
    Auth::guard('pb')->logout();
    expect(Auth::guard('pb')->attempt(['email' => 'invitee@example.com', 'password' => 'old-pass']))->toBeFalse();
});

// --- 5. resend --------------------------------------------------------------

it('resend rotates the token and pushes the expiry out', function (): void {
    $invite = seedInvite(['expires_at' => now()->addDay()]);
    $oldExpiry = $invite->fresh()->getAttribute('expires_at');

    invites()->resend($invite, ttlDays: 14);

    // Old plaintext no longer validates against the rotated hash.
    expect(invites()->findValid('invitee@example.com', 'KNOWNTOKEN'))->toBeNull();

    // Expiry has moved further into the future.
    expect($invite->fresh()->getAttribute('expires_at')->greaterThan($oldExpiry))->toBeTrue()
        ->and($invite->fresh()->getAttribute('accepted_at'))->toBeNull();
});

// --- 6. route end-to-end ----------------------------------------------------

it('GET /login/invite/{token} renders the accept page for a valid invite', function (): void {
    seedInvite();

    $this->get('/login/invite/KNOWNTOKEN?email=invitee@example.com')
        ->assertOk();
});

it('POST /login/invite/{token} creates the user, logs them in, and burns the invite', function (): void {
    $invite = seedInvite(['role_id' => 5]);

    $this->post('/login/invite/KNOWNTOKEN', [
        'email' => 'invitee@example.com',
        'name' => 'Ada Lovelace',
        'password' => 'analytical-pass',
        'password_confirmation' => 'analytical-pass',
    ])->assertRedirect();

    $user = PbUser::query()->where('email', 'invitee@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->getAttribute('status'))->toBe(PbUser::STATUS_ACTIVE)
        ->and($user->getAttribute('role_id'))->toBe(5)
        ->and(auth('pb')->check())->toBeTrue();

    expect($invite->fresh()->getAttribute('accepted_at'))->not->toBeNull();
});

it('GET /login/invite/{token} bounces an invalid or expired token back to login', function (): void {
    seedInvite(['expires_at' => now()->subDay()]);

    $this->get('/login/invite/KNOWNTOKEN?email=invitee@example.com')
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');
});
