<?php

namespace Raprmdn\DataTables\Concerns;

use InvalidArgumentException;

trait HasRelations
{
    protected array $relationships = [];

    protected array $relationshipCounts = [];

    protected function mergeRelationships(array $current, string|array $incoming): array
    {
        $incoming = is_array($incoming) ? $incoming : [$incoming];

        if ($incoming === []) {
            return $current;
        }

        foreach ($incoming as $key => $relationship) {
            $name = is_string($key) ? $key : $relationship;

            if (is_string($name) && trim($name) === '') {
                throw new InvalidArgumentException('Relationship names must not be empty.');
            }
        }

        foreach ($incoming as $key => $relationship) {
            if (is_int($key)) {
                if ((is_string($relationship) && array_key_exists($relationship, $current))
                    || in_array($relationship, $current, true)) {
                    continue;
                }

                $current[] = $relationship;

                continue;
            }

            foreach ($current as $currentKey => $currentRelationship) {
                if (is_int($currentKey) && $currentRelationship === $key) {
                    unset($current[$currentKey]);
                }
            }

            $current[$key] = $relationship;
        }

        return $current;
    }

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
