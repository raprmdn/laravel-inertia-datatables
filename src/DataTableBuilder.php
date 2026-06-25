<?php

namespace Raprmdn\DataTables;

use Countable;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Raprmdn\DataTables\Concerns\HasDataTable;
use Raprmdn\DataTables\Concerns\HasFilters;
use Raprmdn\DataTables\Concerns\HasLimitEntries;
use Raprmdn\DataTables\Concerns\HasRelations;
use Raprmdn\DataTables\Concerns\HasSearch;
use Raprmdn\DataTables\Concerns\HasSorting;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
class DataTableBuilder
{
    /** @use HasDataTable<TModel> */
    use HasDataTable;
    use HasRelations;
    use HasSearch;
    use HasFilters;
    use HasSorting;
    use HasLimitEntries;

    public function __construct()
    {
        $this->limit = (int) $this->configValue('inertia-datatables.pagination.default_per_page', 10);
        $this->jsonColumns = $this->configValue('inertia-datatables.json_columns', []);
    }

    /**
     * @param EloquentBuilder<TModel>|QueryBuilder $query
     * @return $this
     */
    public function query(EloquentBuilder|QueryBuilder $query): self
    {
        $this->query = $query;

        return $this;
    }

    /**
     * @param array<int, string> $relationships
     * @return $this
     */
    public function with(array $relationships): self
    {
        $this->relationships = $relationships;

        return $this;
    }

    /**
     * @param array<int, string> $relationships
     * @return $this
     */
    public function withCount(array $relationships): self
    {
        $this->relationshipCounts = $relationships;

        return $this;
    }

    /**
     * @param array<int, string> $searchable
     * @return $this
     */
    public function searchable(array $searchable): self
    {
        $this->searchable = $searchable;

        return $this;
    }

    /**
     * @param array<int, string> $filters
     * @return $this
     */
    public function applyFilters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    /**
     * @param array<string, array{from?: string, to?: string}> $dateRanges
     * @return $this
     */
    public function applyDateRanges(array $dateRanges): self
    {
        $this->dateRanges = $dateRanges;

        return $this;
    }

    /**
     * @param array<int, string> $allowedFilters
     * @return $this
     */
    public function allowedFilters(array $allowedFilters): self
    {
        $this->allowedFilters = $allowedFilters;

        return $this;
    }

    /**
     * @return $this
     */
    public function applySort(?string $sort): self
    {
        $this->sort = $sort;

        return $this;
    }

    /**
     * @param array<int, string> $allowedSorts
     * @return $this
     */
    public function allowedSorts(array $allowedSorts): self
    {
        $this->allowedSorts = $allowedSorts;

        return $this;
    }

    /**
     * @param 'pagination'|'collection' $type
     * @return $this
     */
    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return $this
     */
    public function orderBy(string $column = 'created_at', string $direction = 'desc'): self
    {
        $this->orderBy = $column;
        $this->direction = $direction;

        return $this;
    }

    /**
     * @return $this
     */
    public function perPage(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    protected function configValue(string $key, mixed $default = null): mixed
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

    protected function requestQuery(?string $key = null, mixed $default = null): mixed
    {
        if (function_exists('request')) {
            return request()->query($key, $default);
        }

        $container = Container::getInstance();

        if ($container->bound('request')) {
            return $container->make('request')->query($key, $default);
        }

        return $key === null ? [] : $default;
    }

    protected function valueIsFilled(mixed $value): bool
    {
        if (function_exists('filled')) {
            return filled($value);
        }

        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value) || $value instanceof Countable) {
            return count($value) > 0;
        }

        return true;
    }
}
