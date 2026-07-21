# Laravel Inertia Datatables

[![Latest Version on Packagist](https://img.shields.io/packagist/v/raprmdn/laravel-inertia-datatables.svg?style=flat-square)](https://packagist.org/packages/raprmdn/laravel-inertia-datatables)

> This package is currently in beta. It is usable, but the public API may still
> change before `v1.0.0`.

## Introduction

`raprmdn/laravel-inertia-datatables` is a Laravel server-side datatable query
builder. It provides searching, allowlisted exact and custom filters, sorting,
relation support, validated date ranges, pagination, and collection output
without tying the backend to one presentation layer.

The package is backend-only and works with Inertia, API resources, Blade, or any
other Laravel response. Optional Inertia React starter components are planned
but are not currently shipped. There is no required frontend runtime or npm
package.

## Installation

```bash
composer require raprmdn/laravel-inertia-datatables
```

## Publishing Configuration

```bash
php artisan vendor:publish --tag=inertia-datatables-config
```

## Quick Start

```php
use App\Models\User;
use Raprmdn\DataTables\Facades\DataTable;

$users = DataTable::query(User::query())
    ->searchable(['name', 'email'])
    ->orderBy('created_at', 'desc')
    ->make();
```

## Configuration

The published configuration contains all request and result defaults:

```php
return [
    'query_params' => [
        'search'    => 'search',
        'filters'   => 'filters',
        'column'    => 'col',
        'direction' => 'sort',
        'limit'     => 'limit',
    ],

    'date_format' => 'd-m-Y',

    'pagination' => [
        'default_per_page' => 10,
        'max_per_page'     => 100,
        'on_each_side'     => 1,
    ],

    'json_columns' => [
        // 'channels',
        // 'filters->reward',
    ],
];
```

### Query Parameters

`query_params` configures request keys for search, filters, sort column, sort
direction, and page limit. The `column` and `filters` keys are also used by the
map-only parser forms.

### Date Format

`date_format` is passed to Carbon when parsing date ranges. Validation is strict
about parser errors, warnings, invalid calendar dates, and overflow dates.
Carbon does not enforce exact field width, so `1-1-2026` is accepted by the
default `d-m-Y` parser.

### Pagination

`default_per_page` sets the fallback page size, `max_per_page` caps the final
request limit, and `on_each_side` controls the paginator link window.

### JSON Columns

`json_columns` lists JSON columns or paths that should use
`whereJsonContains()`, normally for JSON arrays. Scalar JSON paths usually use
ordinary equality filtering.

## Searching

The configured search query parameter defaults to `search`:

```text
?search=raprmdn
```

### Basic Columns

```php
->searchable(['name', 'email'])
```

Searchable columns are trusted developer-defined identifiers. The request value
is lowercased, wrapped in `%`, and passed through query bindings. Searchable
columns are OR-grouped inside one parenthesized condition, preserving existing
query constraints. An empty column list or search value leaves the query
unchanged. Eloquent columns are qualified against their query table, and all
search identifiers are wrapped by the active database grammar.

### Relation Columns

Eloquent relations use dot notation, including nested paths:

```php
->searchable(['number', 'contact.name', 'reason.parent.name'])
```

For Query Builder, dotted strings are SQL table or alias references rather than
Eloquent relationships.

## Filtering

### Basic Filters

Filters use `column:value` expressions through the configured `filters` request
key:

```text
?filters[]=status:new&filters[]=priority:High
```

```php
$filterColumns = [
    'status'   => 'status',
    'priority' => 'priority.name',
];

[$columnFilters, $allowedFilters] = DataTable::parseFilters(
    $request->query('filters', []),
    $filterColumns,
);

$result = DataTable::query($query)
    ->applyFilters($columnFilters)
    ->allowedFilters($allowedFilters)
    ->make();
```

Values for the same column are OR-grouped. Different columns are applied as
separate AND groups. Values use query bindings.

### Allowed Filters

Parsed request filters must be paired with `allowedFilters()`. Expressions whose
column is not in the allowlist are ignored during query application. Parsing a
request does not make arbitrary request-provided columns safe automatically.

### Custom Filters

Use `filterUsing()` when a filter cannot be expressed using the built-in exact, relation, JSON, or date filters.

```php
filterUsing(string $key, callable $callback): self
```

The callback receives the original query builder and all values for the filter:

```php
function (
    EloquentBuilder|QueryBuilder $query,
    array $values,
): void
```

The callback runs once per matching filter during `make()`. Registering the same key again replaces the previous callback.

#### Example

```text
filters[]=category_state:categorized
filters[]=category_state:uncategorized
```

```php
$posts = DataTable::query(Post::query())
    ->applyFilters($columnFilters)
    ->allowedFilters($allowedFilters)
    ->filterUsing(
        'category_state',
        function (EloquentBuilder $query, array $values): void {
            $categorized = in_array('categorized', $values, true);
            $uncategorized = in_array('uncategorized', $values, true);

            if ($categorized === $uncategorized) {
                return;
            }

            if ($categorized) {
                $query->has('categories');

                return;
            }

            $query->doesntHave('categories');
        },
    )
    ->make();
```

#### Notes

- The filter key must also be included in `allowedFilters()`.
- The callback receives all selected values for that filter.
- Different custom filters are combined with `AND`.
- Multiple values for the same filter are handled by your callback.
- Eloquent-specific methods such as `has()` and `whereHas()` require an Eloquent Builder.
- Custom filters should only add query constraints. They should not execute the query.
- Authorization (ownership, tenancy, visibility) should remain in the base query, not in optional filters.

### Filter Mapping

Map request-facing names to trusted database or Eloquent relation columns:

```php
$filterColumns = [
    'status'     => 'status',
    'priority'   => 'priority.name',
    'created_at' => 'created_at',
];
```

The map values become the unique `$allowedFilters` output.

### Filter Aliases

Aliases replace complete frontend filter expressions before column mapping:

```php
[$columnFilters, $allowedFilters] = DataTable::parseFilters(
    request()->query('filters', []),
    ['status' => 'email_verified_at'],
    [
        'status:verified'   => 'status:NOT NULL',
        'status:unverified' => 'status:NULL',
    ],
);
```

Alias matching uses the exact full string:

```text
status:verified
-> status:NOT NULL
-> email_verified_at:NOT NULL
```

Unknown aliases leave the filter unchanged. Aliases do not add columns to the
allowlist. In map-only form, pass aliases by name:

```php
[$columnFilters, $allowedFilters] = DataTable::parseFilters(
    ['status' => 'email_verified_at'],
    aliases: ['status:verified' => 'status:NOT NULL'],
);
```

### NULL and NOT NULL

The exact values `NULL` and `NOT NULL` produce `whereNull()` and
`whereNotNull()` conditions:

```text
?filters[]=status:NULL
?filters[]=status:NOT NULL
```

### JSON Scalar Filters

Map JSON object values with Laravel arrow syntax:

```php
$filterColumns = [
    'redeem_status' => 'filters->status',
];
```

```text
?filters[]=redeem_status:waiting_confirmation
```

This filters object data such as:

```json
{
    "status": "waiting_confirmation"
}
```

### JSON Array Filters

Add array paths to `json_columns` so filtering uses `whereJsonContains()`:

```php
// config/inertia-datatables.php
'json_columns' => [
    'filters->reward',
],
```

Map and request the filter normally:

```php
$filterColumns = ['reward' => 'filters->reward'];
```

```text
?filters[]=reward:44
```

This supports array data such as:

```json
{
    "reward": ["44", "45", "46"]
}
```

### Date Range Filters

Date keys end in `_from` and `_to`:

```text
?filters[]=created_at_from:01-01-2026
&filters[]=created_at_to:31-12-2026
```

```php
[$columnFilters, $allowedFilters, $dateRanges] = DataTable::parseFilters(
    $request->query('filters', []),
    ['created_at' => 'created_at'],
);

$result = DataTable::query($query)
    ->applyFilters($columnFilters)
    ->allowedFilters($allowedFilters)
    ->applyDateRanges($dateRanges)
    ->make();
```

Only allowlisted date-range columns are applied. Non-empty values use the
configured Carbon format; parser errors, warnings, invalid calendar dates,
non-string values, and overflow dates throw `InvalidArgumentException`. Carbon
does not enforce exact field width. Unapproved date columns are ignored. Either
boundary may be omitted. `from` is inclusive from midnight. `to` includes the
entire selected calendar day through an exclusive next-midnight boundary,
including fractional-second timestamps. The generated comparisons do not wrap
the database column in `DATE()`, `CAST()`, or another date function.

## Sorting

### Basic Sorting

```text
?col=created_at&sort=desc
```

### Sort Mapping

Map frontend keys to trusted backend columns:

```php
$sortColumns = [
    'name'         => 'name',
    'organization' => 'organization.name',
    'created_at'   => 'created_at',
];

[$sort, $allowedSorts] = DataTable::parseSort(
    $request->query('col'),
    $sortColumns,
);
```

### Map-Only Parsing

When the configured column request key should be read automatically:

```php
[$sort, $allowedSorts] = DataTable::parseSort($sortColumns);
```

The request key defaults to `col` and is controlled by
`query_params.column`.

### Allowed Sorts

Pass the mapped allowlist to the builder:

```php
$result = DataTable::query($query)
    ->applySort($sort)
    ->allowedSorts($allowedSorts)
    ->orderBy('created_at', 'desc')
    ->make();
```

Unknown, missing, empty, or non-string requested keys produce a `null` sort and
still return the unique backend allowlist. Passing `null` to `applySort()` is
supported.

### Fallback Ordering

`orderBy()` supplies the fallback column and direction when no approved sort is
selected. The fallback column does not need to appear in `allowedSorts()`.

### Sort Directions

The configured direction request value is lowercased, so `asc` and `desc` are
accepted case-insensitively. Other request values use the direction stored by
`orderBy()`. `orderBy()` does not validate that stored direction immediately;
the stored value must also be `asc` or `desc` case-insensitively, or Laravel
throws `InvalidArgumentException` during `make()`.

## Pagination and Result Types

### Pagination

Pagination is the default, equivalent to `type('pagination')`. `make()` returns
a length-aware paginator, appends current request query parameters, and applies
the configured link window.

### Per-Page Limits

```text
?limit=25
```

`perPage(25)` sets the builder fallback. The request value overrides that
fallback. The final value is clamped between `1` and
`pagination.max_per_page`.

### Collection Output

```php
$result = DataTable::query($query)
    ->type('collection')
    ->make();
```

Collection output is unpaginated and ignores request or configured page-size
behavior. Eloquent returns models; Query Builder returns plain objects.

### Select Preservation

Search, filters, date ranges, and sorting preserve an existing `select()`.
Relation sorting does not add helper columns or replace the selected columns.

## Relations

### Dot Notation

Eloquent search, filtering, date ranges, and supported sorting use relationship
methods followed by the related column:

```php
'contact.name'
```

### Nested Relations

Nested paths include each relationship segment:

```php
'reason.parent.name'
```

Here `reason` is a relationship on the base model, `parent` is a relationship
on the related model, and `name` is the final column.

### Searching Relations

```php
->searchable(['contact.name', 'reason.parent.name'])
```

### Filtering Relations

```php
->applyFilters(['priority.name:High'])
->allowedFilters(['priority.name'])
```

### Sorting Relations

```php
->applySort('contact.name')
->allowedSorts(['contact.name'])
```

### Supported Sort Relations

Eloquent sorting supports `BelongsTo` and `HasOne`, including nested paths,
self-referencing `BelongsTo`, and self-referencing `HasOne` relations. Relation
sorts use correlated scalar subqueries, preserve relation constraints and
related-model global scopes, and do not add joins to the base query.

### Unsupported Sort Relations

`HasMany` and `BelongsToMany` sorting is ambiguous and throws
`InvalidArgumentException`. Missing relation methods and methods that are not
Eloquent relations also produce deterministic sorting exceptions.

### Eager Loading

Pass one Laravel relationship string or an array:

```php
->with('contact')
```

```php
->with(['contact.channel', 'priority'])
```

### Relation Counts

```php
->withCount('comments')
```

```php
->withCount(['comments', 'tags'])
```

Laravel count aliases remain supported as exact relationship strings.
Selected count aliases may also be mapped in `allowedSorts()`; they are ordered
as select aliases rather than qualified as physical model columns.

### Repeated `with()` Calls

Repeated calls accumulate eager loads. Duplicate plain names are retained once.

### Repeated `withCount()` Calls

Repeated calls accumulate count definitions and deduplicate duplicate plain
names. Distinct exact aliases remain distinct.

### Constrained Relations

Associative closure constraints are preserved for both methods:

```php
->with([
    'comments' => fn ($query) => $query->latest()->limit(5),
])
->withCount([
    'comments' => fn ($query) => $query->where('approved', true),
])
```

A later constrained definition replaces an earlier constraint for the same
exact key. A constrained definition remains authoritative over a plain duplicate
regardless of call order. Nested paths, column-selection syntax, and count
aliases are compared only by exact string equality.

## Query Builder

### Supported Behavior

Query Builder supports valid SQL columns for searching, allowlisted exact and
custom filters, date ranges, sorting, pagination, and collection output. Results
are plain objects rather than Eloquent models.

### Relationship Limitations

Query Builder does not resolve Eloquent relationship methods. `with()` and
`withCount()` are no-ops. Dotted search and filter strings are SQL table or
alias references.

### Manual Joins

Supply every required join before passing the query to the datatable:

```php
$query = DB::table('records')
    ->select('records.*')
    ->leftJoin(
        'organizations as organization',
        'organization.id',
        '=',
        'records.organization_id',
    );
```

### Aliases

The alias in a dotted column must match the alias in the query. For example,
`organization.name` requires a table or join alias named `organization`.

### Qualified Columns

Qualify columns whenever joined tables can contain the same names. Selecting
`records.*` prevents same-named joined columns such as `id`, `name`, or
`created_at` from replacing base result properties. Query Builder identifiers
remain caller-defined; the package does not infer their tables or aliases.

### Nested Sort Aliases

For Query Builder sorting, relation-like path segments are joined with
underscores. `organization.country.name` orders by
`organization_country.name`; the caller must create that alias. This conversion
applies to sorting, while search and filter columns are passed as configured SQL
references.

### Complete Example

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Raprmdn\DataTables\Facades\DataTable;

public function index(Request $request)
{
    $query = DB::table('records')
        ->select('records.*')
        ->leftJoin(
            'organizations as organization',
            'organization.id',
            '=',
            'records.organization_id',
        );

    [$columnFilters, $allowedFilters] = DataTable::parseFilters(
        $request->query('filters', []),
        ['status' => 'records.status'],
    );

    $sortColumns = [
        'record'       => 'records.name',
        'organization' => 'organization.name',
    ];

    [$sort, $allowedSorts] = DataTable::parseSort($sortColumns);

    return DataTable::query($query)
        ->searchable(['records.name', 'organization.name'])
        ->applyFilters($columnFilters)
        ->allowedFilters($allowedFilters)
        ->applySort($sort)
        ->allowedSorts($allowedSorts)
        ->orderBy('records.created_at', 'desc')
        ->make();
}
```

## Parser Helpers

Parser helpers normalize request-facing keys into developer-controlled backend
columns. They return builder state but do not execute a query.

### `parseFilters()`

Signature:

```php
DataTable::parseFilters(
    mixed $filtersOrMap,
    array $map = [],
    array $aliases = [],
): array
```

The explicit form receives raw filters, a map, and optional exact aliases:

```php
[$columnFilters, $allowedFilters, $dateRanges] = DataTable::parseFilters(
    $request->query('filters', []),
    $filterColumns,
    $filterAliases,
);
```

The map-only form receives an associative map as its first argument and reads
the configured filters query parameter:

```php
[$columnFilters, $allowedFilters, $dateRanges] = DataTable::parseFilters(
    $filterColumns,
    aliases: $filterAliases,
);
```

It always returns three positions:

1. `$columnFilters`: normal column or relation expressions for `applyFilters()`.
2. `$allowedFilters`: unique backend map values for `allowedFilters()`.
3. `$dateRanges`: mapped `_from` and `_to` values for `applyDateRanges()`.

Callers without date ranges may destructure the first two positions. Exact
aliases are applied before mapping and deduplication. Alias values do not add
columns to the allowlist.

Normalization behavior:

- Non-array raw input becomes an empty filter list.
- Non-string entries are discarded.
- Duplicate normalized filters are removed in first-occurrence order.
- Filters split on the first colon, preserving additional colons in values.
- Expressions without a colon or with an empty column are ignored.
- Unknown well-formed filters remain in `$columnFilters`, but cannot reach SQL
  without a matching builder allowlist entry.
- `_from` and `_to` suffixes become mapped date range entries.
- JSON paths remain normal mapped columns; `json_columns` controls containment.

Complete example:

```text
?filters[]=status:verified
&filters[]=priority:High
&filters[]=created_at_from:01-01-2026
&filters[]=created_at_to:31-01-2026
```

```php
$filterColumns = [
    'status'     => 'email_verified_at',
    'priority'   => 'priority.name',
    'created_at' => 'created_at',
];

[$columnFilters, $allowedFilters, $dateRanges] = DataTable::parseFilters(
    $request->query('filters', []),
    $filterColumns,
    ['status:verified' => 'status:NOT NULL'],
);
```

Expected output:

```php
$columnFilters = [
    'email_verified_at:NOT NULL',
    'priority.name:High',
];

$allowedFilters = [
    'email_verified_at',
    'priority.name',
    'created_at',
];

$dateRanges = [
    'created_at' => [
        'from' => '01-01-2026',
        'to'   => '31-01-2026',
    ],
];
```

### `parseSort()`

Signature:

```php
DataTable::parseSort(
    mixed $sort,
    array $sortColumns = [],
): array
```

The explicit form receives a requested frontend key and a map:

```php
[$sort, $allowedSorts] = DataTable::parseSort(
    $request->query('col'),
    $sortColumns,
);
```

The map-only form receives the associative map as its first argument and reads
`query_params.column`:

```php
[$sort, $allowedSorts] = DataTable::parseSort($sortColumns);
```

It returns a mapped backend column or `null`, plus unique backend map values in
first-occurrence order. Missing, empty, non-string, and unknown requested keys
produce `null`. Passing `null` to `applySort()` activates fallback ordering.

Complete example:

```text
?col=organization&sort=asc
```

```php
$sortColumns = [
    'name'         => 'name',
    'organization' => 'organization.name',
    'created_at'   => 'created_at',
];

[$sort, $allowedSorts] = DataTable::parseSort(
    $request->query('col'),
    $sortColumns,
);
```

Expected output:

```php
$sort = 'organization.name';
$allowedSorts = ['name', 'organization.name', 'created_at'];
```

The parser maps only the column. During `make()`, the builder reads and
normalizes the direction request value.

## API Reference

The facade resolves `DataTableManager`. Each `DataTable::query()` call returns a
fresh `DataTableBuilder`, so state does not leak between query chains. Except
for constructors, lifecycle methods, parser helpers, and `make()`, builder
methods return the same builder for fluent chaining.

### Manager Methods

| Method | Purpose and return behavior |
| --- | --- |
| `DataTable::query(EloquentBuilder\|QueryBuilder $query): DataTableBuilder` | Creates a fresh builder and sets its query. Supports both query types. |
| `DataTable::parseFilters(mixed $filtersOrMap, array $map = [], array $aliases = []): array` | Returns column filters, allowed filters, and date ranges without executing a query. |
| `DataTable::parseSort(mixed $sort, array $sortColumns = []): array` | Returns a mapped sort or `null` and unique allowed sorts without executing a query. |

### Builder Methods

| Method | State and behavior | Query applicability |
| --- | --- | --- |
| `__construct()` | Initializes pagination fallback and configured JSON paths. Prefer `DataTable::query()`. | No query yet |
| `query(EloquentBuilder\|QueryBuilder $query): self` | Replaces the stored query. | Both |
| `with(string\|array $relationships): self` | Accumulates, deduplicates, validates, and stores eager loads. | Eloquent; Query Builder no-op at execution |
| `withCount(string\|array $relationships): self` | Accumulates, deduplicates, validates, and stores relation counts. | Eloquent; Query Builder no-op at execution |
| `searchable(array $searchable): self` | Replaces searchable columns. | Both; dotted semantics differ |
| `applyFilters(array $filters): self` | Replaces filter expressions. | Both; requires matching allowlist |
| `allowedFilters(array $allowedFilters): self` | Replaces the trusted filter and date allowlist. | Both |
| `filterUsing(string $key, callable $callback): self` | Accumulates custom callbacks by mapped key; replacing an existing callback for the same key. | Both; callback receives the original builder |
| `applyDateRanges(array $dateRanges): self` | Replaces date range state and validates values during `make()`. | Both; dotted Eloquent paths use relations |
| `applySort(?string $sort): self` | Replaces the selected backend sort; `null` uses fallback ordering. | Both |
| `allowedSorts(array $allowedSorts): self` | Replaces the trusted sort allowlist. | Both |
| `orderBy(string $column = 'created_at', string $direction = 'desc'): self` | Replaces fallback ordering. | Both; dotted semantics differ |
| `perPage(int $limit): self` | Replaces the fallback page size. Request and maximum limits still apply. | Pagination for both |
| `type(string $type): self` | Replaces result type; valid values are `pagination` and `collection`. | Both |
| `make()` | Applies state and executes the query. | Both |

## Examples

The complete Laravel + Inertia React demo application is available at the
[demo repository](https://github.com/raprmdn/laravel-inertia-datatable) and
[project page](https://raprmdn.dev/projects/laravel-inertia-datatable).

### Inertia Controller

```php
use App\Http\Resources\ContactResource;
use App\Http\Resources\TicketResource;
use App\Models\Contact;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Raprmdn\DataTables\Facades\DataTable;

public function show(Request $request, Contact $contact)
{
    $contact->load(['channel', 'createdBy']);

    $filterColumns = [
        'status'      => 'status',
        'priority'    => 'priority.name',
        'channel'     => 'contact.channel.name',
        'reason_type' => 'reason.parent.name',
        'department'  => 'department.name',
        'assigned'    => 'assignedTo.name',
        'created_by'  => 'creator.name',
        'created_at'  => 'created_at',
    ];

    [$columnFilters, $allowedFilters, $dateRanges] = DataTable::parseFilters(
        $request->query('filters', []),
        $filterColumns,
    );

    $sortColumns = [
        'ticket'     => 'number',
        'status'     => 'status',
        'priority'   => 'priority.sla_minutes',
        'channel'    => 'contact.channel.name',
        'customer'   => 'contact.name',
        'department' => 'department.name',
        'assigned'   => 'assignedTo.name',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'created_by' => 'creator.name',
    ];

    [$sort, $allowedSorts] = DataTable::parseSort(
        $request->query('col'),
        $sortColumns,
    );

    $tickets = DataTable::query(
        Ticket::query()->where('contact_id', $contact->id),
    )
        ->with([
            'priority',
            'assignedTo',
            'contact.channel',
            'department',
            'reason.parent',
            'subReason',
            'creator',
            'updater',
        ])
        ->searchable([
            'number',
            'reason.name',
            'reason.parent.name',
            'subReason.name',
            'contact.name',
            'contact.email',
            'contact.phone',
        ])
        ->applyFilters($columnFilters)
        ->allowedFilters($allowedFilters)
        ->applyDateRanges($dateRanges)
        ->applySort($sort)
        ->allowedSorts($allowedSorts)
        ->make();

    return inertia('contact/show', [
        'contact' => ContactResource::make($contact),
        'tickets' => TicketResource::collection($tickets),
    ]);
}
```

### API Resource

```php
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Raprmdn\DataTables\Facades\DataTable;

Route::get('/users', function (Request $request) {
    $sortColumns = [
        'name'       => 'name',
        'email'      => 'email',
        'created_at' => 'created_at',
    ];

    [$sort, $allowedSorts] = DataTable::parseSort(
        $request->query('col'),
        $sortColumns,
    );

    $users = DataTable::query(User::query())
        ->searchable(['name', 'email'])
        ->applySort($sort)
        ->allowedSorts($allowedSorts)
        ->make();

    return UserResource::collection($users);
});
```

## Testing

```bash
composer test
vendor/bin/phpunit
```

## Known Limitations

- The package is beta and its public API may change before `v1.0.0`.
- Inertia React starter components are planned but not shipped.
- String column and relation names may not receive perfect IDE autocomplete.
- Relation sorting supports `BelongsTo` and `HasOne`, not `HasMany` or `BelongsToMany`.
- Query Builder joins and aliases must be supplied by the caller.
- Generic frontend filter operators are not implemented; use trusted
  `filterUsing()` callbacks for application-specific behavior.


## Contributing

Issues and pull requests are welcome while the package is in beta. Keep changes
focused and backend-first. Public API changes require regression tests and
corresponding README updates.

## License

This package is open-sourced software licensed under the MIT license.
