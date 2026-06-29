<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\PbPermission;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Models\Record;
use Andre\AiPageBuilder\Services\AccessControl;
use Andre\AiPageBuilder\Services\Data\RecordQuery;

beforeEach(function (): void {
    app(BuildPlanApplier::class)->apply([
        'collections' => [
            ['key' => 'employees', 'name' => 'Employees', 'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string'],
                ['key' => 'salary', 'label' => 'Salary', 'type' => 'integer'],
            ]],
        ],
    ]);
    $this->emp = PbModel::query()->where('key', 'employees')->firstOrFail();
    $this->rec = app(RecordQuery::class)->create($this->emp, ['name' => 'Ada', 'salary' => 100]);

    $this->role = PbRole::create(['name' => 'Staff', 'slug' => 'staff', 'is_admin' => false]);
    $this->user = PbUser::create(['name' => 'U', 'email' => 'u@x.com', 'password' => 'x', 'role_id' => $this->role->id]);
    // Read is restricted to the `name` field for this role.
    PbPermission::create(['role_id' => $this->role->id, 'resource_type' => 'collection', 'resource_key' => 'employees', 'action' => 'read', 'fields' => ['name']]);
});

it('computes allowed fields per role/action', function (): void {
    $ac = app(AccessControl::class);
    expect($ac->allowedFields($this->user, 'employees', 'read'))->toBe(['name']);

    // Admin + no user → no restriction (null = all).
    $adminRole = PbRole::create(['name' => 'Admin', 'slug' => 'admin', 'is_admin' => true]);
    $admin = PbUser::create(['name' => 'A', 'email' => 'a@x.com', 'password' => 'x', 'role_id' => $adminRole->id]);
    expect($ac->allowedFields($admin, 'employees', 'read'))->toBeNull();
    expect($ac->allowedFields(null, 'employees', 'read'))->toBeNull();
});

it('projects the REST read to only the allowed fields', function (): void {
    $this->actingAs($this->user, 'pb');

    $data = $this->getJson('/api/pb/employees/'.$this->rec->id)->assertOk()->json('data');

    expect($data)->toHaveKey('name')
        ->and($data)->toHaveKey('id')
        ->and($data)->not->toHaveKey('salary');
});

it('strips disallowed fields on write', function (): void {
    PbPermission::create(['role_id' => $this->role->id, 'resource_type' => 'collection', 'resource_key' => 'employees', 'action' => 'create', 'fields' => ['name']]);
    $this->actingAs($this->user, 'pb');

    $this->postJson('/api/pb/employees', ['name' => 'Bob', 'salary' => 999])->assertCreated();

    $bob = Record::for($this->emp)->newQuery()->where('name', 'Bob')->first();
    expect($bob->getAttribute('salary'))->toBeNull(); // salary was not writable → dropped
});
