<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Record;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Andre\AiPageBuilder\Services\RecordCsv;

/**
 * Build a `leads` collection (name required, email unique, score integer) with a
 * couple of seed rows, returning the synced PbModel.
 */
function makeCsvLeadsModel(): PbModel
{
    app(BuildPlanApplier::class)->apply([
        'collections' => [[
            'key' => 'leads',
            'name' => 'Leads',
            'has_timestamps' => true,
            'has_soft_deletes' => false,
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'options' => ['required' => true]],
                ['key' => 'email', 'label' => 'Email', 'type' => 'string', 'options' => ['unique' => true]],
                ['key' => 'score', 'label' => 'Score', 'type' => 'integer'],
            ],
            'seed' => [
                ['name' => 'Acme', 'email' => 'a@acme.com', 'score' => 42],
                ['name' => 'Globex', 'email' => 'g@globex.com', 'score' => 88],
            ],
        ]],
    ]);

    return PbModel::query()->where('key', 'leads')->firstOrFail();
}

it('exports records to CSV with a header row and one row per record', function (): void {
    $model = makeCsvLeadsModel();

    $csv = app(RecordCsv::class)->export($model);

    $lines = array_values(array_filter(explode("\n", trim($csv))));

    // Header + two seed rows.
    expect($lines)->toHaveCount(3)
        ->and($lines[0])->toContain('id')
        ->and($lines[0])->toContain('name')
        ->and($lines[0])->toContain('email')
        ->and($lines[0])->toContain('score');

    expect($csv)->toContain('Acme')
        ->and($csv)->toContain('a@acme.com')
        ->and($csv)->toContain('Globex');
});

it('imports valid CSV rows and creates records', function (): void {
    $model = makeCsvLeadsModel();

    $csv = "name,email,score\nWayne Ent,w@wayne.com,70\nInitech,i@initech.com,55\n";

    $summary = app(RecordCsv::class)->import($model, $csv);

    expect($summary['imported'])->toBe(2)
        ->and($summary['skipped'])->toBe(0)
        ->and($summary['errors'])->toBe([]);

    // Two seed rows + two imported.
    expect(Record::for($model)->newQuery()->count())->toBe(4)
        ->and(app(RecordQuery::class)->list($model, ['search' => 'Wayne'])->total())->toBe(1);
});

it('skips a bad row with an error and continues importing the rest', function (): void {
    $model = makeCsvLeadsModel();

    // Row 2 is missing the required name → must be skipped, not abort the import.
    $csv = "name,email,score\nValid Co,v@valid.com,30\n,missing-name@x.com,10\nAnother Co,b@another.com,20\n";

    $summary = app(RecordCsv::class)->import($model, $csv);

    expect($summary['imported'])->toBe(2)
        ->and($summary['skipped'])->toBe(1)
        ->and($summary['errors'])->toHaveCount(1)
        ->and($summary['errors'][0])->toContain('Row 3');

    expect(Record::for($model)->newQuery()->where('email', 'v@valid.com')->exists())->toBeTrue()
        ->and(Record::for($model)->newQuery()->where('email', 'b@another.com')->exists())->toBeTrue()
        ->and(Record::for($model)->newQuery()->where('email', 'missing-name@x.com')->exists())->toBeFalse();
});

it('ignores unknown columns on import', function (): void {
    $model = makeCsvLeadsModel();

    $csv = "name,email,score,bogus\nMapped Co,m@mapped.com,15,ignored-value\n";

    $summary = app(RecordCsv::class)->import($model, $csv);

    expect($summary['imported'])->toBe(1)
        ->and($summary['skipped'])->toBe(0);

    $row = Record::for($model)->newQuery()->where('email', 'm@mapped.com')->first();
    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('Mapped Co')
        ->and($row->score)->toBe(15);
});

it('round-trips export then import into a fresh collection', function (): void {
    $model = makeCsvLeadsModel();
    $csv = app(RecordCsv::class)->export($model);

    // Wipe the rows, then re-import the exported CSV. The `id`/timestamp columns
    // in the header are not importable, so RecordQuery owns those — rows recreate
    // cleanly off the field columns.
    Record::for($model)->newQuery()->delete();
    expect(Record::for($model)->newQuery()->count())->toBe(0);

    $summary = app(RecordCsv::class)->import($model, $csv);

    expect($summary['imported'])->toBe(2)
        ->and($summary['errors'])->toBe([])
        ->and(Record::for($model)->newQuery()->count())->toBe(2);
});
