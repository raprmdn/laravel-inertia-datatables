<?php

namespace Raprmdn\DataTables\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

trait HasSorting
{
    protected string $orderBy = 'created_at';
    protected string $direction = 'desc';
    protected ?string $sort = null;

    protected array $allowedSorts = [];

    protected function sort()
    {
        $column = $this->sort ?: $this->orderBy;

        $directionKey = $this->configValue('inertia-datatables.query_params.direction', 'sort');
        $direction = strtolower((string) $this->requestQuery($directionKey, $this->direction));

        if (! in_array($column, $this->allowedSorts, true)) {
            $column = $this->orderBy;
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = $this->direction;
        }

        if (str_contains($column, '.')) {
            return $this->applyRelationSort($column, $direction);
        }

        return $this->query->orderBy($column, $direction);
    }

    protected function applyRelationSort(string $column, string $direction)
    {
        $parts = explode('.', $column);
        $sortColumn = array_pop($parts);

        if (! $this->query instanceof EloquentBuilder) {
            $table = implode('_', $parts);

            return $this->query->orderBy("{$table}.{$sortColumn}", $direction);
        }

        $model = $this->query->getModel();

        if (count($parts) === 1) {
            $relationName = $parts[0];
            $relation = $this->resolveRelation($model, $relationName);
            $related = $relation->getRelated();
            $relatedTable = $related->getTable();

            if ($relation instanceof BelongsTo) {
                $ownerKey = $relation->getOwnerKeyName();
                $foreignKey = $relation->getForeignKeyName();

                return $this->query->orderBy(
                    $related->newQuery()
                        ->select($sortColumn)
                        ->whereColumn("{$relatedTable}.{$ownerKey}", "{$model->getTable()}.{$foreignKey}")
                        ->limit(1),
                    $direction
                );
            }

            if ($relation instanceof HasOne) {
                $localKey = $relation->getLocalKeyName();
                $foreignKey = $relation->getForeignKeyName();

                return $this->query->orderBy(
                    $related->newQuery()
                        ->select($sortColumn)
                        ->whereColumn("{$relatedTable}.{$foreignKey}", "{$model->getTable()}.{$localKey}")
                        ->limit(1),
                    $direction
                );
            }

            throw new InvalidArgumentException("Unsupported relation type for sorting: {$relationName}.");
        }

        if (empty($this->query->getQuery()->columns)) {
            $this->query->select($model->getTable() . '.*');
        }

        $parentModel = $model;
        $parentTable = $model->getTable();

        foreach ($parts as $index => $relationName) {
            $relation = $this->resolveRelation($parentModel, $relationName);
            $relatedModel = $relation->getRelated();
            $relatedTable = $relatedModel->getTable();
            $relationAlias = "{$relatedTable}_{$relationName}_{$index}";

            $joins = $this->query->getQuery()->joins ?? [];
            $alreadyJoined = collect($joins)->contains(
                fn ($join) => $join->table === "{$relatedTable} as {$relationAlias}"
            );

            if (! $alreadyJoined) {
                if ($relation instanceof BelongsTo) {
                    $this->query->leftJoin(
                        "{$relatedTable} as {$relationAlias}",
                        "{$relationAlias}." . $relation->getOwnerKeyName(),
                        '=',
                        "{$parentTable}." . $relation->getForeignKeyName()
                    );
                } elseif ($relation instanceof HasOne) {
                    $this->query->leftJoin(
                        "{$relatedTable} as {$relationAlias}",
                        "{$relationAlias}." . $relation->getForeignKeyName(),
                        '=',
                        "{$parentTable}." . $relation->getLocalKeyName()
                    );
                } else {
                    throw new InvalidArgumentException(
                        'Unsupported relation type [' . get_class($relation) . "] for multi-level sort on [{$relationName}]."
                    );
                }
            }

            $parentModel = $relatedModel;
            $parentTable = $relationAlias;
        }

        return $this->query->orderBy("{$parentTable}.{$sortColumn}", $direction);
    }

    private function resolveRelation($model, string $relationName): Relation
    {
        if (! method_exists($model, $relationName)) {
            throw new InvalidArgumentException('Relation [' . $relationName . '] does not exist on ' . get_class($model) . '.');
        }

        $relation = $model->{$relationName}();

        if (! $relation instanceof Relation) {
            throw new InvalidArgumentException('Method [' . $relationName . '] on ' . get_class($model) . ' is not an Eloquent relation.');
        }

        return $relation;
    }
}
