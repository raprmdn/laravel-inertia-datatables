# Laravel Inertia Datatables

[![Latest Version on Packagist](https://img.shields.io/packagist/v/raprmdn/laravel-inertia-datatables.svg?style=flat-square)](https://packagist.org/packages/raprmdn/laravel-inertia-datatables)

> This package is currently in beta. It is usable, but the public API may still
> change before `v1.0.0`.

## Introduction

`raprmdn/laravel-inertia-datatables` is a backend-first Laravel query builder
for server-side datatables. It supports:

- Eloquent and Query Builder queries
- Declarative, allowlisted column definitions
- Search, exact filters, JSON filters, date ranges, and custom filters
- Direct, relation, and custom sorting
- Eager loading and relation counts
- Paginated or collection output

The package does not require Inertia, React, Tailwind, Ziggy, shadcn/ui, or an
npm package. Use it with Inertia, API resources, Blade, or any Laravel response.

`ColumnDefinition` is the primary public API. Define request-facing column keys
with `Column::make()` or `Column::group()`, enable only needed capabilities, and
register them with `columnDefinitions()`.

## Installation

```bash
composer require raprmdn/laravel-inertia-datatables
```

### Configuration

Optional:

```bash
php artisan vendor:publish --tag=inertia-datatables-config
```

Default configuration:

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
];
```

## Quick Start

Define public keys and pass raw request values to the builder. Definitions map
those keys to trusted backend columns during `make()`.

```php
use App\Models\Post;
use Illuminate\Http\Request;
use Raprmdn\DataTables\Column;
use Raprmdn\DataTables\Facades\DataTable;

public function index(Request $request)
{
    $posts = DataTable::query(Post::query())
        ->with('author:id,name')
        ->columnDefinitions([
            Column::group(['title', 'excerpt'])->searchable(),
            Column::make('author', 'author.name')
                ->searchable()
                ->filterable()
                ->sortable(),
            Column::make('status')->filterable()->sortable(),
            Column::make('created_at')->dateRange()->sortable(),
        ])
        ->applyFilters($request->query('filters', []))
        ->applySort($request->string('col')->toString() ?: null)
        ->orderBy('created_at', 'desc')
        ->make();

    return response()->json($posts);
}
```

Example request:

```text
?search=laravel
&filters[]=status:published
&filters[]=created_at_from:01-01-2026
&filters[]=created_at_to:31-01-2026
&col=author
&sort=asc
&limit=25
```

Unknown filter and sort keys are ignored because they have no matching enabled
definition.

## Column Definitions

### Public Keys and Sources

`Column::make()` creates one `ColumnDefinition`:

```php
Column::make('author', 'user.name')
    ->searchable()
    ->filterable()
    ->sortable();
```

The first argument is the public request key. The optional second argument is a
trusted default backend source.

```text
Public key:     author
Default source: user.name
```

Do not pass request input as a source. Sources are developer-defined SQL columns
or Eloquent relation paths.

### Capabilities

Definitions expose only capabilities you enable:

```php
Column::make('name')->searchable();
Column::make('status')->filterable();
Column::make('created_at')->sortable()->dateRange();
```

Available capability methods:

| Method | Enables |
| --- | --- |
| `searchable()` | Global search |
| `filterable()` | Exact, JSON, or custom filtering |
| `sortable()` | Requested sorting |
| `dateRange()` | `_from` and `_to` date filters |

`dateRange()` enables date filtering directly. It does not require
`filterable()`.

### Source Overrides

Each capability can override the default source:

```php
Column::make('author', 'user.name')
    ->searchable()
    ->filterable('user.id')
    ->sortable();
```

Source resolution order:

1. Capability-specific source
2. Default source
3. Public key

### Column Groups

Use `Column::group()` when columns share capabilities:

```php
Column::group([
    'title',
    'slug',
    'excerpt',
])->searchable()->sortable();
```

Associative entries map public keys to backend sources:

```php
Column::group([
    'title',
    'author' => 'user.name',
])->searchable()->sortable();
```

Groups expand to independent definitions during registration. A capability
source passed to a group is copied to every member; use separate definitions
when members need different overrides.

### Registration and Merging

Register definitions or groups with `columnDefinitions()`:

```php
->columnDefinitions([
    Column::group(['title', 'slug'])->searchable(),
    Column::make('author', 'user.name')->filterable(),
])
```

Registration is additive. Repeated public keys merge:

- Capabilities accumulate.
- Later explicit sources, strategies, and callbacks win.
- Alias maps merge, with later values replacing matching aliases.

Definitions may be registered before or after `applyFilters()` and
`applySort()`. Resolution happens during `make()`.

### Request Resolution

`applyFilters()` accepts raw `column:value` strings. `applySort()` accepts a
public key or `null`:

```php
->applyFilters($request->query('filters', []))
->applySort($request->string('col')->toString() ?: null)
```

Filter values split on the first colon, so values may contain more colons.
Malformed entries, non-string entries, and unknown public keys are ignored.

## Searching

Search reads the configured `search` query parameter:

```text
?search=raprmdn
```

Enable search on one definition or a group:

```php
->columnDefinitions([
    Column::group(['name', 'email'])->searchable(),
    Column::make('organization', 'organization.name')->searchable(),
])
```

Search behavior:

- Searchable sources are developer-defined.
- Search values use query bindings.
- Columns are OR-grouped inside one parenthesized condition.
- Existing base query constraints remain outside that group.
- Matching is case-insensitive through `LOWER(column) LIKE ?`.
- Empty search values leave the query unchanged.

For Eloquent, dotted sources are relation paths:

```php
Column::make('contact', 'contact.name')->searchable();
Column::make('reason', 'reason.parent.name')->searchable();
```

For Query Builder, dotted sources are SQL table or alias references.

## Filtering

Filters use the configured `filters` query parameter and `column:value` format:

```text
?filters[]=status:published&filters[]=priority:high
```

```php
->columnDefinitions([
    Column::make('status', 'status_code')->filterable(),
    Column::make('priority', 'priority.name')->filterable(),
])
->applyFilters($request->query('filters', []))
```

Values for one public key are OR-grouped. Different keys are applied as
separate AND groups. Values use query bindings.

### Filter Aliases

Map friendly request values to backend values:

```php
Column::make('verification', 'email_verified_at')
    ->filterable()
    ->filterAliases([
        'verified' => 'NOT NULL',
        'unverified' => 'NULL',
    ]);
```

Aliases are exact and case-sensitive. Unknown values pass through unchanged.
Values may be strings, integers, floats, booleans, `null`, or JSON-compatible
arrays.

### NULL Values

Exact values `NULL` and `NOT NULL` use `whereNull()` and `whereNotNull()`:

```text
?filters[]=verification:verified
```

Prefer aliases when exposing these operations to clients.

### JSON Filters

Scalar JSON paths normally use exact filtering:

```php
Column::make(
    'email_notifications',
    'settings->notifications->email',
)->filterable();
```

Use `jsonContains()` for JSON arrays or containment:

```php
Column::make('delivery_channel', 'channels')
    ->filterable()
    ->jsonContains()
    ->filterAliases([
        'mail' => 'email',
        'text' => 'sms',
    ]);
```

Array alias values require `jsonContains()` or `filterUsing()`. SQLite supports
scalar arrays and object maps; nested arrays need a custom filter because SQLite
lacks native structural JSON containment.

### Date Ranges

Enable ranges on the public base key:

```php
Column::make('created_at')->dateRange();
```

Clients send `_from` and `_to` keys:

```text
?filters[]=created_at_from:01-01-2026
&filters[]=created_at_to:31-01-2026
```

Either boundary may be omitted. Empty boundaries are ignored. Dates use the
configured Carbon format, defaulting to `d-m-Y`. Invalid dates throw
`InvalidArgumentException`.

The `from` boundary starts at midnight and is inclusive. The `to` boundary is
exclusive at the next midnight, preserving the complete selected day including
fractional-second timestamps. Comparisons do not wrap the database column in a
date function.

An explicitly filterable key such as `created_at_from` wins before suffix
detection.

### Custom Filters

Use a trusted callback when exact, relation, JSON, and date filtering are not
enough:

```php
use Illuminate\Database\Eloquent\Builder;

Column::make('category_state')
    ->filterable()
    ->filterUsing(function (Builder $query, array $values): void {
        $categorized = in_array('categorized', $values, true);
        $uncategorized = in_array('uncategorized', $values, true);

        if ($categorized === $uncategorized) {
            return;
        }

        $categorized
            ? $query->has('categories')
            : $query->doesntHave('categories');
    });
```

`filterUsing()` selects the strategy but does not enable filtering; also call
`filterable()`. The callback receives the original query and all alias-resolved
values for that public key. It runs once during `make()`, should only add trusted
constraints, and should not execute the query. Exceptions propagate.

Keep authorization, tenancy, and visibility constraints in the base query, not
in optional datatable filters.

## Sorting

Sorting uses the configured column and direction parameters:

```text
?col=created_at&sort=desc
```

Define public sort keys, then pass the requested key to `applySort()`:

```php
->columnDefinitions([
    Column::make('name')->sortable(),
    Column::make('organization', 'organization.name')->sortable(),
    Column::make('created_at')->sortable(),
])
->applySort($request->string('col')->toString() ?: null)
->orderBy('created_at', 'desc')
```

Unknown, missing, or `null` public keys use fallback ordering from `orderBy()`.
The fallback column need not be sortable.

Directions `asc` and `desc` are accepted case-insensitively. An invalid request
direction uses the fallback direction. The fallback direction itself must be
`asc` or `desc`; Laravel rejects invalid values during `make()`.

### Custom Sorting

Use `sortUsing()` for calculated values or application-specific ordering:

```php
Column::make('score')
    ->sortable()
    ->sortUsing(function ($query, string $direction): void {
        $query->orderBy('score', $direction);
    });
```

`sortUsing()` does not enable sorting; also call `sortable()`. Callback return
values are ignored.

## Relations

### Column Paths

Eloquent definitions use relation methods followed by the final column:

```php
->columnDefinitions([
    Column::make('contact', 'contact.name')
        ->searchable()
        ->filterable()
        ->sortable(),
    Column::make('reason', 'reason.parent.name')
        ->searchable()
        ->filterable(),
])
```

Search and filtering support nested relation paths. Date ranges also support
dotted Eloquent paths.

### Relation Sorting

Eloquent sorting supports `BelongsTo` and `HasOne`, including nested and
self-referencing relations. Sorting uses correlated scalar subqueries, keeps
relation constraints and related-model global scopes, and does not join the base
query.

`HasMany` and `BelongsToMany` sorting is ambiguous and throws
`InvalidArgumentException`. Missing or non-relation methods also fail
deterministically. Use `sortUsing()` when custom aggregation is intentional.

### Eager Loading

`with()` and `withCount()` accept one relationship or an array:

```php
->with(['contact.channel', 'priority'])
->withCount(['comments', 'tags'])
```

Repeated calls accumulate. Duplicate plain names are retained once. Laravel
count aliases remain valid exact strings.

Constrained definitions are preserved:

```php
->with([
    'comments' => fn ($query) => $query->latest()->limit(5),
])
->withCount([
    'comments' => fn ($query) => $query->where('approved', true),
])
```

A later constrained definition replaces an earlier constraint for the same
key. A constrained definition remains authoritative over a plain duplicate.

## Pagination & Collections

### Pagination

Pagination is the default result type. `make()` returns a length-aware paginator,
appends current request query parameters, and applies the configured link window.

```php
->perPage(25)
->make()
```

The configured `limit` request parameter overrides `perPage()`. The final value
is clamped from `1` through `pagination.max_per_page`.

### Collections

```php
->type('collection')
->make()
```

Collection output is unpaginated and ignores request and configured page-size
behavior. Eloquent returns models; Query Builder returns plain objects.

Search, filters, date ranges, and sorting preserve an existing `select()`.
Relation sorting does not add helper columns or replace selected columns.

## Query Builder

Query Builder supports search, exact and custom filters, JSON filters, date
ranges, sorting, pagination, and collections. Define columns the same way as for
Eloquent.

It does not resolve Eloquent relations. `with()` and `withCount()` become no-ops,
and dotted search or filter sources are SQL table or alias references.

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Raprmdn\DataTables\Column;
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

    return DataTable::query($query)
        ->columnDefinitions([
            Column::make('record', 'records.name')
                ->searchable()
                ->sortable(),
            Column::make('organization', 'organization.name')
                ->searchable()
                ->sortable(),
            Column::make('status', 'records.status')->filterable(),
        ])
        ->applyFilters($request->query('filters', []))
        ->applySort($request->string('col')->toString() ?: null)
        ->orderBy('records.created_at', 'desc')
        ->make();
}
```

Supply joins before passing the query to `DataTable::query()`. Aliases in dotted
sources must match query aliases. Qualify overlapping columns and select the base
table, such as `records.*`, to prevent joined values replacing result properties.

For Query Builder sorting only, a multi-part source such as
`organization.country.name` becomes `organization_country.name`; create that
alias yourself. Search and filter sources are used as configured.

## API Reference

### Column API

| Method | Purpose |
| --- | --- |
| `Column::make(string $name, ?string $source = null): ColumnDefinition` | Create one public-key definition. |
| `Column::group(array $columns): ColumnDefinitionGroup` | Create a group of independent definitions. |
| `searchable(?string $source = null): self` | Enable search, optionally overriding its source. |
| `filterable(?string $source = null): self` | Enable exact, JSON, or custom filtering. |
| `sortable(?string $source = null): self` | Enable requested sorting. |
| `dateRange(?string $source = null): self` | Enable `_from` and `_to` date filtering. |
| `filterAliases(array $aliases): self` | Merge exact value aliases. |
| `jsonContains(): self` | Select JSON containment filtering. |
| `filterUsing(callable $callback): self` | Select custom filtering; requires `filterable()`. |
| `sortUsing(callable $callback): self` | Set custom sorting; requires `sortable()`. |

Names and sources must not be empty. Invalid definitions throw
`InvalidArgumentException`.

### Manager API

| Method | Purpose |
| --- | --- |
| `DataTable::query(EloquentBuilder\|QueryBuilder $query): DataTableBuilder` | Return a fresh builder for the query. |

Each `query()` call returns a new mutable builder, so state does not leak between
query chains.

### Builder API

| Method | Purpose |
| --- | --- |
| `columnDefinitions(array $definitions): self` | Add definitions and groups. |
| `applyFilters(array $filters): self` | Replace raw filter expressions. |
| `applySort(?string $sort): self` | Replace requested public sort key. |
| `with(string\|array $relationships): self` | Accumulate Eloquent eager loads. |
| `withCount(string\|array $relationships): self` | Accumulate Eloquent relation counts. |
| `orderBy(string $column = 'created_at', string $direction = 'desc'): self` | Replace fallback ordering. |
| `perPage(int $limit): self` | Replace fallback page size. |
| `type(string $type): self` | Set `pagination` or `collection`. |
| `make()` | Resolve definitions, apply query state, and execute. |

Execution order is relations, search, filters, date ranges, sorting, then
pagination or collection retrieval.

### Request Parameters

| Default key | Purpose | Example |
| --- | --- | --- |
| `search` | Global search value | `?search=laravel` |
| `filters` | Repeated filter expressions | `?filters[]=status:open` |
| `col` | Public sort key | `?col=created_at` |
| `sort` | Sort direction | `?sort=desc` |
| `limit` | Requested page size | `?limit=25` |

All keys are configurable in `inertia-datatables.query_params`.

## Examples

**Example Application (GitHub):** [https://github.com/raprmdn/laravel-inertia-datatable](https://github.com/raprmdn/laravel-inertia-datatable)

### Inertia Controller

Inertia is one response option, not a package requirement:

```php
use App\Models\Ticket;
use Illuminate\Http\Request;
use Raprmdn\DataTables\Column;
use Raprmdn\DataTables\Facades\DataTable;

public function index(Request $request)
{
    $tickets = DataTable::query(Ticket::query())
        ->with(['priority', 'contact.channel', 'creator'])
        ->columnDefinitions([
            Column::group(['number', 'subject'])->searchable(),
            Column::make('customer', 'contact.name')
                ->searchable()
                ->sortable(),
            Column::make('priority', 'priority.name')
                ->filterable()
                ->sortable('priority.sla_minutes'),
            Column::make('status')->filterable()->sortable(),
            Column::make('created_by', 'creator.name')->filterable(),
            Column::make('created_at')->dateRange()->sortable(),
        ])
        ->applyFilters($request->query('filters', []))
        ->applySort($request->string('col')->toString() ?: null)
        ->make();

    return inertia('tickets/index', ['tickets' => $tickets]);
}
```

### API Resource

```php
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Raprmdn\DataTables\Column;
use Raprmdn\DataTables\Facades\DataTable;

Route::get('/users', function (Request $request) {
    $users = DataTable::query(User::query())
        ->columnDefinitions([
            Column::group(['name', 'email'])->searchable()->sortable(),
            Column::make('created_at')->dateRange()->sortable(),
        ])
        ->applyFilters($request->query('filters', []))
        ->applySort($request->string('col')->toString() ?: null)
        ->make();

    return UserResource::collection($users);
});
```

## Testing

Run package tests:

```bash
composer test
```

Equivalent direct command:

```bash
vendor/bin/phpunit
```

For package changes, also validate Composer metadata:

```bash
composer validate --strict
```

## Limitations

- Package remains beta; public API may change before `v1.0.0`.
- Eloquent relation sorting supports `BelongsTo` and `HasOne`, not `HasMany`
  or `BelongsToMany`.
- Query Builder joins and aliases must be supplied by the caller.
- String column and relation names may not receive complete IDE autocomplete.
- Generic frontend filter operators are not provided; use trusted
  `filterUsing()` callbacks for application-specific behavior.
- Frontend components are not included or required.

## Legacy API

Legacy APIs remain fully supported for backward compatibility. New code should
use column definitions.

Available legacy methods:

- `DataTable::parseFilters()`
- `DataTable::parseSort()`
- `searchable()`
- `allowedFilters()`
- `filterUsing()` on the builder
- `applyDateRanges()`
- `allowedSorts()`

Existing parser flow remains valid:

```php
[$filters, $allowedFilters, $dateRanges] = DataTable::parseFilters(
    $request->query('filters', []),
    ['status' => 'status', 'created_at' => 'created_at'],
);

[$sort, $allowedSorts] = DataTable::parseSort(
    $request->query('col'),
    ['name' => 'name', 'created_at' => 'created_at'],
);

$result = DataTable::query($query)
    ->searchable(['name'])
    ->applyFilters($filters)
    ->allowedFilters($allowedFilters)
    ->applyDateRanges($dateRanges)
    ->applySort($sort)
    ->allowedSorts($allowedSorts)
    ->make();
```

Legacy allowlists remain required because parser output alone does not authorize
SQL columns. Legacy JSON containment paths remain configured through
`json_columns`. Definitions and legacy methods may coexist; legacy setters keep
their replacement behavior while `columnDefinitions()` remains additive.

## Contributing

Issues and pull requests are welcome while the package is in beta. Keep changes
focused and backend-first. Public API changes require regression tests and README
updates.

## License

This package is open-sourced software licensed under the MIT license.
