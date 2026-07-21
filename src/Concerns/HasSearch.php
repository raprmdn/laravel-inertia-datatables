<?php

namespace Raprmdn\DataTables\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

trait HasSearch
{
    protected array $searchable = [];

    protected function search(): void
    {
        if (empty($this->searchable)) {
            return;
        }

        $searchKey = $this->configValue('inertia-datatables.query_params.search', 'search');
        $search = $this->requestQuery($searchKey);

        if (! is_string($search) || ! $this->valueIsFilled($search)) {
            return;
        }

        $searchTerm = '%' . strtolower($search) . '%';

        $this->query->where(function ($query) use ($searchTerm) {
            foreach ($this->searchable as $column) {
                if (str_contains($column, '.') && $this->query instanceof EloquentBuilder) {
                    $parts = explode('.', $column);
                    $relationPath = implode('.', array_slice($parts, 0, -1));
                    $columnName = end($parts);

                    $query->orWhereHas($relationPath, function ($nestedQuery) use ($searchTerm, $columnName) {
                        $column = $this->qualifyEloquentColumn($nestedQuery, $columnName);
                        $column = $nestedQuery->getQuery()->getGrammar()->wrap($column);

                        $nestedQuery->whereRaw("LOWER({$column}) LIKE ?", [$searchTerm]);
                    });

                    continue;
                }

                $column = $this->query instanceof EloquentBuilder
                    ? $this->qualifyEloquentColumn($this->query, $column)
                    : $column;
                $grammar = $this->query instanceof EloquentBuilder
                    ? $this->query->getQuery()->getGrammar()
                    : $query->getGrammar();

                $query->orWhereRaw('LOWER(' . $grammar->wrap($column) . ') LIKE ?', [$searchTerm]);
            }
        });
    }
}
