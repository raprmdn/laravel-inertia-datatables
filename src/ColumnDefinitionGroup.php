<?php

namespace Raprmdn\DataTables;

use InvalidArgumentException;

final class ColumnDefinitionGroup
{
    /** @var list<ColumnDefinition> */
    private array $definitions;

    public function __construct(array $columns)
    {
        $normalized = [];

        foreach ($columns as $key => $value) {
            if (is_int($key)) {
                if (! is_string($value)) {
                    throw new InvalidArgumentException('Indexed column group entries must be column names.');
                }

                $normalized[] = [$value, null];

                continue;
            }

            if (! is_string($value)) {
                throw new InvalidArgumentException('Associative column group values must be column sources.');
            }

            $normalized[] = [$key, $value];
        }

        $this->definitions = array_map(
            fn (array $column): ColumnDefinition => new ColumnDefinition($column[0], $column[1]),
            $normalized,
        );
    }

    public function searchable(?string $source = null): self
    {
        return $this->apply(fn (ColumnDefinition $definition) => $definition->searchable($source));
    }

    public function filterable(?string $source = null): self
    {
        return $this->apply(fn (ColumnDefinition $definition) => $definition->filterable($source));
    }

    public function sortable(?string $source = null): self
    {
        return $this->apply(fn (ColumnDefinition $definition) => $definition->sortable($source));
    }

    public function dateRange(?string $source = null): self
    {
        return $this->apply(fn (ColumnDefinition $definition) => $definition->dateRange($source));
    }

    public function filterAliases(array $aliases): self
    {
        return $this->apply(fn (ColumnDefinition $definition) => $definition->filterAliases($aliases));
    }

    public function jsonContains(): self
    {
        return $this->apply(fn (ColumnDefinition $definition) => $definition->jsonContains());
    }

    public function filterUsing(callable $callback): self
    {
        return $this->apply(fn (ColumnDefinition $definition) => $definition->filterUsing($callback));
    }

    public function sortUsing(callable $callback): self
    {
        return $this->apply(fn (ColumnDefinition $definition) => $definition->sortUsing($callback));
    }

    /** @return list<ColumnDefinition> */
    public function definitions(): array
    {
        return array_map(fn (ColumnDefinition $definition) => clone $definition, $this->definitions);
    }

    private function apply(callable $callback): self
    {
        foreach ($this->definitions as $definition) {
            $callback($definition);
        }

        return $this;
    }
}
