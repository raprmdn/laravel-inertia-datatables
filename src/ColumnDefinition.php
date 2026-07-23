<?php

namespace Raprmdn\DataTables;

use InvalidArgumentException;
use Raprmdn\DataTables\Support\FilterStrategy;

final class ColumnDefinition
{
    private bool $searchable = false;

    private bool $filterable = false;

    private bool $sortable = false;

    private bool $dateRange = false;

    private ?string $searchSource = null;

    private ?string $filterSource = null;

    private ?string $sortSource = null;

    private ?string $dateRangeSource = null;

    private array $aliases = [];

    private ?FilterStrategy $filterStrategy = null;

    private mixed $filterCallback = null;

    private mixed $sortCallback = null;

    public function __construct(
        private readonly string $name,
        private ?string $defaultSource = null,
    ) {
        $this->validateName($name);
        $this->defaultSource = $this->validateSource($defaultSource);
    }

    public function searchable(?string $source = null): self
    {
        $this->searchable = true;

        if ($source !== null) {
            $this->searchSource = $this->validateSource($source);
        }

        return $this;
    }

    public function filterable(?string $source = null): self
    {
        $this->filterable = true;

        if ($source !== null) {
            $this->filterSource = $this->validateSource($source);
        }

        return $this;
    }

    public function sortable(?string $source = null): self
    {
        $this->sortable = true;

        if ($source !== null) {
            $this->sortSource = $this->validateSource($source);
        }

        return $this;
    }

    public function dateRange(?string $source = null): self
    {
        $this->dateRange = true;

        if ($source !== null) {
            $this->dateRangeSource = $this->validateSource($source);
        }

        return $this;
    }

    public function filterAliases(array $aliases): self
    {
        foreach ($aliases as $alias => $value) {
            $this->validateAliasValue($value);
            $this->aliases[(string) $alias] = $value;
        }

        return $this;
    }

    public function jsonContains(): self
    {
        $this->filterStrategy = FilterStrategy::JsonContains;
        $this->filterCallback = null;

        return $this;
    }

    public function filterUsing(callable $callback): self
    {
        $this->filterStrategy = FilterStrategy::Custom;
        $this->filterCallback = $callback;

        return $this;
    }

    public function sortUsing(callable $callback): self
    {
        $this->sortCallback = $callback;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function defaultSource(): ?string
    {
        return $this->defaultSource;
    }

    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    public function isFilterable(): bool
    {
        return $this->filterable;
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function hasDateRange(): bool
    {
        return $this->dateRange;
    }

    public function searchSource(): string
    {
        return $this->searchSource ?? $this->defaultSource ?? $this->name;
    }

    public function filterSource(): string
    {
        return $this->filterSource ?? $this->defaultSource ?? $this->name;
    }

    public function sortSource(): string
    {
        return $this->sortSource ?? $this->defaultSource ?? $this->name;
    }

    public function dateRangeSource(): string
    {
        return $this->dateRangeSource ?? $this->defaultSource ?? $this->name;
    }

    public function aliases(): array
    {
        return $this->aliases;
    }

    public function filterStrategy(): FilterStrategy
    {
        return $this->filterStrategy ?? FilterStrategy::Exact;
    }

    public function filterCallback(): mixed
    {
        return $this->filterCallback;
    }

    public function sortCallback(): mixed
    {
        return $this->sortCallback;
    }

    /** @internal */
    public function merge(self $definition): void
    {
        $this->searchable = $this->searchable || $definition->searchable;
        $this->filterable = $this->filterable || $definition->filterable;
        $this->sortable = $this->sortable || $definition->sortable;
        $this->dateRange = $this->dateRange || $definition->dateRange;

        if ($definition->defaultSource !== null) {
            $this->defaultSource = $definition->defaultSource;
        }

        foreach (['searchSource', 'filterSource', 'sortSource', 'dateRangeSource'] as $property) {
            if ($definition->{$property} !== null) {
                $this->{$property} = $definition->{$property};
            }
        }

        $this->aliases = array_replace($this->aliases, $definition->aliases);

        if ($definition->filterStrategy !== null) {
            $this->filterStrategy = $definition->filterStrategy;

            if ($definition->filterStrategy !== FilterStrategy::Custom) {
                $this->filterCallback = null;
            }
        }

        if ($definition->filterCallback !== null) {
            $this->filterCallback = $definition->filterCallback;
        }

        if ($definition->sortCallback !== null) {
            $this->sortCallback = $definition->sortCallback;
        }
    }

    private function validateName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Column name must not be empty.');
        }
    }

    private function validateSource(?string $source): ?string
    {
        if ($source !== null && trim($source) === '') {
            throw new InvalidArgumentException('Column source must not be empty.');
        }

        return $source;
    }

    private function validateAliasValue(mixed $value): void
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->validateAliasValue($item);
            }

            return;
        }

        throw new InvalidArgumentException(
            'Filter alias values must be scalar, null, or JSON-compatible arrays.'
        );
    }
}
