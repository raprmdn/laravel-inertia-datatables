<?php

namespace Raprmdn\DataTables;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Raprmdn\DataTables\Support\FilterParser;

class DataTableManager
{
    /**
     * Create a new datatable builder instance for the given query.
     *
     * @template TModel of Model
     *
     * @param EloquentBuilder<TModel>|QueryBuilder $query
     * @return DataTableBuilder<TModel>
     */
    public function query(EloquentBuilder|QueryBuilder $query): DataTableBuilder
    {
        return (new DataTableBuilder())->query($query);
    }

    /**
     * Parse request filters into column filters and date ranges.
     *
     * @param array<int, string>|array<string, string> $filtersOrMap
     * @param array<string, string> $map
     * @return array{0: array<int, string>, 1: array<string, array{from?: string, to?: string}>}
     */
    public function parseFilters(array $filtersOrMap, array $map = []): array
    {
        if ($map === [] && ! array_is_list($filtersOrMap)) {
            $map = $filtersOrMap;
            $filterKey = $this->configValue('inertia-datatables.query_params.filters', 'filters');
            $filtersOrMap = $this->requestQuery($filterKey, []);
        }

        if (! is_array($filtersOrMap)) {
            $filtersOrMap = [];
        }

        return FilterParser::parse($filtersOrMap, $map);
    }

    /**
     * Parse requested sort column into selected sort and allowed sort columns.
     *
     * @param array<string, string> $sortColumns
     * @return array{0: string|null, 1: array<int, string>}
     */
    public function parseSort(?string $sort, array $sortColumns): array
    {
        $allowedSorts = array_values($sortColumns);

        if (! $sort || ! isset($sortColumns[$sort])) {
            return [null, $allowedSorts];
        }

        return [$sortColumns[$sort], $allowedSorts];
    }

    private function configValue(string $key, mixed $default = null): mixed
    {
        if (function_exists('config')) {
            return config($key, $default);
        }

        $container = Container::getInstance();

        if ($container->bound('config')) {
            return $container->make('config')->get($key, $default);
        }

        return $default;
    }

    private function requestQuery(string $key, mixed $default = null): mixed
    {
        if (function_exists('request')) {
            return request()->query($key, $default);
        }

        $container = Container::getInstance();

        if ($container->bound('request')) {
            return $container->make('request')->query($key, $default);
        }

        return $default;
    }
}
