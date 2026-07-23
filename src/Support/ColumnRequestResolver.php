<?php

namespace Raprmdn\DataTables\Support;

final class ColumnRequestResolver
{
    public function __construct(private readonly ColumnRegistry $registry)
    {
    }

    public function resolve(array $filters, array $dateRanges, ?string $sort): array
    {
        [$resolvedFilters, $resolvedDateRanges] = $this->resolveFilters($filters);

        foreach ($dateRanges as $key => $range) {
            if (! is_string($key) || ! is_array($range)) {
                continue;
            }

            $definition = $this->registry->dateRange($key);

            if ($definition === null) {
                continue;
            }

            $name = $definition->name();
            $resolvedDateRanges[$name] ??= [
                'source' => $definition->dateRangeSource(),
            ];

            foreach (['from', 'to'] as $boundary) {
                if (array_key_exists($boundary, $range)) {
                    $resolvedDateRanges[$name][$boundary] = $range[$boundary];
                }
            }
        }

        return [
            'filters' => $resolvedFilters,
            'dateRanges' => $resolvedDateRanges,
            'sort' => $sort === null ? null : $this->registry->sortable($sort),
        ];
    }

    private function resolveFilters(array $filters): array
    {
        $resolvedFilters = [];
        $resolvedDateRanges = [];

        foreach ($filters as $filter) {
            if (! is_string($filter) || ! str_contains($filter, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $filter, 2);

            if ($key === '') {
                continue;
            }

            $definition = $this->registry->filterable($key);

            if ($definition !== null) {
                $name = $definition->name();
                $aliases = $definition->aliases();
                $resolvedFilters[$name] ??= [
                    'source' => $definition->filterSource(),
                    'strategy' => $definition->filterStrategy(),
                    'callback' => $definition->filterCallback(),
                    'values' => [],
                ];
                $resolvedFilters[$name]['values'][] = array_key_exists($value, $aliases)
                    ? $aliases[$value]
                    : $value;

                continue;
            }

            $boundary = null;
            $baseKey = null;

            if (str_ends_with($key, '_from')) {
                $boundary = 'from';
                $baseKey = substr($key, 0, -5);
            } elseif (str_ends_with($key, '_to')) {
                $boundary = 'to';
                $baseKey = substr($key, 0, -3);
            }

            if ($boundary === null || $value === '') {
                continue;
            }

            $definition = $this->registry->dateRange($baseKey);

            if ($definition === null) {
                continue;
            }

            $name = $definition->name();
            $resolvedDateRanges[$name] ??= [
                'source' => $definition->dateRangeSource(),
            ];
            $resolvedDateRanges[$name][$boundary] = $value;
        }

        return [$resolvedFilters, $resolvedDateRanges];
    }
}
