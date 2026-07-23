<?php

namespace Raprmdn\DataTables\Support;

use InvalidArgumentException;
use Raprmdn\DataTables\Column;
use Raprmdn\DataTables\ColumnDefinition;
use Raprmdn\DataTables\ColumnDefinitionGroup;

final class ColumnRegistry
{
    private int $sequence = 0;

    private array $registrations = [];

    private array $legacyJsonColumns;

    private array $legacyFilterKeys = [];

    private array $explicitFilterKeys = [];

    /** @var array<string, ColumnDefinition>|null */
    private ?array $compiled = null;

    public function __construct(array $legacyJsonColumns = [])
    {
        $this->legacyJsonColumns = array_values(array_filter($legacyJsonColumns, 'is_string'));
    }

    public function register(array $entries): void
    {
        $definitions = [];

        foreach ($entries as $entry) {
            if ($entry instanceof ColumnDefinition) {
                $definitions[] = clone $entry;

                continue;
            }

            if ($entry instanceof ColumnDefinitionGroup) {
                array_push($definitions, ...$entry->definitions());

                continue;
            }

            throw new InvalidArgumentException(
                'Column definitions must be ColumnDefinition or ColumnDefinitionGroup instances.'
            );
        }

        foreach ($definitions as $definition) {
            if ($definition->isFilterable()) {
                $this->explicitFilterKeys[$definition->name()] = true;
            }

            $this->registrations['definition:' . ++$this->sequence] = [
                'sequence' => $this->sequence,
                'definitions' => [$definition],
            ];
        }

        $this->invalidate();
    }

    public function replaceLegacySearchable(array $columns): void
    {
        $this->replaceLegacy(
            'legacy:searchable',
            $this->legacyDefinitions($columns, fn (ColumnDefinition $column, string $source) => $column->searchable($source)),
        );
    }

    public function replaceLegacyFilters(array $columns): void
    {
        $this->legacyFilterKeys = [];
        foreach ($columns as $column) {
            if (is_string($column) && trim($column) !== '') {
                $this->legacyFilterKeys[$column] = true;
            }
        }

        $this->replaceLegacy(
            'legacy:filters',
            $this->legacyDefinitions($columns, function (ColumnDefinition $column, string $source): void {
                $column->filterable($source)->dateRange($source);
            }),
        );
    }

    public function replaceLegacySorts(array $columns): void
    {
        $this->replaceLegacy(
            'legacy:sorts',
            $this->legacyDefinitions($columns, fn (ColumnDefinition $column, string $source) => $column->sortable($source)),
        );
    }

    public function registerLegacyFilterCallback(string $key, callable $callback): void
    {
        $definition = Column::make($key)->filterUsing($callback);
        $this->replaceLegacy("legacy:filter-callback:{$key}", [$definition]);
    }

    public function find(string $name): ?ColumnDefinition
    {
        return $this->definitions()[$name] ?? null;
    }

    public function filterable(string $name): ?ColumnDefinition
    {
        $definition = $this->find($name);

        return $definition?->isFilterable() ? $definition : null;
    }

    public function dateRange(string $name): ?ColumnDefinition
    {
        $definition = $this->find($name);

        return $definition?->hasDateRange() ? $definition : null;
    }

    public function sortable(string $name): ?ColumnDefinition
    {
        $definition = $this->find($name);

        return $definition?->isSortable() ? $definition : null;
    }

    public function searchableSources(): array
    {
        $sources = [];

        foreach ($this->definitions() as $definition) {
            if ($definition->isSearchable()) {
                $sources[$definition->searchSource()] = true;
            }
        }

        return array_keys($sources);
    }

    /** @return array<string, ColumnDefinition> */
    public function definitions(): array
    {
        if ($this->compiled !== null) {
            return $this->compiled;
        }

        $registrations = $this->registrations;
        uasort($registrations, fn (array $left, array $right): int => $left['sequence'] <=> $right['sequence']);
        $compiled = [];

        foreach ($registrations as $registration) {
            foreach ($registration['definitions'] as $definition) {
                $name = $definition->name();

                if (! isset($compiled[$name])) {
                    $compiled[$name] = clone $definition;

                    continue;
                }

                $compiled[$name]->merge($definition);
            }
        }

        foreach ($this->legacyJsonColumns as $column) {
            $definition = $compiled[$column] ?? null;

            if (isset($this->legacyFilterKeys[$column])
                && ! isset($this->explicitFilterKeys[$column])
                && $definition?->isFilterable()
                && $definition->filterStrategy() === FilterStrategy::Exact
                && $definition->filterCallback() === null) {
                $definition->jsonContains();
            }
        }

        return $this->compiled = $compiled;
    }

    private function replaceLegacy(string $slot, array $definitions): void
    {
        $this->registrations[$slot] = [
            'sequence' => ++$this->sequence,
            'definitions' => $definitions,
        ];
        $this->invalidate();
    }

    private function legacyDefinitions(array $columns, callable $configure): array
    {
        $definitions = [];

        foreach ($columns as $source) {
            if (! is_string($source) || trim($source) === '') {
                continue;
            }

            $definition = Column::make($source);
            $configure($definition, $source);
            $definitions[] = $definition;
        }

        return $definitions;
    }

    private function invalidate(): void
    {
        $this->compiled = null;
    }
}
