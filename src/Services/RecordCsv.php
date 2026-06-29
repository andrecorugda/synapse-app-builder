<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\Record;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * CSV import / export for a collection's records. Export emits a header row of
 * `id` + each field's column name followed by one row per record; import parses
 * that same shape and replays every row through RecordQuery::create, so the
 * validation, casts, and column whitelisting used everywhere else apply here
 * too. Import never aborts on a bad row — it collects the error and continues.
 */
class RecordCsv
{
    public function __construct(private readonly RecordQuery $records) {}

    /**
     * Render all of a collection's records as CSV text. The header is `id` plus
     * each field's physical column name; array/JSON values are JSON-encoded so a
     * cell stays a single value.
     */
    public function export(PbModel $model): string
    {
        $columns = $this->columns($model);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $columns);

        Record::for($model)->newQuery()->orderBy('id')->chunk(500, function ($records) use ($handle, $columns): void {
            foreach ($records as $record) {
                fputcsv($handle, $this->row($record, $columns));
            }
        });

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Parse CSV text and create a record per data row via RecordQuery (so casts
     * and validation apply). The first row is the header, mapping each cell to a
     * field key or column name; unknown columns are dropped. Bad rows are skipped
     * with an error rather than aborting the import.
     *
     * @return array{imported:int,skipped:int,errors:array<int,string>}
     */
    public function import(PbModel $model, string $csv): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Could not open a stream to read the CSV.']];
        }
        fwrite($handle, $csv);
        rewind($handle);

        $allowed = $this->importableColumns($model);
        $header = null;

        $line = 0;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;

            // First non-empty row is the header.
            if ($header === null) {
                $header = array_map(static fn ($h): string => trim((string) $h), $cells);

                continue;
            }

            // Skip blank lines (fgetcsv yields [null] for an empty line).
            if ($cells === [null]) {
                continue;
            }

            $data = $this->mapRow($header, $cells, $allowed);

            if ($data === []) {
                $skipped++;
                $errors[] = "Row {$line}: no recognised columns — skipped.";

                continue;
            }

            try {
                $this->records->create($model, $data);
                $imported++;
            } catch (ValidationException $e) {
                $skipped++;
                $errors[] = "Row {$line}: ".implode(' ', $e->validator->errors()->all());
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = "Row {$line}: ".$e->getMessage();
            }
        }

        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Header / export columns: id first, then each field's physical column name.
     *
     * @return array<int,string>
     */
    private function columns(PbModel $model): array
    {
        $columns = ['id'];

        foreach ($model->fields as $field) {
            $columns[] = $field->columnName();
        }

        if ($model->has_timestamps) {
            $columns[] = 'created_at';
            $columns[] = 'updated_at';
        }

        return $columns;
    }

    /**
     * Columns an import may write: each field by both its key and its column name
     * (relations accept either `manager` or `manager_id`). `id` and timestamps are
     * deliberately excluded — RecordQuery owns those.
     *
     * @return array<int,string>
     */
    private function importableColumns(PbModel $model): array
    {
        $allowed = [];

        foreach ($model->fields as $field) {
            $allowed[] = $field->key;
            $allowed[] = $field->columnName();
        }

        return array_values(array_unique($allowed));
    }

    /**
     * Build a record's cells for the given columns, JSON-encoding array values so
     * each cell holds a single string.
     *
     * @param  array<int,string>  $columns
     * @return array<int,string>
     */
    private function row(Record $record, array $columns): array
    {
        $cells = [];

        foreach ($columns as $column) {
            $value = $record->getAttribute($column);

            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            $cells[] = $value === null ? '' : (string) $value;
        }

        return $cells;
    }

    /**
     * Map a data row to a {column => value} payload, keeping only recognised
     * columns. Empty cells under an unknown header are ignored; a recognised
     * column with an empty cell passes through as null (so nullable fields clear).
     *
     * @param  array<int,string>  $header
     * @param  array<int,string|null>  $cells
     * @param  array<int,string>  $allowed
     * @return array<string,mixed>
     */
    private function mapRow(array $header, array $cells, array $allowed): array
    {
        $data = [];

        foreach ($header as $index => $column) {
            if (! in_array($column, $allowed, true)) {
                continue;
            }

            $value = $cells[$index] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            $data[$column] = ($value === '' || $value === null) ? null : $value;
        }

        return $data;
    }
}
