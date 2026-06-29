<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Andre\AiPageBuilder\Filament\Resources\PbModelResource;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    // A collection with TWO user foreign keys — the whole point: a record can
    // relate to several users in different roles (author, assignee), each a
    // renamable relation to the app users table, not a single owner column.
    app(BuildPlanApplier::class)->apply([
        'collections' => [
            ['key' => 'tasks', 'name' => 'Tasks', 'fields' => [
                ['key' => 'title', 'label' => 'Title', 'type' => 'string'],
                ['key' => 'author', 'label' => 'Author', 'type' => 'relation', 'options' => ['relation_model' => PbUser::RELATION_TARGET]],
                ['key' => 'assignee', 'label' => 'Assignee', 'type' => 'relation', 'options' => ['relation_model' => PbUser::RELATION_TARGET]],
            ]],
        ],
    ]);

    $this->tasks = PbModel::query()->where('key', 'tasks')->firstOrFail();
    $this->rq = app(RecordQuery::class);
    $this->alice = PbUser::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);
    $this->bob = PbUser::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']);
});

it('offers the users table as a default relation target', function (): void {
    expect(PbModelResource::relationModelOptions())->toHaveKey(PbUser::RELATION_TARGET);
});

it('stores user relations as foreign ids and validates the users exist', function (): void {
    $task = $this->rq->create($this->tasks, [
        'title' => 'Ship it',
        'author' => $this->alice->id,
        'assignee' => $this->bob->id,
    ]);

    expect($task->getAttribute('author_id'))->toBe($this->alice->id)
        ->and($task->getAttribute('assignee_id'))->toBe($this->bob->id);
});

it('rejects a user relation that does not reference a real user', function (): void {
    $this->rq->create($this->tasks, ['title' => 'Bad', 'author' => 999999]);
})->throws(ValidationException::class);

it('expands multiple user relations without leaking the password', function (): void {
    $task = $this->rq->create($this->tasks, [
        'title' => 'Ship it',
        'author' => $this->alice->id,
        'assignee' => $this->bob->id,
    ]);

    $found = $this->rq->find($this->tasks, $task->id, ['expand' => 'author,assignee']);

    expect($found->getAttribute('author')['name'])->toBe('Alice')
        ->and($found->getAttribute('assignee')['name'])->toBe('Bob')
        ->and($found->getAttribute('author'))->not->toHaveKey('password')
        ->and($found->getAttribute('author'))->not->toHaveKey('remember_token');
});
