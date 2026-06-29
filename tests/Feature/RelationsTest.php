<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    app(BuildPlanApplier::class)->apply([
        'collections' => [
            ['key' => 'companies', 'name' => 'Companies', 'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'options' => ['required' => true]],
            ]],
            ['key' => 'employees', 'name' => 'Employees', 'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string'],
                ['key' => 'company', 'label' => 'Company', 'type' => 'relation', 'options' => ['relation_model' => 'companies']],
            ]],
        ],
    ]);

    $this->companies = PbModel::query()->where('key', 'companies')->firstOrFail();
    $this->employees = PbModel::query()->where('key', 'employees')->firstOrFail();
    $this->rq = app(RecordQuery::class);
});

it('stores a relation as a foreign id and validates it exists', function (): void {
    $acme = $this->rq->create($this->companies, ['name' => 'Acme']);
    $ada = $this->rq->create($this->employees, ['name' => 'Ada', 'company' => $acme->id]);

    expect($ada->getAttribute('company_id'))->toBe($acme->id);
});

it('rejects a relation value that does not reference an existing row', function (): void {
    $this->rq->create($this->employees, ['name' => 'Bad', 'company' => 999999]);
})->throws(ValidationException::class);

it('expands a belongs-to relation (employee → company)', function (): void {
    $acme = $this->rq->create($this->companies, ['name' => 'Acme']);
    $ada = $this->rq->create($this->employees, ['name' => 'Ada', 'company' => $acme->id]);

    $found = $this->rq->find($this->employees, $ada->id, ['expand' => 'company']);

    expect($found->getAttribute('company'))->toBeArray()
        ->and($found->getAttribute('company')['name'])->toBe('Acme');
});

it('expands a has-many reverse relation (company → employees)', function (): void {
    $acme = $this->rq->create($this->companies, ['name' => 'Acme']);
    $this->rq->create($this->employees, ['name' => 'Ada', 'company' => $acme->id]);
    $this->rq->create($this->employees, ['name' => 'Bob', 'company' => $acme->id]);

    $found = $this->rq->find($this->companies, $acme->id, ['expand' => 'employees']);

    expect($found->getAttribute('employees'))->toHaveCount(2);
});

it('expands relations across a list query without N+1 surprises', function (): void {
    $acme = $this->rq->create($this->companies, ['name' => 'Acme']);
    $globex = $this->rq->create($this->companies, ['name' => 'Globex']);
    $this->rq->create($this->employees, ['name' => 'Ada', 'company' => $acme->id]);
    $this->rq->create($this->employees, ['name' => 'Bob', 'company' => $globex->id]);

    $page = $this->rq->list($this->employees, ['expand' => 'company', 'sort' => 'name']);
    $rows = $page->getCollection();

    expect($rows->firstWhere('name', 'Ada')->getAttribute('company')['name'])->toBe('Acme')
        ->and($rows->firstWhere('name', 'Bob')->getAttribute('company')['name'])->toBe('Globex');
});
