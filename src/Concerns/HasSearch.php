<?php

namespace Raprmdn\DataTables\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;

trait HasSearch
{
    protected function search(): void
    {
        $searchable = $this->columnRegistry->searchableSources();
        $callbacks = $this->columnRegistry->searchCallbacks();

        if ($searchable === [] && $callbacks === []) {
            return;
        }

        $searchKey = $this->configValue('inertia-datatables.query_params.search', 'search');
        $search = $this->requestQuery($searchKey);

        if (! is_string($search) || ! $this->valueIsFilled($search)) {
            return;
        }

        $columns = [];
        $relationColumns = [];

        foreach ($searchable as $column) {
            if (str_contains($column, '.') && $this->query instanceof EloquentBuilder) {
                $separator = strrpos($column, '.');
                $relationColumns[substr($column, 0, $separator)][] = substr($column, $separator + 1);

                continue;
            }

            $columns[] = $column;
        }

        $this->query->where(function ($query) use ($search, $columns, $relationColumns, $callbacks) {
            foreach ($columns as $column) {
                $column = $this->query instanceof EloquentBuilder
                    ? $this->qualifyEloquentColumn($this->query, $column)
                    : $column;

                $this->orWhereSearchMatches($query, $column, $search);
            }

            foreach ($relationColumns as $relationPath => $relatedColumns) {
                $this->orWhereRelationSearchMatches($query, $relationPath, function ($related) use ($relatedColumns, $search) {
                    $related->where(function ($related) use ($relatedColumns, $search) {
                        foreach ($relatedColumns as $column) {
                            $this->orWhereSearchMatches($related, $this->qualifyEloquentColumn($related, $column), $search);
                        }
                    });
                });
            }

            foreach ($callbacks as $callback) {
                $query->orWhere(function ($query) use ($callback, $search) {
                    $callback($query, $search);
                });
            }
        });
    }

    /**
     * One subquery per relation path. A direct BelongsTo is matched through its
     * foreign key, which the database can evaluate once; OR-grouped EXISTS
     * subqueries run again for every base row.
     */
    private function orWhereRelationSearchMatches(EloquentBuilder $query, string $relationPath, Closure $constrain): void
    {
        $model = $this->query->getModel();
        $relation = ! str_contains($relationPath, '.') && method_exists($model, $relationPath)
            ? Relation::noConstraints(fn () => $model->{$relationPath}())
            : null;

        if (! $relation instanceof BelongsTo || $relation instanceof MorphTo) {
            $query->orWhereHas($relationPath, $constrain);

            return;
        }

        $related = $relation->getQuery()->select($relation->getQualifiedOwnerKeyName());
        $constrain($related);

        $query->orWhereIn(
            $this->qualifyEloquentColumn($this->query, $relation->getForeignKeyName()),
            $related,
        );
    }

    private function orWhereSearchMatches(EloquentBuilder|QueryBuilder $query, string $column, string $search): void
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $wrapped = $base->getGrammar()->wrap($column);
        // "!" is the escape character because a backslash needs different quoting per database.
        $pattern = '%' . str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], $search) . '%';

        if ($base->getConnection()->getDriverName() === 'pgsql') {
            $query->orWhereRaw("({$wrapped})::text ILIKE ? ESCAPE '!'", [$pattern]);

            return;
        }

        // JSON paths compare with a binary collation on MySQL, so they keep LOWER().
        if (str_contains($column, '->')) {
            $query->orWhereRaw("LOWER({$wrapped}) LIKE ? ESCAPE '!'", [mb_strtolower($pattern)]);

            return;
        }

        $query->orWhereRaw("{$wrapped} LIKE ? ESCAPE '!'", [$pattern]);
    }
}
