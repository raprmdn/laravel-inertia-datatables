<?php

namespace Raprmdn\DataTables\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Raprmdn\DataTables\Support\ColumnRequestResolver;

trait HasDataTable
{
    protected EloquentBuilder|QueryBuilder $query;

    /**
     * Execute the datatable query.
     */
    public function make()
    {
        if (! isset($this->query)) {
            throw new InvalidArgumentException('Query must be set before calling make().');
        }

        if (! in_array($this->type, ['pagination', 'collection'], true)) {
            throw new InvalidArgumentException('DataTable type must be pagination or collection.');
        }

        $resolved = (new ColumnRequestResolver($this->columnRegistry))->resolve(
            $this->filters,
            $this->dateRanges,
            $this->sort,
        );
        $this->resolvedFilters = $resolved['filters'];
        $this->resolvedDateRanges = $resolved['dateRanges'];
        $this->resolvedSort = $resolved['sort'];

        $this->relations();
        $this->search();
        $this->filter();
        $this->filterDateRanges();
        $this->sort();

        $result = $this->limit();

        if ($this->type === 'pagination') {
            $result->appends($this->requestQuery());
        }

        return $result;
    }
}
