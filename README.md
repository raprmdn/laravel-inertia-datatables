# Laravel Inertia Datatables

[![Latest Version on Packagist](https://img.shields.io/packagist/v/raprmdn/laravel-inertia-datatables.svg?style=flat-square)](https://packagist.org/packages/raprmdn/laravel-inertia-datatables)

> This package is currently in beta. It is usable, but the public API may still change before `v1.0.0`.

`raprmdn/laravel-inertia-datatables` is a Laravel server-side datatable query builder. The current core package is backend-only and can be used with Inertia, API resources, Blade, or any Laravel response.

Inertia React starter components remain planned future work. No React components, frontend runtime, or npm package are currently shipped.

## Compatibility

The package supports Laravel 10 through 13 on PHP versions allowed by each Laravel release and the package's PHP `^8.2` requirement.

| Laravel | Testbench | PHP tested by CI workflow |
| --- | --- | --- |
| 10 | 8 | 8.2 |
| 11 | 9 | 8.2 |
| 12 | 10 | 8.2 |
| 13 | 11 | 8.3 and 8.5 |

SQLite `:memory:` is the default local test database. The CI workflow also defines representative full-suite jobs for MySQL 8.0 and PostgreSQL 16.

> The workflow is implemented, but remote GitHub Actions and database service jobs have not yet been verified on GitHub.

Laravel 10 and 11 are retained for declared compatibility but are end-of-life. Their CI dependency-resolution jobs allow Composer to install advisory-affected framework versions; this does not claim ongoing framework security support.

## Installation

```bash
composer require raprmdn/laravel-inertia-datatables
```

## Publish Config

```bash
php artisan vendor:publish --tag=inertia-datatables-config
```

Published file:

```txt
config/inertia-datatables.php
```

## Configuration

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

* `query_params`: request query keys used by search, filters, sorting, and pagination. The `column` key is also used by map-only `parseSort()` calls.
* `date_format`: expected incoming date range format.
* `pagination`: default page size, max page size, and paginator link window.
* `json_columns`: columns or JSON paths that should use JSON contains filtering with `whereJsonContains`. Use this for JSON arrays, for example `filters->reward`. JSON scalar paths such as `filters->status` can usually be filtered normally.

## Basic Usage

```php
use App\Models\User;
use Raprmdn\DataTables\Facades\DataTable;

$users = DataTable::query(User::query())
    ->searchable(['name', 'email'])
    ->orderBy('created_at', 'desc')
    ->make();
```

## Demo

A complete Laravel + Inertia React example application demonstrating this package is available here:

Repository: https://github.com/raprmdn/laravel-inertia-datatable / https://raprmdn.dev/projects/laravel-inertia-datatable

## Query Parameters

### Search

```txt
?search=raprmdn
```

```php
->searchable(['name', 'email', 'contact.name'])
```

Searchable columns are trusted developer-defined identifiers, not request-provided column names. Search values use query bindings.

### Filters

```txt
?filters[]=status:new&filters[]=priority:High
```

Filters use a mapping where:

* **Key** is the filter name received from the request.
* **Value** is the database column or relationship column.

```php
$filterColumns = [
    'status'   => 'status',
    'priority' => 'priority.name',
];

[$columnFilters, $allowedFilters] = DataTable::parseFilters(
    $request->query('filters', []),
    $filterColumns,
);

DataTable::query($query)
    ->applyFilters($columnFilters)
    ->allowedFilters($allowedFilters)
    ->make();
```

Special filter values: `NULL`, `NOT NULL`.

Request-controlled filter columns must pass through `allowedFilters()`. Values use query bindings. Filters are split on the first colon, so values containing additional colons are preserved. Unknown parsed filters remain in parser output but are ignored during query application unless their mapped column is allowlisted.

### Filter Aliases

Filter column maps convert frontend column names to trusted backend columns. Optional filter aliases convert complete frontend filter expressions before column mapping.

```php
[$columnFilters, $allowedFilters] = DataTable::parseFilters(
    request()->query('filters', []),
    [
        'status' => 'email_verified_at',
    ],
    [
        'status:verified' => 'status:NOT NULL',
        'status:unverified' => 'status:NULL',
    ],
);
```

Aliases use exact full-string matching:

```txt
status:verified
-> status:NOT NULL
-> email_verified_at:NOT NULL
```

Unknown filters remain unchanged. Aliases are useful for nullable states, user-friendly enum labels, and frontend values that differ from stored values.

Map-only shorthand supports aliases through a named argument:

```php
DataTable::parseFilters(
    [
        'status' => 'email_verified_at',
    ],
    aliases: [
        'status:verified' => 'status:NOT NULL',
    ],
);
```

### JSON Filters

JSON scalar values can be filtered using Laravel JSON path syntax directly in your filter mapping.

```php
$filterColumns = [
    'redeem_status' => 'filters->status',
    'status'        => 'status',
];
```

Example request:

```txt
?filters[]=redeem_status:waiting_confirmation
```

This is useful for JSON object values like:

```json
{
    "status": "waiting_confirmation"
}
```

For JSON arrays, add the JSON path to `json_columns` so the filter uses `whereJsonContains`.

```php
// config/inertia-datatables.php
'json_columns' => [
    'filters->reward',
],
```

Then map the filter normally:

```php
$filterColumns = [
    'reward' => 'filters->reward',
];
```

Example request:

```txt
?filters[]=reward:44
```

This is useful for JSON array values like:

```json
{
    "reward": ["44", "45", "46"]
}
```

Use `json_columns` only for JSON columns or JSON paths that need contains matching. JSON scalar paths such as `filters->status` usually do not need to be listed there.

### Date Range Filters

```txt
?filters[]=created_at_from:01-01-2026&filters[]=created_at_to:31-12-2026
```

Date range filter keys should end with `_from` and `_to`.

```php
$filterColumns = [
    'status'     => 'status',
    'created_at' => 'created_at',
];

[$columnFilters, $allowedFilters, $dateRanges] = DataTable::parseFilters(
    $request->query('filters', []),
    $filterColumns,
);

DataTable::query($query)
    ->applyFilters($columnFilters)
    ->allowedFilters($allowedFilters)
    ->applyDateRanges($dateRanges)
    ->make();
```

Date input must strictly match `inertia-datatables.date_format`. Invalid formats and overflow dates such as `31-02-2026` throw `InvalidArgumentException` instead of being normalized.

Partial ranges are supported. `from` is inclusive from the start of its calendar day. `to` includes the entire selected calendar day by using an exclusive next-day boundary internally, so fractional-second timestamps on the final day remain included.

### Sorting

```txt
?col=created_at&sort=desc
```

Sorting uses a mapping where:

* **Key** is the column name received from the request.
* **Value** is the database column or relationship column.

```php
$sortColumns = [
    'name'       => 'name',
    'email'      => 'email',
    'created_at' => 'created_at',
];

[$sort, $allowedSorts] = DataTable::parseSort(
    $request->query('col'),
    $sortColumns,
);

DataTable::query($query)
    ->applySort($sort)
    ->allowedSorts($allowedSorts)
    ->orderBy('created_at', 'desc')
    ->make();
```

When the request column name is configured through `inertia-datatables.query_params.column`, use the map-only shorthand:

```php
[$sort, $allowedSorts] = DataTable::parseSort($sortColumns);
```

Map-only parsing reads the configured query parameter, rejects non-string request values, maps the selected key, and returns unique allowed backend sort columns.

Only `asc` and `desc` directions are valid. Invalid directions safely fall back to the configured default direction. Missing, invalid, or unapproved requested columns use the fallback `orderBy()` column.

If the requested column is empty or not found in the mapping, `$sort` is `null`. Passing it to `applySort(null)` is supported and uses fallback ordering.

### Pagination

```txt
?limit=25
```

```php
DataTable::query($query)
    ->perPage(25)
    ->make();
```

Use collection output when pagination is not needed:

```php
DataTable::query($query)
    ->type('collection')
    ->make();
```

Pagination defaults and maximum limits come from configuration. Collection output is unpaginated and does not apply paginator page-size behavior, even when a limit query parameter is present.

## Relations

Eloquent searching, filtering, date ranges, and supported sorting use relationship columns with **dot notation**.

```php
'contact.name'
'priority.sla_minutes'
'reason.parent.name'
```

The first part is the **relationship method** defined on your Eloquent model, and the last part is the **column** on the related table.

For example:

```php
'contact.name'
```

* `contact` → `contact()` relationship defined in the `Ticket` model.
* `name` → `name` column in the `contacts` table.

Nested relationships are also supported:

```php
'reason.parent.name'
```

* `reason` → relationship on `Ticket`
* `parent` → relationship on `Reason`
* `name` → column in the parent relation table.

Use Laravel relationship names for eager loading:

```php
->with(['contact.channel', 'priority'])
->withCount(['tickets'])
```

Relation sorting supports `BelongsTo` and `HasOne`, including self-referencing relations. Sorting `HasMany` or `BelongsToMany` relations throws `InvalidArgumentException` because one related row cannot be selected unambiguously.

### Query Builder Columns

Query Builder instances do not resolve Eloquent relationships. Dotted columns are treated as SQL table or alias references, so callers must add the required joins and aliases themselves.

```php
use Illuminate\Support\Facades\DB;

$query = DB::table('records')
    ->select('records.*')
    ->join('organizations as organization', 'organization.id', '=', 'records.organization_id');

$result = DataTable::query($query)
    ->applySort('organization.name')
    ->allowedSorts(['organization.name'])
    ->type('collection')
    ->make();
```

Use qualified columns when joins can make names ambiguous. Explicitly selecting the base table, such as `records.*`, also prevents same-named joined columns from replacing base result properties.

For nested Query Builder sort paths, the package converts relation segments to an underscore alias. For example, `organization.country.name` orders by `organization_country.name`; the caller must provide that alias.

## Available Methods

* `query($query)`: set the Eloquent or query builder instance.
* `with([...])`: eager load relationships.
* `withCount([...])`: eager load relationship counts.
* `searchable([...])`: set searchable columns and relation columns.
* `applyFilters([...])`: apply parsed filters.
* `allowedFilters([...])`: whitelist filter columns.
* `applyDateRanges([...])`: apply parsed date ranges.
* `applySort($column)`: set requested sort column. Accepts `null` to use default ordering.
* `allowedSorts([...])`: whitelist sort columns.
* `DataTable::parseFilters($filters, $filterColumns, $aliases = [])`: parse request filters into column filters, allowed filters, and date ranges.
* `DataTable::parseSort($column, $sortColumns)`: explicitly parse a requested sort column and allowed sorts from one mapping.
* `DataTable::parseSort($sortColumns)`: read the configured column query parameter and parse the sort map.
* `orderBy($column, $direction)`: set fallback order.
* `perPage($limit)`: set default pagination limit.
* `type('pagination')`: return paginated results.
* `type('collection')`: return collection results.
* `make()`: execute the query.

## Helper Methods

### `DataTable::parseFilters()`

`DataTable::parseFilters()` converts request filters into:

1. `$columnFilters` — normal column or relationship filters for `applyFilters()`.
2. `$allowedFilters` — unique mapped backend columns for `allowedFilters()`.
3. `$dateRanges` — date range filters for `applyDateRanges()`.

It accepts raw filters, a column map, and optional exact filter aliases:

```php
DataTable::parseFilters(
    $request->query('filters', []),
    $filterColumns,
    $filterAliases,
);
```

Example filter mapping:

```php
$filterColumns = [
    'status'     => 'status',
    'channel'    => 'contact.channel.name',
    'created_at' => 'created_at',
];
```

Example request:

```text
?filters[]=status:closed
&filters[]=channel:Instagram
&filters[]=created_at_from:01-05-2026
&filters[]=created_at_to:30-06-2026
```

Usage:

```php
[$columnFilters, $allowedFilters, $dateRanges] = DataTable::parseFilters(
    $request->query('filters', []),
    $filterColumns,
);
```

Result:

```php
$columnFilters = [
    'status:closed',
    'contact.channel.name:Instagram',
];

$allowedFilters = [
    'status',
    'contact.channel.name',
    'created_at',
];

$dateRanges = [
    'created_at' => [
        'from' => '01-05-2026',
        'to'   => '30-06-2026',
    ],
];
```

Then pass the result to the DataTable:

```php
DataTable::query($query)
    ->applyFilters($columnFilters)
    ->allowedFilters($allowedFilters)
    ->applyDateRanges($dateRanges)
    ->make();
```

### `DataTable::parseSort()`

`DataTable::parseSort()` converts a request sort key into:

1. `$sort` — selected database or relationship column for `applySort()`.
2. `$allowedSorts` — allowed sortable columns for `allowedSorts()`.

It supports explicit request input:

```php
DataTable::parseSort(
    $request->query('col'),
    $sortColumns,
);
```

It also supports configured map-only input:

```php
DataTable::parseSort($sortColumns);
```

The map-only form reads `inertia-datatables.query_params.column`.

Example sort mapping:

```php
$sortColumns = [
    'ticket'   => 'number',
    'channel'  => 'contact.channel.name',
    'priority' => 'priority.sla_minutes',
];
```

Example request:

```text
?col=channel&sort=asc
```

Usage:

```php
[$sort, $allowedSorts] = DataTable::parseSort(
    $request->query('col'),
    $sortColumns,
);
```

Result:

```php
$sort = 'contact.channel.name';

$allowedSorts = [
    'number',
    'contact.channel.name',
    'priority.sla_minutes',
];
```

Then pass the result to the DataTable:

```php
DataTable::query($query)
    ->applySort($sort)
    ->allowedSorts($allowedSorts)
    ->make();
```

## Example Inertia Controller

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

    $query = Ticket::query()->where('contact_id', $contact->id);

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

    $tickets = DataTable::query($query)
        ->with([
            'priority',
            'assignedTo',
            'contact.channel',
            'department',
            'reason.parent',
            'reason',
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
        ->applySort($sort)
        ->allowedSorts($allowedSorts)
        ->applyFilters($columnFilters)
        ->allowedFilters($allowedFilters)
        ->applyDateRanges($dateRanges)
        ->make();

    return inertia('contact/show', [
        'contact' => ContactResource::make($contact),
        'tickets' => TicketResource::collection($tickets),
    ]);
}
```

## API Example

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

## Upgrading To v0.3.0

`v0.3.0` changes the second value returned by `DataTable::parseFilters()`. This is a breaking API change.

Before:

```php
[$columnFilters, $dateRanges] =
    DataTable::parseFilters($filters, $map);
```

After, without date ranges:

```php
[$columnFilters, $allowedFilters] =
    DataTable::parseFilters($filters, $map);
```

After, with date ranges:

```php
[$columnFilters, $allowedFilters, $dateRanges] =
    DataTable::parseFilters($filters, $map);
```

## Inertia React Components

This package currently focuses on the Laravel backend query builder.

Publishable Inertia React starter components are planned for a future release. Until that release, you can use this package with your own Inertia, React, Vue, Blade, or API frontend.

## Testing

The package uses PHPUnit through Orchestra Testbench. SQLite `:memory:` is the local default.

```bash
composer test
vendor/bin/phpunit
```

The GitHub Actions workflow validates Composer metadata, the Laravel/PHP/Testbench matrix above, and representative MySQL 8.0 and PostgreSQL 16 jobs. Remote workflow results must be checked on GitHub; this README does not claim they have passed yet.

## Known Limitations

* Beta release, API may change.
* Inertia React components are planned but not part of the current beta release.
* String column and relation names inside arrays may not get perfect IDE autocomplete.
* Relation sorting supports `BelongsTo` and `HasOne`; `HasMany` and `BelongsToMany` throw `InvalidArgumentException`.
* Query Builder relation joins and aliases must be supplied by the caller.
* Advanced filter operators are not implemented yet.

## Roadmap

Completed foundation:

* PHPUnit and Orchestra Testbench suite
* Backend correctness hardening
* Laravel/PHP and database compatibility workflow

Future work:

* More filter operators
* Optional Inertia React starter components
* Column definitions API

## Contributing

Issues and pull requests are welcome while the package is in beta. Keep changes focused and backend-first unless the change is explicitly about planned frontend starter components.

## License

This package is open-sourced software licensed under the MIT license.
