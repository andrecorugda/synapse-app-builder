<?php

declare(strict_types=1);

/*
 * The data API's write replies (POST / PATCH) and read projections only show
 * what the acting role may READ: write permission is not read permission.
 */

use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\PbPermission;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Services\Data\RecordQuery;

function writeReplyCollections(): void
{
    app(BuildPlanApplier::class)->apply([
        'collections' => [
            ['key' => 'staff_records', 'name' => 'Staff records', 'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string'],
                ['key' => 'ssn', 'label' => 'SSN', 'type' => 'string'],
                ['key' => 'salary', 'label' => 'Salary', 'type' => 'integer'],
            ]],
            ['key' => 'accounts', 'name' => 'Accounts', 'fields' => [
                ['key' => 'account', 'label' => 'Account name', 'type' => 'string'],
                ['key' => 'account_id', 'label' => 'Bank account number', 'type' => 'string'],
            ]],
            ['key' => 'tickets', 'name' => 'Tickets', 'fields' => [
                ['key' => 'body', 'label' => 'Body', 'type' => 'string'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
                ['key' => 'owner', 'label' => 'Owner', 'type' => 'relation', 'options' => ['relation_model' => PbUser::RELATION_TARGET]],
            ]],
        ],
    ]);
}

function writeReplyRole(): PbRole
{
    return PbRole::create(['name' => 'Staff', 'slug' => 'staff-'.uniqid(), 'is_admin' => false]);
}

function writeReplyUser(PbRole $role, string $name = 'U'): PbUser
{
    return PbUser::create(['name' => $name, 'email' => strtolower($name).uniqid().'@x.test', 'password' => 'x', 'role_id' => $role->id]);
}

/** @param array<int,string>|null $fields */
function writeReplyGrant(PbRole $role, string $collection, string $action, ?array $fields = null, ?array $rule = null): void
{
    PbPermission::create(array_filter([
        'role_id' => $role->id, 'resource_type' => 'collection', 'resource_key' => $collection,
        'action' => $action, 'fields' => $fields, 'rule' => $rule,
    ], static fn ($v): bool => $v !== null));
}

it('projects a PATCH reply to the read field list', function (): void {
    writeReplyCollections();
    $model = PbModel::query()->where('key', 'staff_records')->firstOrFail();
    $row = app(RecordQuery::class)->create($model, ['name' => 'Ada', 'ssn' => 'SSN-123-45-6789', 'salary' => 98765]);

    $role = writeReplyRole();
    writeReplyGrant($role, 'staff_records', 'read', ['name']);
    writeReplyGrant($role, 'staff_records', 'update', ['name']);
    $user = writeReplyUser($role);

    $this->actingAs($user, 'pb')->getJson('/api/pb/staff_records/'.$row->id)->assertOk()->assertJsonMissingPath('data.ssn');

    $res = $this->actingAs($user, 'pb')->patchJson('/api/pb/staff_records/'.$row->id, ['name' => 'Ada L.'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Ada L.')
        ->assertJsonPath('data.id', $row->id);

    expect($res->getContent())->not->toContain('SSN-123-45-6789')
        ->and($res->getContent())->not->toContain('98765');
});

it('projects a POST reply to the read field list', function (): void {
    writeReplyCollections();
    $role = writeReplyRole();
    writeReplyGrant($role, 'staff_records', 'read', ['name']);
    writeReplyGrant($role, 'staff_records', 'create');
    $user = writeReplyUser($role);

    $res = $this->actingAs($user, 'pb')
        ->postJson('/api/pb/staff_records', ['name' => 'Bo', 'ssn' => 'SSN-999', 'salary' => 4242])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Bo');

    expect($res->getContent())->not->toContain('SSN-999')->and($res->getContent())->not->toContain('4242');
});

it('replies with only the id when the role may write but not read the collection', function (): void {
    writeReplyCollections();
    $role = writeReplyRole();
    writeReplyGrant($role, 'staff_records', 'create');
    $user = writeReplyUser($role);

    $res = $this->actingAs($user, 'pb')
        ->postJson('/api/pb/staff_records', ['name' => 'Write-only', 'ssn' => 'SSN-WO'])
        ->assertCreated();

    expect(array_keys($res->json('data')))->toBe(['id']);
});

it('replies with only the id when a PATCH targets a row outside the read rule', function (): void {
    writeReplyCollections();
    $model = PbModel::query()->where('key', 'tickets')->firstOrFail();
    $role = writeReplyRole();
    $alice = writeReplyUser($role, 'Alice');
    $bob = writeReplyUser($role, 'Bob');
    $bobs = app(RecordQuery::class)->create($model, ['body' => 'BOBS-PRIVATE-TICKET', 'status' => 'open', 'owner' => $bob->id]);

    // Read own tickets only; any agent may update (triage) any ticket's status.
    writeReplyGrant($role, 'tickets', 'read', null, ['owner' => '$CURRENT_USER']);
    writeReplyGrant($role, 'tickets', 'update', ['status']);

    $this->actingAs($alice, 'pb')->getJson('/api/pb/tickets/'.$bobs->id)->assertNotFound();

    $empty = $this->actingAs($alice, 'pb')->patchJson('/api/pb/tickets/'.$bobs->id, [])->assertOk();
    $triage = $this->actingAs($alice, 'pb')->patchJson('/api/pb/tickets/'.$bobs->id, ['status' => 'closed'])->assertOk();

    expect($empty->json('data'))->toBe(['id' => $bobs->id])
        ->and($triage->json('data'))->toBe(['id' => $bobs->id])
        ->and($triage->getContent())->not->toContain('BOBS-PRIVATE-TICKET');

    // The owner still gets the full row back on their own write.
    $this->actingAs($bob, 'pb')->patchJson('/api/pb/tickets/'.$bobs->id, ['status' => 'open'])
        ->assertOk()
        ->assertJsonPath('data.body', 'BOBS-PRIVATE-TICKET');
});

it('returns the whole row from a write on an open collection', function (): void {
    writeReplyCollections();

    $this->postJson('/api/pb/staff_records', ['name' => 'Open', 'ssn' => 'SSN-OPEN'])
        ->assertCreated()
        ->assertJsonPath('data.ssn', 'SSN-OPEN');
});

it('does not leak a hidden plain field whose key is a readable key plus _id', function (): void {
    writeReplyCollections();
    $model = PbModel::query()->where('key', 'accounts')->firstOrFail();
    $row = app(RecordQuery::class)->create($model, ['account' => 'Acme payroll', 'account_id' => 'IBAN-DE89-3704-0044']);

    $role = writeReplyRole();
    writeReplyGrant($role, 'accounts', 'read', ['account']);
    $user = writeReplyUser($role);

    $list = $this->actingAs($user, 'pb')->getJson('/api/pb/accounts')->assertOk()->assertJsonPath('data.0.account', 'Acme payroll');
    $one = $this->actingAs($user, 'pb')->getJson('/api/pb/accounts/'.$row->id)->assertOk();

    expect($list->getContent())->not->toContain('IBAN-DE89-3704-0044')
        ->and($one->getContent())->not->toContain('IBAN-DE89-3704-0044');
});

it('still returns the relation column of a readable relation field', function (): void {
    writeReplyCollections();
    $model = PbModel::query()->where('key', 'tickets')->firstOrFail();
    $role = writeReplyRole();
    $owner = writeReplyUser($role, 'Owner');
    $row = app(RecordQuery::class)->create($model, ['body' => 'B', 'status' => 'open', 'owner' => $owner->id]);
    writeReplyGrant($role, 'tickets', 'read', ['owner']);

    $this->actingAs($owner, 'pb')->getJson('/api/pb/tickets/'.$row->id)
        ->assertOk()
        ->assertJsonPath('data.owner_id', $owner->id)
        ->assertJsonMissingPath('data.body');
});
