# Collections & data

[← Docs index](README.md)

A **collection** is a user-defined data model. Its metadata lives in `page_builder_models` (the `PbModel`) and `page_builder_fields` (the `PbField`s), and the `SchemaSynchronizer` turns that metadata into a **real database table** named `pb_<key>` (Directus-style). Every collection automatically gets a REST API, and Flows, Functions, pages and the AI applier all read/write it through one service: `RecordQuery`.

## Defining a collection

In the admin: **Collections** → create. A collection has:

| Field | Notes |
|---|---|
| `key` | Lowercase identifier `^[a-z][a-z0-9_]*$`. **Immutable after create.** Drives the physical table name. |
| `name` | Human label |
| `label_singular` / `label_plural` | Optional display labels |
| `icon` | Heroicon |
| `has_timestamps` | Add `created_at`/`updated_at` (default true) |
| `has_soft_deletes` | Add `deleted_at` (default false) |
| `description` | Optional |

The physical table name is `{data.table_prefix}{key}` with dashes converted to underscores — default prefix `pb_`, so collection key `leads` → table `pb_leads` (`PbModel::physicalTableName()`). Deleting a collection in the admin drops its physical table (`SchemaSynchronizer::dropTable()`).

## Field types

`src/Enums/FieldType.php` — the single source of truth mapping a logical type to its physical column, Eloquent cast and validation rule.

| `type` | Label | Physical column | Cast | Notes |
|---|---|---|---|---|
| `string` | Text (single line) | `string(length)` (default 255) | — | `options.length` |
| `text` | Text (multi-line) | `text` | — | |
| `integer` | Integer | `bigInteger` | `integer` | |
| `decimal` | Decimal | `decimal(precision, scale)` (default 12,2) | `float` | `options.precision`, `options.scale` |
| `boolean` | Boolean | `boolean` | `boolean` | |
| `date` | Date | `date` | `date` | |
| `datetime` | Date & time | `dateTime` | `datetime` | |
| `json` | JSON | `json` | `array` | |
| `select` | Select (choices) | `string(length)` | — | `options.choices` (string list) |
| `relation` | Relation (belongs to) | `unsignedBigInteger` as **`{key}_id`** | `integer` | indexed; set `options.relation_model` to the related collection key |

### Field options

A field's `options` (JSON) tune the column and validation:

- `required` (bool) — non-nullable + `required` validation (otherwise the column is nullable and validation is `nullable`).
- `nullable` (bool) — explicit override of nullability.
- `default` — column default (cast to the field type).
- `unique` (bool) — unique index.
- `index` (bool) — plain index (relations are always indexed).
- `length` (int) — for `string`/`select`.
- `precision` / `scale` (int) — for `decimal`.
- `choices` (string[]) — for `select`; adds an `in:` validation rule.
- `relation_model` (string) — for `relation`; the related collection key.

> **Column naming:** a `relation` field stores its foreign id as `{key}_id`; every other type uses the key verbatim. So in API payloads a relation field accepts either the field key or the `_id` column name.

## Schema sync

`SchemaSynchronizer::sync(PbModel $model)`:

- **Create** — if the table doesn't exist, creates it with `id`, each field's column, plus `timestamps()`/`softDeletes()` per the collection flags.
- **Alter** — if it exists, adds columns for new fields, adds `created_at`/`updated_at`/`deleted_at` if newly enabled, and (only when destructive sync is on) drops columns for removed fields.

**Destructive sync is off by default.** Removing a field does **not** drop its column unless `data.allow_destructive_sync` is true (`AI_PAGE_BUILDER_DATA_DESTRUCTIVE=true`) — a guard so a mis-edit can't destroy data. The system columns `id`, `created_at`, `updated_at`, `deleted_at` are never dropped.

## The auto REST API

Every collection is exposed under `{data.api_prefix}/{model}` (default `api/pb`), resolved by collection key:

| Method | Path | Action |
|---|---|---|
| `GET` | `/api/pb/{model}` | List (paginated) |
| `POST` | `/api/pb/{model}` | Create |
| `GET` | `/api/pb/{model}/{id}` | Read one |
| `PUT`/`PATCH` | `/api/pb/{model}/{id}` | Update |
| `DELETE` | `/api/pb/{model}/{id}` | Delete |

The API middleware (`data.api_middleware`) is `EncryptCookies`, `StartSession`, `api` — so the built app's logged-in end-user (the `pb` guard) is recognised on same-origin XHR and permission + row-level rules apply. No CSRF is added, so stateless writes to *unrestricted* collections keep working. Permission enforcement happens in `RecordApiController` via `AccessControl` — see [Authentication & permissions](authentication-and-permissions.md).

### Query syntax (`RecordQuery`)

Directus-style query parameters, parsed by `src/Services/Data/RecordQuery.php`.

**Filter** — `filter[field][op]=value`:

| `op` | Meaning |
|---|---|
| `eq` | `=` (also the shorthand: `filter[status]=active`) |
| `neq` | `!=` |
| `gt` / `gte` | `>` / `>=` |
| `lt` / `lte` | `<` / `<=` |
| `like` | `LIKE '%value%'` |
| `in` / `nin` | `whereIn` / `whereNotIn` (array or comma-separated) |
| `null` / `nnull` | `whereNull` / `whereNotNull` |
| `between` | `whereBetween` (first two values of an array) |

```
GET /api/pb/leads?filter[status][eq]=active
GET /api/pb/leads?filter[id][in]=1,2,3
GET /api/pb/leads?filter[name][like]=acme
GET /api/pb/leads?filter[created_at][gte]=2026-01-01
```

**Sort** — `sort=field,-other` (comma-separated; leading `-` = descending; defaults to `id` ascending):

```
GET /api/pb/leads?sort=name,-created_at
```

**Fields (projection)** — `fields=a,b,c` (only defined/system columns; `id` always included):

```
GET /api/pb/leads?fields=name,email,status
```

**Search** — `search=term` (LIKE across `string`/`text`/`select` columns):

```
GET /api/pb/leads?search=acme
```

**Pagination** — `page` (default 1) and `per_page` (clamped between 1 and `data.max_per_page`; default `data.default_per_page` = 25).

```
GET /api/pb/leads?page=2&per_page=50
```

### Response envelope

`list()` returns Laravel's paginator JSON:

```json
{
  "data": [ { "id": 1, "name": "Acme", "...": "..." } ],
  "meta": { "current_page": 1, "from": 1, "last_page": 5, "per_page": 25, "to": 25, "total": 125 },
  "links": { "first": "...", "last": "...", "next": "...", "prev": null }
}
```

Single-record verbs return `{ "data": { ... } }`. `store` returns `201`; `destroy` returns `204`. A failed validation returns `422` with Laravel's error bag; a denied permission returns `403`.

## Reading/writing from Flows, Functions and pages

- **Flows** — the [`record` node](flows.md#record) does `list`/`get`/`create`/`update`/`delete` against a collection, going through `RecordQuery`.
- **Functions** — a `php`-runtime [Function](functions-and-states.md) can call `RecordQuery` directly (or use the `record` node).
- **Pages** — the [`data_table` block / `pbTable`](pages-and-components.md#data-tables-with-pbtable) fetches the list endpoint; a `data-pb-record="<collection>"` form POSTs a create.

Every one of these paths funnels through `RecordQuery`, so validation, column mapping (field key ↔ `_id`), and the row-level permission filter are applied consistently.

## Validation

`RecordQuery::validate()` builds rules from each field's `FieldType::validationRules($options)`. Input may be keyed by field key **or** physical column name (relations accept `manager` or `manager_id`). Updates validate in *partial* mode (only present fields; `required` is relaxed). Unmapped keys are dropped.

Next: [Flows](flows.md).
