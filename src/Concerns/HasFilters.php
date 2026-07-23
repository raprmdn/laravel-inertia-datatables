<?php

namespace Raprmdn\DataTables\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use InvalidArgumentException;
use Raprmdn\DataTables\Support\FilterStrategy;

trait HasFilters
{
    protected array $filters = [];

    protected array $dateRanges = [];

    protected array $resolvedFilters = [];

    protected array $resolvedDateRanges = [];

    protected function filter()
    {
        if ($this->resolvedFilters === []) {
            return $this->query;
        }

        foreach ($this->resolvedFilters as $condition) {
            $column = $condition['source'];
            $values = $condition['values'];

            if ($condition['strategy'] === FilterStrategy::Custom) {
                ($condition['callback'])(
                    $this->query,
                    $values,
                );

                continue;
            }

            if (str_contains($column, '.') && $this->query instanceof EloquentBuilder) {
                $parts = explode('.', $column);
                $relationPath = implode('.', array_slice($parts, 0, -1));
                $columnName = end($parts);

                $this->query->whereHas($relationPath, function ($query) use ($columnName, $values, $condition) {
                    $columnName = $this->qualifyEloquentColumn($query, $columnName);

                    $this->applyConditions($query, $columnName, $values, $condition['strategy']);
                });

                continue;
            }

            $queryColumn = $this->query instanceof EloquentBuilder
                ? $this->qualifyEloquentColumn($this->query, $column)
                : $column;

            $this->applyConditions($this->query, $queryColumn, $values, $condition['strategy']);
        }

        return $this->query;
    }

    private function applyConditions($query, string $column, array $values, FilterStrategy $strategy): void
    {
        $query->where(function ($query) use ($column, $values, $strategy) {
            foreach ($values as $value) {
                if ($value === 'NULL') {
                    $query->orWhereNull($column);
                    continue;
                }

                if ($value === 'NOT NULL') {
                    $query->orWhereNotNull($column);
                    continue;
                }

                if ($strategy === FilterStrategy::JsonContains) {
                    if (is_array($value)) {
                        if ($query->getConnection()->getDriverName() === 'sqlite') {
                            $query->orWhere(function ($query) use ($column, $value): void {
                                $this->applySqliteJsonArrayCondition($query, $column, $value);
                            });
                        } else {
                            $query->orWhereJsonContains($column, $value);
                        }

                        continue;
                    }

                    $query->orWhereJsonContains($column, $value);
                    continue;
                }

                if (is_array($value)) {
                    throw new InvalidArgumentException(
                        'Array filter values require jsonContains() or filterUsing().'
                    );
                }

                $query->orWhere($column, $value);
            }
        });
    }

    private function applySqliteJsonArrayCondition($query, string $column, array $value): void
    {
        if (array_is_list($value)) {
            if ($value === []) {
                $grammar = $query instanceof EloquentBuilder
                    ? $query->getQuery()->getGrammar()
                    : $query->getGrammar();
                $query->whereRaw('json_type(' . $grammar->wrap($column) . ") = 'array'");

                return;
            }

            foreach ($value as $item) {
                if (is_array($item)) {
                    throw new InvalidArgumentException(
                        'SQLite JSON containment does not support nested array alias values.'
                    );
                }

                $query->whereJsonContains($column, $item);
            }

            return;
        }

        foreach ($value as $key => $item) {
            $path = "{$column}->{$key}";

            if (is_array($item)) {
                $this->applySqliteJsonArrayCondition($query, $path, $item);

                continue;
            }

            if ($item === null) {
                $query->whereJsonContainsKey($path)->whereNull($path);

                continue;
            }

            $query->where($path, $item);
        }
    }

    protected function filterDateRanges(): void
    {
        if ($this->resolvedDateRanges === []) {
            return;
        }

        foreach ($this->resolvedDateRanges as $range) {
            $column = $range['source'];

            $from = $this->parseDateRangeValue($range['from'] ?? null);
            $to = $this->parseDateRangeValue($range['to'] ?? null);

            if (! $from && ! $to) {
                continue;
            }

            if (str_contains($column, '.') && $this->query instanceof EloquentBuilder) {
                $parts = explode('.', $column);
                $relationPath = implode('.', array_slice($parts, 0, -1));
                $columnName = end($parts);

                $this->query->whereHas($relationPath, function ($query) use ($columnName, $from, $to) {
                    $columnName = $this->qualifyEloquentColumn($query, $columnName);

                    $this->applyDateRangeCondition($query, $columnName, $from, $to);
                });

                continue;
            }

            $queryColumn = $this->query instanceof EloquentBuilder
                ? $this->qualifyEloquentColumn($this->query, $column)
                : $column;

            $this->applyDateRangeCondition($this->query, $queryColumn, $from, $to);
        }
    }

    private function parseDateRangeValue(mixed $value): ?string
    {
        if (! $this->valueIsFilled($value)) {
            return null;
        }

        $format = $this->configValue('inertia-datatables.date_format', 'd-m-Y');

        if (! is_string($value)) {
            throw new InvalidArgumentException("Invalid date value. Expected format: {$format}.");
        }

        try {
            $date = Carbon::createFromFormat($format, $value);
            $errors = Carbon::getLastErrors();
        } catch (\Throwable) {
            throw new InvalidArgumentException("Invalid date format for value [{$value}]. Expected format: {$format}.");
        }

        if (! $date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException("Invalid date format for value [{$value}]. Expected format: {$format}.");
        }

        return $date->format('Y-m-d');
    }

    private function applyDateRangeCondition($query, string $column, ?string $from, ?string $to): void
    {
        $toExclusive = $to
            ? Carbon::createFromFormat('Y-m-d', $to)->addDay()->format('Y-m-d') . ' 00:00:00'
            : null;

        if ($from && $to) {
            $query
                ->where($column, '>=', "{$from} 00:00:00")
                ->where($column, '<', $toExclusive);

            return;
        }

        if ($from) {
            $query->where($column, '>=', "{$from} 00:00:00");

            return;
        }

        if ($to) {
            $query->where($column, '<', $toExclusive);
        }
    }

}
