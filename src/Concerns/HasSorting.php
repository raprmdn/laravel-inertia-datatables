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
        $requestedDirection = $this->requestQuery($directionKey, $this->direction);
        $direction = is_string($requestedDirection)
            ? strtolower($requestedDirection)
            : $this->direction;

        if (! in_array($column, $this->allowedSorts, true)) {
            $column = $this->orderBy;
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = $this->direction;
        }

        if (str_contains($column, '.')) {
            return $this->applyRelationSort($column, $direction);
        }

        if ($this->query instanceof EloquentBuilder && ! $this->isSelectedAlias($column)) {
            $column = $this->qualifyEloquentColumn($this->query, $column);
        }

        return $this->query->orderBy($column, $direction);
    }

    private function isSelectedAlias(string $column): bool
    {
        $query = $this->query->getQuery();
        $grammar = $query->getGrammar();

        foreach ($query->columns ?? [] as $selected) {
            $selected = $grammar->getValue($selected);

            if (is_string($selected)
                && preg_match('/\s+as\s+[`"\[]?([^`"\]\s]+)[`"\]]?\s*$/i', $selected, $matches)
                && $matches[1] === $column) {
                return true;
            }
        }

        return false;
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

            if ($relation->getRelated()->getTable() === $model->getTable()) {
                return $this->query->orderBy(
                    $this->buildSelfRelationSortQuery($relation, $relationName, $sortColumn),
                    $direction,
                );
            }
        }

        $relationQuery = $this->buildRelationSortQuery(
            $this->query,
            $model,
            $parts,
            $sortColumn,
            count($parts) > 1,
        );

        return $this->query->orderBy($relationQuery, $direction);
    }

    private function buildSelfRelationSortQuery(
        Relation $relation,
        string $relationName,
        string $sortColumn,
    ): EloquentBuilder {
        if (! $relation instanceof BelongsTo && ! $relation instanceof HasOne) {
            throw new InvalidArgumentException("Unsupported relation type for sorting: {$relationName}.");
        }

        $table = $relation->getRelated()->getTable();
        $alias = "{$table}_{$relationName}_relation_sort";
        $query = $relation->getRelated()->newQuery()->from("{$table} as {$alias}");
        $query->getModel()->setTable($alias);
        $query->mergeConstraintsFrom($relation->getQuery());
        $query->select("{$alias}.{$sortColumn}");

        if ($relation instanceof BelongsTo) {
            $query->whereColumn(
                "{$alias}.{$relation->getOwnerKeyName()}",
                $this->qualifyEloquentColumn($this->query, $relation->getForeignKeyName()),
            );
        } else {
            $query->whereColumn(
                "{$alias}.{$relation->getForeignKeyName()}",
                $this->qualifyEloquentColumn($this->query, $relation->getLocalKeyName()),
            );
        }

        return $query->limit(1);
    }

    private function buildRelationSortQuery(
        EloquentBuilder $parentQuery,
        $parentModel,
        array $parts,
        string $sortColumn,
        bool $nested,
    ): EloquentBuilder {
        $parentModel = clone $parentModel;
        $parentTable = $this->eloquentTableReference($parentQuery);

        if ($parentTable !== null) {
            $parentModel->setTable($parentTable);
        }

        $relationName = array_shift($parts);
        $relation = $this->resolveRelation($parentModel, $relationName);

        if (! $relation instanceof BelongsTo && ! $relation instanceof HasOne) {
            if (! $nested) {
                throw new InvalidArgumentException("Unsupported relation type for sorting: {$relationName}.");
            }

            throw new InvalidArgumentException(
                'Unsupported relation type [' . get_class($relation) . "] for multi-level sort on [{$relationName}]."
            );
        }

        $query = $relation->getRelationExistenceQuery(
            $relation->getRelated()->newQuery(),
            $parentQuery,
        );
        $query->mergeConstraintsFrom($relation->getQuery());

        if ($parts === []) {
            $query->select($this->qualifyEloquentColumn($query, $sortColumn));
        } else {
            $nestedQuery = $this->buildRelationSortQuery(
                $query,
                $query->getModel(),
                $parts,
                $sortColumn,
                true,
            );

            $query->select([])->selectSub($nestedQuery, 'relation_sort_value');
        }

        return $query->limit(1);
    }

    private function resolveRelation($model, string $relationName): Relation
    {
        if (! method_exists($model, $relationName)) {
            throw new InvalidArgumentException('Relation [' . $relationName . '] does not exist on ' . get_class($model) . '.');
        }

        $relation = Relation::noConstraints(fn () => $model->{$relationName}());

        if (! $relation instanceof Relation) {
            throw new InvalidArgumentException('Method [' . $relationName . '] on ' . get_class($model) . ' is not an Eloquent relation.');
        }

        return $relation;
    }
}
