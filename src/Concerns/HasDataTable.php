<?php

namespace Raprmdn\DataTables\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
trait HasDataTable
{
    /**
     * @var EloquentBuilder<TModel>|QueryBuilder
     */
    protected EloquentBuilder|QueryBuilder $query;

    /**
     * Execute the datatable query.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Database\Eloquent\Collection<int, TModel>|\Illuminate\Support\Collection<int, mixed>
     */
    public function make()
    {
        if (! isset($this->query)) {
            throw new InvalidArgumentException('Query must be set before calling make().');
        }

        if (! in_array($this->type, ['pagination', 'collection'], true)) {
            throw new InvalidArgumentException('DataTable type must be pagination or collection.');
        }

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
