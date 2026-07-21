<?php

namespace Raprmdn\DataTables;

use Countable;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Raprmdn\DataTables\Concerns\HasDataTable;
use Raprmdn\DataTables\Concerns\HasFilters;
use Raprmdn\DataTables\Concerns\HasLimitEntries;
use Raprmdn\DataTables\Concerns\HasRelations;
use Raprmdn\DataTables\Concerns\HasSearch;
use Raprmdn\DataTables\Concerns\HasSorting;

class DataTableBuilder
{
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
     * Which query to use for the data table.
     */
    public function query(EloquentBuilder|QueryBuilder $query): self
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Add relationships that should be eager loaded.
     *
     * Accepts one relationship or an indexed or constrained array.
     * Nested relationships may use dot notation, for example `contact.channel`.
     *
     * @param  array<array-key, array|\Closure|string>|string  $relationships
     * @return $this
     *
     * Examples: `channel`, `createdBy`, `contact.channel`.
     */
    public function with(string|array $relationships): self
    {
        $this->relationships = $this->mergeRelationships($this->relationships, $relationships);

        return $this;
    }

    /**
     * Add relationship counts that should be eager loaded.
     *
     * Accepts one relationship or an indexed or constrained array.
     *
     * @param  array<array-key, array|\Closure|string>|string  $relationships
     * @return $this
     *
     * Examples: `tickets`, `comments`.
     */
    public function withCount(string|array $relationships): self
    {
        $this->relationshipCounts = $this->mergeRelationships($this->relationshipCounts, $relationships);

        return $this;
    }

    /**
     * Set columns that are searchable.
     *
     * Normal columns should use the column name.
     * Relationship columns should use dot notation.
     *
     * Examples: `name`, `email`, `contact.name`, `reason.parent.name`.
     */
    public function searchable(array $searchable): self
    {
        $this->searchable = $searchable;

        return $this;
    }

    /**
     * Set filters that should be applied.
     *
     * Filters should be parsed before being passed to this method.
     * Use DataTable::parseFilters() to map public filter keys to trusted database columns.
     *
     * Format: `column:value`.
     * Relationship columns may use dot notation.
     *
     * Examples: `status:new`, `priority.name:High`, `creator.name:Rafi`.
     * Special values: `NULL`, `NOT NULL`, JSON `filters->status`
     */
    public function applyFilters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    /**
     * Set date ranges that should be applied.
     *
     * The array key is the database column.
     * The value should contain optional `from` and `to` keys.
     *
     * Example:
     * `created_at => ['from' => '01-01-2026', 'to' => '31-12-2026']`
     *
     * The incoming date format is controlled by config `inertia-datatables.date_format`.
     */
    public function applyDateRanges(array $dateRanges): self
    {
        $this->dateRanges = $dateRanges;

        return $this;
    }

    /**
     * Set columns that are allowed to be filtered.
     *
     * This prevents request-provided filters from leaking unwanted database columns
     * or relations.
     *
     * Examples: `status`, `priority.name`, `contact.channel.name`.
     */
    public function allowedFilters(array $allowedFilters): self
    {
        $this->allowedFilters = $allowedFilters;

        return $this;
    }

    /**
     * Register custom filtering behavior for an allowed filter key.
     */
    public function filterUsing(string $key, callable $callback): self
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('Custom filter key must not be empty.');
        }

        $this->customFilters[$key] = $callback;

        return $this;
    }

    /**
     * Set the requested sort column.
     *
     * This value is usually taken from the configured sort column query parameter.
     * The column should also be whitelisted using allowedSorts().
     *
     * Examples: `name`, `created_at`, `contact.name`.
     */
    public function applySort(?string $sort): self
    {
        $this->sort = $sort;

        return $this;
    }

    /**
     * Set columns that are allowed to be sorted.
     *
     * Normal columns should use the column name.
     * Relationship columns may use dot notation when supported.
     *
     * Examples: `number`, `created_at`, `priority.sla_minutes`, `contact.name`.
     */
    public function allowedSorts(array $allowedSorts): self
    {
        $this->allowedSorts = $allowedSorts;

        return $this;
    }

    /**
     * Set the result type.
     *
     * Supported types:
     * - `pagination`
     * - `collection`
     *
     * The default type is `pagination`.
     */
    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Set the default order column and direction.
     *
     * This is used when no valid requested sort column is provided.
     *
     * Examples: `created_at`, `desc`.
     */
    public function orderBy(string $column = 'created_at', string $direction = 'desc'): self
    {
        $this->orderBy = $column;
        $this->direction = $direction;

        return $this;
    }

    /**
     * Set the default number of entries per page.
     *
     * This is only used when the result type is `pagination`.
     * The maximum value is still limited by config `inertia-datatables.pagination.max_per_page`.
     *
     * Examples: `10`, `25`, `50`.
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

    protected function qualifyEloquentColumn(EloquentBuilder $query, string $column): string
    {
        if (str_contains($column, '.')) {
            return $column;
        }

        $table = $this->eloquentTableReference($query);

        return $table === null ? $column : "{$table}.{$column}";
    }

    protected function eloquentTableReference(EloquentBuilder $query): ?string
    {
        $from = $query->getQuery()->from;
        $fromExpression = ! is_string($from);

        if ($fromExpression) {
            $from = $query->getQuery()->getGrammar()->getValue($from);
        }

        if (! is_string($from)) {
            return null;
        }

        if (preg_match('/\s+as\s+[`"\[]?([^`"\]\s]+)[`"\]]?\s*$/i', $from, $matches)) {
            $alias = $matches[1];
            $prefix = $query->getQuery()->getConnection()->getTablePrefix();

            return $fromExpression && $prefix !== '' && str_starts_with($alias, $prefix)
                ? substr($alias, strlen($prefix))
                : $alias;
        }

        return str_contains(trim($from), ' ') ? null : trim($from);
    }
}
