<?php

namespace Raprmdn\DataTables\Facades;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Facade;
use Raprmdn\DataTables\DataTableBuilder;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @method static DataTableBuilder<TModel> query(EloquentBuilder<TModel>|QueryBuilder $query)
 * @method static array{0: array<int, string>, 1: array<string, array{from?: string, to?: string}>} parseFilters(array $filtersOrMap, array $map = [])
 * @method static array{0: string|null, 1: array<int, string>} parseSort(?string $sort, array $sortColumns)
 *
 * @see \Raprmdn\DataTables\DataTableManager
 */
class DataTable extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'inertia-datatables';
    }
}
