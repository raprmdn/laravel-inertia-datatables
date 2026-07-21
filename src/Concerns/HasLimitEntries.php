<?php

namespace Raprmdn\DataTables\Concerns;

trait HasLimitEntries
{
    protected string $type = 'pagination';
    protected int $limit = 10;

    protected function limit()
    {
        if ($this->type === 'collection') {
            return $this->query->get();
        }

        $limitKey = $this->configValue('inertia-datatables.query_params.limit', 'limit');

        $requestedLimit = (int) $this->requestQuery($limitKey, $this->limit);
        $maxLimit = (int) $this->configValue('inertia-datatables.pagination.max_per_page', 100);

        $limit = max(1, min($requestedLimit, $maxLimit));
        $onEachSide = (int) $this->configValue('inertia-datatables.pagination.on_each_side', 1);

        return $this->query
            ->paginate($limit)
            ->onEachSide($onEachSide);
    }
}
