<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Resources\PbModelResource\RelationManagers;

use Andre\AiPageBuilder\Enums\FieldType;
use Andre\AiPageBuilder\Models\PbField;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Record;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Andre\AiPageBuilder\Services\RecordCsv;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Browses the ACTUAL rows of a collection's dynamic table (pb_<key>) as a second
 * tab on the collection edit page, alongside FieldsRelationManager.
 *
 * Records do not live in a normal Eloquent relationship — they are stored in the
 * generated `{prefix}{key}` table and reached through the dynamic Record model.
 * So instead of declaring a `$relationship`, this manager:
 *   - overrides getRelationship() to hand the table a plain query builder
 *     (Record::for($owner)->newQuery()), which Filament's table accepts as a
 *     Builder just like a real relation; and
 *   - skips the relation-name based authorization (there is no relationship and
 *     no policy on the dynamic Record model).
 *
 * Columns and the create/edit form are built dynamically from the owner
 * collection's fields, and every mutation is routed through RecordQuery so the
 * same casts + validation + column whitelisting used by the REST API apply here.
 */
class RecordsRelationManager extends RelationManager
{
    protected static ?string $title = 'Records';

    /**
     * No real relationship backs this manager, and the dynamic Record model has
     * no policy. canViewForRecord() in the base would otherwise resolve a
     * relationship by name to authorize — skip it so the tab renders.
     */
    protected static bool $shouldSkipAuthorization = true;

    /**
     * Provide an Eloquent query builder over the owner collection's physical
     * table. The table's getQuery() consults $this->query (set via ->query() in
     * table() below) BEFORE the relationship branch, so this drives the table
     * without a declared $relationship — and avoids the relationship branch,
     * which would call ->getQuery() on the builder and return the wrong type.
     */
    public function getRecordsQuery(): Builder
    {
        return Record::for($this->getOwnerRecord())->newQuery();
    }

    /**
     * Defensive only: table() calls ->relationship(null) so this is never used to
     * drive the table. Kept (returning our Eloquent builder) to override the base
     * implementation, which would otherwise resolve a non-existent relationship by
     * name and throw.
     */
    public function getRelationship(): Relation|Builder
    {
        return $this->getRecordsQuery();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->formComponents())
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        /** @var PbModel $owner */
        $owner = $this->getOwnerRecord();

        return $table
            // Records have no Eloquent relationship. The base relation-table wires
            // ->relationship(fn () => $this->getRelationship()) in makeTable(); our
            // table() runs AFTER it, so clear that resolver and drive the table from
            // an explicit Eloquent query instead. This keeps getQuery() on the
            // ->query() branch and prevents getRelationshipQuery() from ever running
            // (it requires an Eloquent\Builder return and would otherwise throw).
            ->relationship(null)
            ->query(fn (): Builder => $this->getRecordsQuery())
            ->recordTitleAttribute('id')
            ->defaultSort('id', 'desc')
            ->columns($this->tableColumns($owner))
            // A query panel above the table — filters apply to THIS collection's
            // query only (Record::for($owner)), so it can never reach another
            // table. Safe, scoped, no raw SQL.
            ->filters($this->tableFilters($owner))
            // Collapsible so the query panel sits at the top but doesn't consume
            // space until the user opens it.
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(3)
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Add record')
                    ->using(fn (array $data): Record => app(RecordQuery::class)->create($owner, $data)),

                Actions\Action::make('exportCsv')
                    ->label('Export CSV')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('gray')
                    ->action(fn (): StreamedResponse => $this->exportCsv($owner)),

                Actions\Action::make('importCsv')
                    ->label('Import CSV')
                    ->icon('heroicon-m-arrow-up-tray')
                    ->color('gray')
                    ->modalSubmitActionLabel('Import')
                    ->schema([
                        Forms\Components\FileUpload::make('file')
                            ->label('CSV file')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                            ->helperText('First row must be a header of column names. Unknown columns are ignored; bad rows are skipped and reported.')
                            ->storeFiles(false)
                            ->required(),
                    ])
                    ->action(fn (array $data) => $this->importCsv($owner, $data)),
            ])
            ->recordActions([
                Actions\EditAction::make()
                    ->fillForm(fn (Record $record): array => $record->attributesToArray())
                    ->using(fn (Record $record, array $data): Record => app(RecordQuery::class)
                        ->update($owner, $record->getKey(), $data) ?? $record),
                Actions\DeleteAction::make()
                    ->using(fn (Record $record): bool => app(RecordQuery::class)->delete($owner, $record->getKey())),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->action(function ($records) use ($owner): void {
                            $query = app(RecordQuery::class);
                            foreach ($records as $record) {
                                $query->delete($owner, $record->getKey());
                            }
                        }),
                ]),
            ]);
    }

    /**
     * Stream all of the collection's records as a CSV download named after the
     * collection key. Building the whole CSV up front (rather than echoing per
     * row) keeps it simple and matches the admin-scale data this drives.
     */
    private function exportCsv(PbModel $owner): StreamedResponse
    {
        $csv = app(RecordCsv::class)->export($owner);
        $filename = $owner->key.'.csv';

        return response()->streamDownload(
            function () use ($csv): void {
                echo $csv;
            },
            $filename,
            ['Content-Type' => 'text/csv'],
        );
    }

    /**
     * Read the uploaded CSV and replay it through RecordCsv::import, surfacing the
     * imported / skipped counts (and the first few row errors) as a notification.
     *
     * @param  array<string,mixed>  $data
     */
    private function importCsv(PbModel $owner, array $data): void
    {
        $file = $data['file'] ?? null;

        if (! $file instanceof UploadedFile) {
            Notification::make()->danger()->title('No file')->body('Could not read the uploaded CSV.')->send();

            return;
        }

        $csv = (string) file_get_contents($file->getRealPath());
        $summary = app(RecordCsv::class)->import($owner, $csv);

        $body = "Imported {$summary['imported']}, skipped {$summary['skipped']}.";
        if ($summary['errors'] !== []) {
            $body .= ' '.implode(' ', array_slice($summary['errors'], 0, 3));
            if (count($summary['errors']) > 3) {
                $body .= ' …';
            }
        }

        $notification = Notification::make()->title('CSV import complete')->body($body);
        $summary['skipped'] === 0 ? $notification->success() : $notification->warning();
        $notification->send();
    }

    /**
     * One table column per field, plus id and (when enabled) created_at.
     *
     * @return array<int,Tables\Columns\Column>
     */
    private function tableColumns(PbModel $owner): array
    {
        $columns = [
            Tables\Columns\TextColumn::make('id')
                ->label('ID')
                ->sortable(),
        ];

        foreach ($owner->fields as $field) {
            $columns[] = $this->tableColumn($field);
        }

        if ($owner->has_timestamps) {
            $columns[] = Tables\Columns\TextColumn::make('created_at')
                ->dateTime()
                ->since()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true);
        }

        return $columns;
    }

    /**
     * Build the query panel: one filter per field, scoped to the collection's
     * own query. Type-aware (select→dropdown, boolean→ternary, number/date→
     * range, text→contains). Never references another table.
     *
     * @return array<int,BaseFilter>
     */
    private function tableFilters(PbModel $owner): array
    {
        $filters = [];

        foreach ($owner->fields as $field) {
            $name = $field->columnName();
            $label = $field->label;
            $options = (array) ($field->options ?? []);
            $type = $field->fieldType();

            $filters[] = match ($type) {
                FieldType::Select => SelectFilter::make($name)
                    ->label($label)
                    ->options($this->selectChoices($options)),

                FieldType::Boolean => TernaryFilter::make($name)->label($label),

                FieldType::Integer, FieldType::Decimal => Filter::make($name)
                    // Two inputs side by side, spanning two grid columns, so the
                    // range filter stays the same height as single-input filters.
                    ->columns(2)
                    ->columnSpan(2)
                    ->schema([
                        Forms\Components\TextInput::make('from')->label($label.' ≥')->numeric(),
                        Forms\Components\TextInput::make('to')->label($label.' ≤')->numeric(),
                    ])
                    ->query(function (Builder $query, array $data) use ($name): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $v): Builder => $q->where($name, '>=', $v))
                            ->when($data['to'] ?? null, fn (Builder $q, $v): Builder => $q->where($name, '<=', $v));
                    }),

                FieldType::Date, FieldType::DateTime => Filter::make($name)
                    ->columns(2)
                    ->columnSpan(2)
                    ->schema([
                        Forms\Components\DatePicker::make('from')->label($label.' from'),
                        Forms\Components\DatePicker::make('until')->label($label.' until'),
                    ])
                    ->query(function (Builder $query, array $data) use ($name): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $v): Builder => $q->whereDate($name, '>=', $v))
                            ->when($data['until'] ?? null, fn (Builder $q, $v): Builder => $q->whereDate($name, '<=', $v));
                    }),

                default => Filter::make($name)
                    ->schema([Forms\Components\TextInput::make('value')->label($label.' contains')])
                    ->query(function (Builder $query, array $data) use ($name): Builder {
                        return $query->when(
                            $data['value'] ?? null,
                            fn (Builder $q, $v): Builder => $q->where($name, 'like', '%'.$v.'%'),
                        );
                    }),
            };
        }

        return $filters;
    }

    /**
     * Type-aware column for a single field.
     */
    private function tableColumn(PbField $field): Tables\Columns\Column
    {
        $name = $field->columnName();
        $type = $field->fieldType();

        return match ($type) {
            FieldType::Boolean => Tables\Columns\IconColumn::make($name)
                ->label($field->label)
                ->boolean()
                ->sortable(),

            FieldType::Date => Tables\Columns\TextColumn::make($name)
                ->label($field->label)
                ->date()
                ->sortable(),

            FieldType::DateTime => Tables\Columns\TextColumn::make($name)
                ->label($field->label)
                ->dateTime()
                ->sortable(),

            FieldType::Json => Tables\Columns\TextColumn::make($name)
                ->label($field->label)
                ->formatStateUsing(fn (mixed $state): string => $this->shortJson($state))
                ->wrap(),

            FieldType::Select => Tables\Columns\TextColumn::make($name)
                ->label($field->label)
                ->badge()
                ->searchable()
                ->sortable(),

            default => Tables\Columns\TextColumn::make($name)
                ->label($field->label)
                ->searchable()
                ->sortable(),
        };
    }

    /**
     * Build the create/edit form inputs from the owner collection's fields. Form
     * field names use each field's column name so the payload maps cleanly into
     * RecordQuery (which accepts either the field key or its column name).
     *
     * @return array<int,Forms\Components\Field>
     */
    private function formComponents(): array
    {
        /** @var PbModel $owner */
        $owner = $this->getOwnerRecord();

        $components = [];

        foreach ($owner->fields as $field) {
            $components[] = $this->formComponent($field);
        }

        return $components;
    }

    private function formComponent(PbField $field): Forms\Components\Field
    {
        $name = $field->columnName();
        $options = (array) ($field->options ?? []);
        $required = (bool) ($options['required'] ?? false);
        $type = $field->fieldType();

        $component = match ($type) {
            FieldType::Text => Forms\Components\Textarea::make($name)->rows(4),

            FieldType::Integer => Forms\Components\TextInput::make($name)->numeric()->integer(),

            FieldType::Decimal => Forms\Components\TextInput::make($name)->numeric(),

            FieldType::Boolean => Forms\Components\Toggle::make($name),

            FieldType::Date => Forms\Components\DatePicker::make($name),

            FieldType::DateTime => Forms\Components\DateTimePicker::make($name),

            FieldType::Json => Forms\Components\Textarea::make($name)
                ->rows(4)
                ->helperText('Enter valid JSON.')
                ->formatStateUsing(fn (mixed $state): ?string => $this->encodeJsonForForm($state))
                ->dehydrateStateUsing(fn (mixed $state): mixed => $this->decodeJsonFromForm($state)),

            FieldType::Select => Forms\Components\Select::make($name)
                ->options($this->selectChoices($options))
                ->native(false),

            FieldType::Relation => Forms\Components\Select::make($name)
                ->options(fn (): array => $this->relationOptions($options))
                ->searchable()
                ->native(false),

            default => Forms\Components\TextInput::make($name)
                ->maxLength((int) ($options['length'] ?? 255)),
        };

        $component->label($field->label);

        if ($required) {
            $component->required();
        }

        return $component;
    }

    /**
     * Select choices: a list of values from options.choices, keyed by value so
     * the stored value matches the option key.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,string>
     */
    private function selectChoices(array $options): array
    {
        $choices = $options['choices'] ?? [];

        if (! is_array($choices)) {
            return [];
        }

        $result = [];
        foreach ($choices as $choice) {
            $result[(string) $choice] = (string) $choice;
        }

        return $result;
    }

    /**
     * Options for a relation field: id => a human label from the related
     * collection's records. Picks the related collection by the key stored in
     * options.relation_model and labels each row by its first string/text field
     * (falling back to "#<id>").
     *
     * @param  array<string,mixed>  $options
     * @return array<int|string,string>
     */
    private function relationOptions(array $options): array
    {
        $key = $options['relation_model'] ?? null;

        if (! is_string($key) || $key === '') {
            return [];
        }

        /** @var class-string<PbModel> $modelClass */
        $modelClass = config('ai-page-builder.models.model', PbModel::class);

        $related = $modelClass::query()->where('key', $key)->first();

        if (! $related instanceof PbModel) {
            return [];
        }

        $labelField = $related->fields
            ->first(fn (PbField $f): bool => in_array($f->type, [
                FieldType::String->value,
                FieldType::Text->value,
            ], true));

        $rows = Record::for($related)->newQuery()->orderBy('id')->get();

        $result = [];
        foreach ($rows as $row) {
            $label = $labelField ? (string) ($row->{$labelField->columnName()} ?? '') : '';
            $result[$row->getKey()] = $label !== '' ? $label : '#'.$row->getKey();
        }

        return $result;
    }

    /**
     * Short, single-line preview of a JSON/array column value for the table.
     */
    private function shortJson(mixed $state): string
    {
        if ($state === null || $state === '') {
            return '';
        }

        $json = is_string($state) ? $state : json_encode($state);
        $json = (string) $json;

        return mb_strlen($json) > 60 ? mb_substr($json, 0, 57).'…' : $json;
    }

    /**
     * Render a JSON column's (already array-cast) value back to a JSON string for
     * the textarea.
     */
    private function encodeJsonForForm(mixed $state): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        if (is_string($state)) {
            return $state;
        }

        return json_encode($state, JSON_PRETTY_PRINT) ?: null;
    }

    /**
     * Decode a JSON textarea back into an array for storage; leave invalid input
     * untouched so RecordQuery's validation reports it.
     */
    private function decodeJsonFromForm(mixed $state): mixed
    {
        if (! is_string($state) || trim($state) === '') {
            return null;
        }

        $decoded = json_decode($state, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $state;
    }
}
