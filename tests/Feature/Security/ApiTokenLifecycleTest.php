<?php

declare(strict_types=1);

/*
 * Regression tests for API tokens in 2.0 (GHSA-hj3q-8gc4-6mpw): a token never
 * outlives its owner's right to sign in.
 */

use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Andre\AiPageBuilder\Models\PbApiToken;
use Andre\AiPageBuilder\Models\PbPermission;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbUser;

/** A Staff user with read access to a restricted `employees` collection, and their token. */
function staffWithToken(): array
{
    app(BuildPlanApplier::class)->apply(['collections' => [
        ['key' => 'employees', 'name' => 'Employees', 'fields' => [['key' => 'name', 'label' => 'Name', 'type' => 'string']]],
    ]]);
    $role = PbRole::create(['name' => 'Staff', 'slug' => 'staff', 'is_admin' => false]);
    PbPermission::create(['role_id' => $role->id, 'resource_type' => 'collection', 'resource_key' => 'employees', 'action' => 'read']);
    $user = PbUser::create(['name' => 'Ex', 'email' => 'ex@x.test', 'password' => 'x', 'role_id' => $role->id]);

    return [$user, PbApiToken::generate('ci', $user->id)['plain_text']];
}

it('accepts the token of an active user', function (): void {
    [, $plain] = staffWithToken();

    $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/pb/employees')->assertOk();
});

it('rejects and revokes the token of a suspended or deactivated user', function (array $change): void {
    [$user, $plain] = staffWithToken();

    $user->forceFill($change)->save();

    $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/pb/employees')->assertUnauthorized();
    expect(PbApiToken::query()->where('pb_user_id', $user->id)->count())->toBe(0);
})->with([
    'suspended' => [['status' => PbUser::STATUS_SUSPENDED]],
    'deactivated' => [['is_active' => false]],
    'both' => [['is_active' => false, 'status' => PbUser::STATUS_SUSPENDED]],
]);

it('rejects a token whose owner cannot sign in even if it was not revoked', function (): void {
    [$user, $plain] = staffWithToken();

    // A write that skips model events (raw query) leaves the token in place.
    PbUser::query()->whereKey($user->id)->update(['status' => PbUser::STATUS_PENDING]);

    $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/pb/employees')->assertUnauthorized();
});

it('rejects the token of a deleted user instead of treating it as ownerless', function (): void {
    [$user, $plain] = staffWithToken();

    PbUser::query()->whereKey($user->id)->delete(); // no events: token row survives

    $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/pb/employees')->assertUnauthorized();
});

it('revokes a user\'s tokens when the user is deleted', function (): void {
    [$user] = staffWithToken();

    $user->delete();

    expect(PbApiToken::query()->where('pb_user_id', $user->id)->count())->toBe(0);
});

it('keeps tokens when an unrelated attribute of an active user changes', function (): void {
    [$user, $plain] = staffWithToken();

    $user->update(['name' => 'Renamed']);

    $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/pb/employees')->assertOk();
});
