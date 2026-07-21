<?php

namespace Raprmdn\DataTables\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use InvalidArgumentException;

trait HasFilters
{
    protected array $filters = [];

    protected array $allowedFilters = [];

    protected array $customFilters = [];

    protected array $jsonColumns = [];

    protected array $dateRanges = [];

    protected function filter()
    {
        if (empty($this->filters) || empty($this->allowedFilters)) {
            return $this->query;
        }

        $conditions = [];

        foreach ($this->filters as $filter) {
            if (! is_string($filter) || ! str_contains($filter, ':')) {
                continue;
            }

            [$column, $value] = explode(':', $filter, 2);

            if (! in_array($column, $this->allowedFilters, true)) {
                continue;
            }

            $conditions[$column][] = $value;
        }

        foreach ($conditions as $column => $values) {
            if (isset($this->customFilters[$column])) {
                ($this->customFilters[$column])(
                    $this->query,
                    $values,
                );

                continue;
            }

            if (str_contains($column, '.') && $this->query instanceof EloquentBuilder) {
                $parts = explode('.', $column);
                $relationPath = implode('.', array_slice($parts, 0, -1));
                $columnName = end($parts);

                $this->query->whereHas($relationPath, function ($query) use ($column, $columnName, $values) {
                    $columnName = $this->qualifyEloquentColumn($query, $columnName);

                    $this->applyConditions($query, $columnName, $values, $column);
                });

                continue;
            }

            $queryColumn = $this->query instanceof EloquentBuilder
                ? $this->qualifyEloquentColumn($this->query, $column)
                : $column;

            $this->applyConditions($this->query, $queryColumn, $values, $column);
        }

        return $this->query;
    }

    private function applyConditions($query, string $column, array $values, ?string $configuredColumn = null): void
    {
        $query->where(function ($query) use ($column, $values, $configuredColumn) {
            foreach ($values as $value) {
                if ($value === 'NULL') {
                    $query->orWhereNull($column);
                    continue;
                }

                if ($value === 'NOT NULL') {
                    $query->orWhereNotNull($column);
                    continue;
                }

                if ($this->isJsonColumn($column, $configuredColumn)) {
                    $query->orWhereJsonContains($column, $value);
                    continue;
                }

                $query->orWhere($column, $value);
            }
        });
    }

    protected function filterDateRanges(): void
    {
        if (empty($this->dateRanges) || empty($this->allowedFilters)) {
            return;
        }

        foreach ($this->dateRanges as $column => $range) {
            if (! in_array($column, $this->allowedFilters, true)) {
                continue;
            }

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

    private function isJsonColumn(string $column, ?string $configuredColumn = null): bool
    {
        return in_array($column, $this->jsonColumns, true)
            || ($configuredColumn !== null && in_array($configuredColumn, $this->jsonColumns, true));
    }
}
