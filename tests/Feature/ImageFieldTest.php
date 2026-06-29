<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Enums\FieldType;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Record;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Define a `gallery` collection with an image field and sync its real table.
 */
function makeGalleryModel(): PbModel
{
    $model = PbModel::create([
        'key' => 'gallery',
        'table_name' => PbModel::physicalTableName('gallery'),
        'name' => 'Gallery',
        'has_timestamps' => true,
        'has_soft_deletes' => false,
    ]);

    $fields = [
        ['key' => 'title', 'label' => 'Title', 'type' => 'string', 'options' => ['required' => true]],
        ['key' => 'photo', 'label' => 'Photo', 'type' => 'image'],
    ];
    foreach ($fields as $i => $f) {
        $model->fields()->create($f + ['sort' => $i]);
    }

    app(SchemaSynchronizer::class)->sync($model->fresh());

    return $model->fresh();
}

it('creates a string column for an image field', function (): void {
    makeGalleryModel();

    expect(Schema::hasColumn('pb_gallery', 'photo'))->toBeTrue()
        ->and(FieldType::Image->columnName('photo'))->toBe('photo')
        ->and(FieldType::Image->cast())->toBeNull();
});

it('stores a path string in an image field', function (): void {
    $model = makeGalleryModel();

    $record = app(RecordQuery::class)->create($model, [
        'title' => 'Cover',
        'photo' => 'page-builder/abc123.jpg',
    ]);

    expect($record)->toBeInstanceOf(Record::class)
        ->and($record->photo)->toBe('page-builder/abc123.jpg');
});

it('validates an image field as a nullable string', function (): void {
    $model = makeGalleryModel();
    $q = app(RecordQuery::class);

    // A string path is accepted, and the field is optional (nullable).
    $q->create($model, ['title' => 'A', 'photo' => 'page-builder/x.png']);
    $q->create($model, ['title' => 'B']);
    expect($q->list($model)->total())->toBe(2);

    // A non-string value is rejected.
    expect(fn () => $q->create($model, ['title' => 'C', 'photo' => ['not', 'a', 'string']]))
        ->toThrow(ValidationException::class);
});

it('returns the image field over the REST API', function (): void {
    $model = makeGalleryModel();

    $this->postJson('/api/pb/gallery', ['title' => 'Cover', 'photo' => 'page-builder/abc123.jpg'])
        ->assertCreated()
        ->assertJsonPath('data.photo', 'page-builder/abc123.jpg');

    $this->getJson('/api/pb/gallery')
        ->assertOk()
        ->assertJsonPath('data.0.photo', 'page-builder/abc123.jpg');
});
