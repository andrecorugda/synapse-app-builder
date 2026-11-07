<?php

declare(strict_types=1);

/*
 * Regression tests for the data REST API hardening in 2.0 (GHSA-hj3q-8gc4-6mpw):
 * user-relation expands, field-level read restrictions on every query parameter,
 * and row rules written with a relation field key.
 */

use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\PbPermission;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Services\Data\RecordQuery;

function dataApiCollections(): void
{
    app(BuildPlanApplier::class)->apply([
        'collections' => [
            ['key' => 'posts', 'name' => 'Posts', 'fields' => [
                ['key' => 'title', 'label' => 'Title', 'type' => 'string'],
                ['key' => 'author', 'label' => 'Author', 'type' => 'relation', 'options' => ['relation_model' => PbUser::RELATION_TARGET]],
            ]],
            ['key' => 'employees', 'name' => 'Employees', 'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string'],
                ['key' => 'ssn', 'label' => 'SSN', 'type' => 'string'],
                ['key' => 'salary', 'label' => 'Salary', 'type' => 'integer'],
            ]],
            ['key' => 'notes', 'name' => 'Notes', 'fields' => [
                ['key' => 'body', 'label' => 'Body', 'type' => 'string'],
                ['key' => 'owner', 'label' => 'Owner', 'type' => 'relation', 'options' => ['relation_model' => PbUser::RELATION_TARGET]],
            ]],
        ],
    ]);
}

/** A non-admin user whose role may read only the `name` field of employees. */
function nameOnlyReader(): PbUser
{
    $emp = PbModel::query()->where('key', 'employees')->firstOrFail();
    app(RecordQuery::class)->create($emp, ['name' => 'Ada', 'ssn' => '123-45-6789', 'salary' => 98765]);
    app(RecordQuery::class)->create($emp, ['name' => 'Bob', 'ssn' => '987-65-4321', 'salary' => 50000]);

    $role = PbRole::create(['name' => 'Staff', 'slug' => 'staff', 'is_admin' => false]);
    PbPermission::create(['role_id' => $role->id, 'resource_type' => 'collection', 'resource_key' => 'employees', 'action' => 'read', 'fields' => ['name']]);

    return PbUser::create(['name' => 'U', 'email' => 'u@x.test', 'password' => 'x', 'role_id' => $role->id]);
}

/** Alice and Bob share a role whose read rule is keyed by the relation FIELD `owner`. */
function ownerScopedNotes(array $rule = ['owner' => '$CURRENT_USER']): array
{
    $notes = PbModel::query()->where('key', 'notes')->firstOrFail();
    $role = PbRole::create(['name' => 'Staff', 'slug' => 'staff', 'is_admin' => false]);
    $alice = PbUser::create(['name' => 'Alice', 'email' => 'alice@x.test', 'password' => 'x', 'role_id' => $role->id]);
    $bob = PbUser::create(['name' => 'Bob', 'email' => 'bob@x.test', 'password' => 'x', 'role_id' => $role->id]);
    $bobsNote = app(RecordQuery::class)->create($notes, ['body' => 'BOBS-PRIVATE-NOTE', 'owner' => $bob->id]);
    app(RecordQuery::class)->create($notes, ['body' => 'alices note', 'owner' => $alice->id]);
    foreach (['read', 'create', 'update', 'delete'] as $action) {
        PbPermission::create(['role_id' => $role->id, 'resource_type' => 'collection', 'resource_key' => 'notes', 'action' => $action, 'rule' => $rule]);
    }

    return [$alice, $bob, $bobsNote];
}

// --- User-relation expand -----------------------------------------------------

it('expands a user relation to id and name only, never 2FA secrets, recovery codes or email', function (): void {
    dataApiCollections();
    $victim = PbUser::create([
        'name' => 'Victim', 'email' => 'victim@corp.test', 'password' => 'pw',
        'two_factor_method' => 'totp', 'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['rc-1111', 'rc-2222'], 'two_factor_confirmed_at' => now(),
    ]);

    // Anonymous caller on an open collection, pointing a row at any user id.
    $this->postJson('/api/pb/posts', ['title' => 'x', 'author' => $victim->id])->assertCreated();

    $author = $this->getJson('/api/pb/posts?expand=author')->assertOk()->json('data.0.author');

    expect(array_keys($author))->toEqualCanonicalizing(['id', 'name'])
        ->and(json_encode($author))->not->toContain('JBSWY3DPEHPK3PXP')
        ->and(json_encode($author))->not->toContain('victim@corp.test');
});

it('lets the host widen the user expand allow-list', function (): void {
    config()->set('ai-page-builder.data.user_expand_fields', ['id', 'name', 'status']);
    dataApiCollections();
    $user = PbUser::create(['name' => 'Ann', 'email' => 'ann@x.test', 'password' => 'pw', 'two_factor_secret' => 'SECRET']);
    $this->postJson('/api/pb/posts', ['title' => 'x', 'author' => $user->id])->assertCreated();

    $author = $this->getJson('/api/pb/posts?expand=author')->assertOk()->json('data.0.author');

    expect($author)->toHaveKeys(['id', 'name', 'status'])->not->toHaveKey('two_factor_secret');
});

it('never serializes credentials, two-factor state, SSO link, email or role of a user', function (): void {
    $user = PbUser::create([
        'name' => 'N', 'email' => 'n@x.test', 'password' => 'pw', 'provider' => 'google', 'provider_id' => 'g-1',
        'two_factor_method' => 'totp', 'two_factor_secret' => 'S', 'two_factor_recovery_codes' => ['r'], 'two_factor_confirmed_at' => now(),
    ]);

    expect($user->fresh()->toArray())->not->toHaveKeys([
        'password', 'remember_token', 'two_factor_method', 'two_factor_secret', 'two_factor_recovery_codes',
        'two_factor_confirmed_at', 'provider', 'provider_id', 'email', 'role_id',
    ]);
});

// --- Field-level read restrictions -------------------------------------------

it('does not reveal a hidden field through aggregate group_by', function (): void {
    dataApiCollections();
    $user = nameOnlyReader();

    $this->actingAs($user, 'pb')->getJson('/api/pb/employees')->assertOk()->assertJsonMissingPath('data.0.ssn');

    $res = $this->actingAs($user, 'pb')->getJson('/api/pb/employees/aggregate?group_by=ssn')->assertOk();
    expect(json_encode($res->json()))->not->toContain('123-45-6789');
});

it('does not compute a metric over a hidden field', function (): void {
    dataApiCollections();
    $user = nameOnlyReader();

    $res = $this->actingAs($user, 'pb')->getJson('/api/pb/employees/aggregate?metric=max&field=salary')->assertOk();

    expect($res->json('total'))->toEqual(0)->and($res->json('rows'))->toBe([]);
});

it('ignores a filter on a hidden field', function (string $query): void {
    dataApiCollections();
    $user = nameOnlyReader();

    // Probing ssn would narrow the list to Ada; ignored, both rows come back.
    $res = $this->actingAs($user, 'pb')->getJson('/api/pb/employees?'.$query)->assertOk();

    expect($res->json('data'))->toHaveCount(2);
})->with([
    'filter eq' => ['filter[ssn]=123-45-6789'],
    'filter like' => ['filter[ssn][like]=123'],
]);

it('does not match a search term against a hidden field', function (): void {
    dataApiCollections();
    $user = nameOnlyReader();

    // Only `name` is searched for this role, so an ssn fragment finds nobody.
    $this->actingAs($user, 'pb')->getJson('/api/pb/employees?search=123-45')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($user, 'pb')->getJson('/api/pb/employees?search=Ad')->assertOk()->assertJsonCount(1, 'data');
});

it('ignores a sort on a hidden field', function (): void {
    dataApiCollections();
    $user = nameOnlyReader();

    $asc = $this->actingAs($user, 'pb')->getJson('/api/pb/employees?sort=salary')->json('data.*.name');
    $desc = $this->actingAs($user, 'pb')->getJson('/api/pb/employees?sort=-salary')->json('data.*.name');

    expect($asc)->toBe($desc); // both fall back to the same (id) order
});

it('still filters and aggregates on a field the role may read', function (): void {
    dataApiCollections();
    $user = nameOnlyReader();

    $this->actingAs($user, 'pb')->getJson('/api/pb/employees?filter[name]=Ada')->assertOk()->assertJsonCount(1, 'data');
    $rows = $this->actingAs($user, 'pb')->getJson('/api/pb/employees/aggregate?group_by=name')->assertOk()->json('rows');

    expect(array_column($rows, 'label'))->toEqualCanonicalizing(['Ada', 'Bob']);
});

// --- Row rules keyed by a relation field key ---------------------------------

it('scopes the list by a row rule keyed by the relation field key', function (): void {
    dataApiCollections();
    [$alice] = ownerScopedNotes();

    $res = $this->actingAs($alice, 'pb')->getJson('/api/pb/notes')->assertOk();

    expect(json_encode($res->json('data')))->not->toContain('BOBS-PRIVATE-NOTE')
        ->and($res->json('data'))->toHaveCount(1);
});

it('scopes aggregate by a row rule keyed by the relation field key', function (): void {
    dataApiCollections();
    [$alice] = ownerScopedNotes();

    $res = $this->actingAs($alice, 'pb')->getJson('/api/pb/notes/aggregate')->assertOk();

    expect($res->json('total'))->toEqual(1);
});

it('lets the owner read and update their own row under a field-key rule', function (): void {
    dataApiCollections();
    [$alice] = ownerScopedNotes();
    $own = $this->actingAs($alice, 'pb')->getJson('/api/pb/notes')->json('data.0.id');

    $this->actingAs($alice, 'pb')->getJson('/api/pb/notes/'.$own)->assertOk();
    $this->actingAs($alice, 'pb')->patchJson('/api/pb/notes/'.$own, ['body' => 'edited'])->assertOk();
});

it('hides another user\'s row from show/update/delete under a field-key rule', function (): void {
    dataApiCollections();
    [$alice, , $bobsNote] = ownerScopedNotes();

    $this->actingAs($alice, 'pb')->getJson('/api/pb/notes/'.$bobsNote->id)->assertNotFound();
    $this->actingAs($alice, 'pb')->patchJson('/api/pb/notes/'.$bobsNote->id, ['body' => 'x'])->assertNotFound();
    $this->actingAs($alice, 'pb')->deleteJson('/api/pb/notes/'.$bobsNote->id)->assertNotFound();
});

it('stamps the owner on create even when the caller names the field key', function (): void {
    dataApiCollections();
    [$alice, $bob] = ownerScopedNotes(['owner_id' => '$CURRENT_USER']);

    // The rule is pinned on the column; the caller tries the field key.
    $this->actingAs($alice, 'pb')->postJson('/api/pb/notes', ['body' => 'planted', 'owner' => $bob->id])->assertCreated();

    $notes = PbModel::query()->where('key', 'notes')->firstOrFail();
    $row = app(RecordQuery::class)->list($notes, ['filter' => ['body' => 'planted']])->first();
    expect((int) $row->owner_id)->toBe($alice->id);
});

it('fails closed when a row rule names no field or column', function (): void {
    dataApiCollections();
    [$alice] = ownerScopedNotes(['ownr' => '$CURRENT_USER']);

    $this->actingAs($alice, 'pb')->getJson('/api/pb/notes')->assertOk()->assertJsonCount(0, 'data');
    expect($this->actingAs($alice, 'pb')->getJson('/api/pb/notes/aggregate')->json('total'))->toEqual(0);
    $this->actingAs($alice, 'pb')->postJson('/api/pb/notes', ['body' => 'x'])->assertForbidden();
});
