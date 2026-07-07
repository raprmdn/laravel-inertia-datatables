<?php

namespace Raprmdn\DataTables\Concerns;

trait HasRelations
{
    protected array $relationships = [];

    protected array $relationshipCounts = [];

    protected function relations()
    {
        if (! empty($this->relationships) && method_exists($this->query, 'with')) {
            $this->query->with($this->relationships);
        }

        if (! empty($this->relationshipCounts) && method_exists($this->query, 'withCount')) {
            $this->query->withCount($this->relationshipCounts);
        }

        return $this->query;
    }
}
