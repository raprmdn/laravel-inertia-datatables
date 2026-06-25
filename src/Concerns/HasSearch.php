<?php

namespace Raprmdn\DataTables\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

trait HasSearch
{
    /**
     * @var array<int, string>
     */
    protected array $searchable = [];

    protected function search(): void
    {
        if (empty($this->searchable)) {
            return;
        }

        $searchKey = $this->configValue('inertia-datatables.query_params.search', 'search');
        $search = $this->requestQuery($searchKey);

        if (! $this->valueIsFilled($search)) {
            return;
        }

        $searchTerm = '%' . strtolower((string) $search) . '%';

        $this->query->where(function ($query) use ($searchTerm) {
            foreach ($this->searchable as $column) {
                if (str_contains($column, '.') && $this->query instanceof EloquentBuilder) {
                    $parts = explode('.', $column);
                    $relationPath = implode('.', array_slice($parts, 0, -1));
                    $columnName = end($parts);

                    $query->orWhereHas($relationPath, function ($nestedQuery) use ($searchTerm, $columnName) {
                        $nestedQuery->whereRaw("LOWER({$columnName}) LIKE ?", [$searchTerm]);
                    });

                    continue;
                }

                $query->orWhereRaw("LOWER({$column}) LIKE ?", [$searchTerm]);
            }
        });
    }
}
