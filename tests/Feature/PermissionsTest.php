<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\PbPermission;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Services\AccessControl;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;

function notesModel(): PbModel
{
    $model = PbModel::create([
        'key' => 'notes',
        'table_name' => PbModel::physicalTableName('notes'),
        'name' => 'Notes',
        'has_timestamps' => true,
    ]);
    $model->fields()->create(['key' => 'title', 'label' => 'Title', 'type' => 'string', 'sort' => 0]);
    $model->fields()->create(['key' => 'owner_id', 'label' => 'Owner', 'type' => 'integer', 'sort' => 1]);
    app(SchemaSynchronizer::class)->sync($model->fresh());

    return $model->fresh();
}

function role(string $slug, bool $admin = false): PbRole
{
    return PbRole::create(['name' => ucfirst($slug), 'slug' => $slug, 'is_admin' => $admin]);
}

function userIn(PbRole $r): PbUser
{
    static $n = 0;
    $n++;

    return PbUser::create(['name' => $r->slug.' user', 'email' => $r->slug.$n.'@x.com', 'password' => 'secret123', 'role_id' => $r->id, 'is_active' => true]);
}

// --- engine ----------------------------------------------------------------

it('treats a collection with no permission rows as open (opt-in)', function (): void {
    $ac = app(AccessControl::class);
    expect($ac->isRestricted('collection', 'notes'))->toBeFalse()
        ->and($ac->can(null, 'read', 'collection', 'notes'))->toBeTrue();
});

it('restricts a collection once any permission targets it', function (): void {
    $member = role('member');
    PbPermission::create(['role_id' => $member->id, 'resource_type' => 'collection', 'resource_key' => 'notes', 'action' => 'read']);

    $ac = app(AccessControl::class);

    expect($ac->isRestricted('collection', 'notes'))->toBeTrue()
        ->and($ac->can(null, 'read', 'collection', 'notes'))->toBeFalse()          // guest denied
        ->and($ac->can(userIn($member), 'read', 'collection', 'notes'))->toBeTrue() // granted
        ->and($ac->can(userIn($member), 'delete', 'collection', 'notes'))->toBeFalse(); // not granted
});

it('lets an admin role bypass every check', function (): void {
    $member = role('member');
    PbPermission::create(['role_id' => $member->id, 'resource_type' => 'collection', 'resource_key' => 'notes', 'action' => 'read']);
    $admin = userIn(role('admin', admin: true));

    expect(app(AccessControl::class)->can($admin, 'delete', 'collection', 'notes'))->toBeTrue();
});

it('resolves $CURRENT_USER in a row rule', function (): void {
    $member = role('member');
    PbPermission::create(['role_id' => $member->id, 'resource_type' => 'collection', 'resource_key' => 'notes', 'action' => '*', 'rule' => ['owner_id' => '$CURRENT_USER']]);
    $u = userIn($member);

    expect(app(AccessControl::class)->rowRule($u, 'notes', 'read'))->toBe(['owner_id' => $u->id]);
});

// --- API enforcement + row-level --------------------------------------------

it('403s a guest on a restricted collection and scopes a member to their rows', function (): void {
    $model = notesModel();
    $member = role('member');
    PbPermission::create(['role_id' => $member->id, 'resource_type' => 'collection', 'resource_key' => 'notes', 'action' => '*', 'rule' => ['owner_id' => '$CURRENT_USER']]);
    $u = userIn($member);

    $rq = app(RecordQuery::class);
    $rq->create($model, ['title' => 'Mine', 'owner_id' => $u->id]);
    $rq->create($model, ['title' => 'Theirs', 'owner_id' => 9999]);

    // Guest → restricted → 403
    $this->getJson('/api/pb/notes')->assertStatus(403);

    // Member → only own row
    $res = $this->actingAs($u, 'pb')->getJson('/api/pb/notes')->assertOk()->json('data');
    expect(collect($res)->pluck('title')->all())->toBe(['Mine']);

    // Member create → owner_id forced to them
    $created = $this->actingAs($u, 'pb')->postJson('/api/pb/notes', ['title' => 'New', 'owner_id' => 9999])
        ->assertCreated()->json('data');
    expect($created['owner_id'])->toBe($u->id);
});

it('hides another user\'s row from show/update/delete', function (): void {
    $model = notesModel();
    $member = role('member');
    PbPermission::create(['role_id' => $member->id, 'resource_type' => 'collection', 'resource_key' => 'notes', 'action' => '*', 'rule' => ['owner_id' => '$CURRENT_USER']]);
    $u = userIn($member);

    $rq = app(RecordQuery::class);
    $theirs = $rq->create($model, ['title' => 'Theirs', 'owner_id' => 9999]);

    $this->actingAs($u, 'pb')->getJson('/api/pb/notes/'.$theirs->id)->assertStatus(404);
    $this->actingAs($u, 'pb')->deleteJson('/api/pb/notes/'.$theirs->id)->assertStatus(404);
});

// --- M2: update re-stamps the owner row-rule (no row reassignment) ----------

it('does not let a user reassign owner_id via update', function (): void {
    $model = notesModel();
    $member = role('member');
    PbPermission::create(['role_id' => $member->id, 'resource_type' => 'collection', 'resource_key' => 'notes', 'action' => '*', 'rule' => ['owner_id' => '$CURRENT_USER']]);
    $u = userIn($member);

    $rq = app(RecordQuery::class);
    $mine = $rq->create($model, ['title' => 'Mine', 'owner_id' => $u->id]);

    // Try to give the row away by PATCHing owner_id to someone else.
    $res = $this->actingAs($u, 'pb')
        ->patchJson('/api/pb/notes/'.$mine->id, ['title' => 'Renamed', 'owner_id' => 9999])
        ->assertOk()->json('data');

    expect($res['owner_id'])->toBe($u->id)   // ownership pinned to the actor
        ->and($res['title'])->toBe('Renamed'); // legit field still updates
});

// --- H2: expand is gated by the RELATED collection's permissions ------------

function projectsWithOwnerModel(): array
{
    // projects: title + owner_id. notes: title + project_id (relation → projects).
    $projects = PbModel::create([
        'key' => 'projects', 'table_name' => PbModel::physicalTableName('projects'),
        'name' => 'Projects', 'has_timestamps' => true,
    ]);
    $projects->fields()->create(['key' => 'title', 'label' => 'Title', 'type' => 'string', 'sort' => 0]);
    $projects->fields()->create(['key' => 'owner_id', 'label' => 'Owner', 'type' => 'integer', 'sort' => 1]);
    app(SchemaSynchronizer::class)->sync($projects->fresh());

    $notes = PbModel::create([
        'key' => 'notes', 'table_name' => PbModel::physicalTableName('notes'),
        'name' => 'Notes', 'has_timestamps' => true,
    ]);
    $notes->fields()->create(['key' => 'body', 'label' => 'Body', 'type' => 'string', 'sort' => 0]);
    $notes->fields()->create(['key' => 'project', 'label' => 'Project', 'type' => 'relation', 'options' => ['relation_model' => 'projects'], 'sort' => 1]);
    app(SchemaSynchronizer::class)->sync($notes->fresh());

    return [$projects->fresh(), $notes->fresh()];
}

it('drops an expand to a related collection the caller cannot read', function (): void {
    [$projects, $notes] = projectsWithOwnerModel();
    $member = role('member');

    // notes: readable by member. projects: row-scoped to the owner.
    PbPermission::create(['role_id' => $member->id, 'resource_type' => 'collection', 'resource_key' => 'notes', 'action' => '*']);
    PbPermission::create(['role_id' => $member->id, 'resource_type' => 'collection', 'resource_key' => 'projects', 'action' => 'read', 'rule' => ['owner_id' => '$CURRENT_USER']]);
    $u = userIn($member);

    $rq = app(RecordQuery::class);
    $theirProject = $rq->create($projects, ['title' => 'Secret', 'owner_id' => 9999]);
    $note = $rq->create($notes, ['body' => 'n', 'project' => $theirProject->id]);

    // Expanding `project` must NOT leak another owner's project row.
    $row = $this->actingAs($u, 'pb')->getJson('/api/pb/notes/'.$note->id.'?expand=project')
        ->assertOk()->json('data');

    expect($row['project'])->toBeNull();
});

it('still expands a related collection the caller is allowed to read', function (): void {
    [$projects, $notes] = projectsWithOwnerModel();
    $member = role('member');

    PbPermission::create(['role_id' => $member->id, 'resource_type' => 'collection', 'resource_key' => 'notes', 'action' => '*']);
    PbPermission::create(['role_id' => $member->id, 'resource_type' => 'collection', 'resource_key' => 'projects', 'action' => 'read', 'rule' => ['owner_id' => '$CURRENT_USER']]);
    $u = userIn($member);

    $rq = app(RecordQuery::class);
    $myProject = $rq->create($projects, ['title' => 'Mine', 'owner_id' => $u->id]);
    $note = $rq->create($notes, ['body' => 'n', 'project' => $myProject->id]);

    $row = $this->actingAs($u, 'pb')->getJson('/api/pb/notes/'.$note->id.'?expand=project')
        ->assertOk()->json('data');

    expect($row['project'])->toBeArray()
        ->and($row['project']['title'])->toBe('Mine');
});
