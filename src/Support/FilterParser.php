<?php

namespace Raprmdn\DataTables\Support;

class FilterParser
{
    public static function parse(array $filters, array $map = []): array
    {
        $columnFilters = [];
        $dateRanges = [];

        foreach ($filters as $filter) {
            if (! is_string($filter) || ! str_contains($filter, ':')) {
                continue;
            }

            [$rawColumn, $value] = explode(':', $filter, 2);

            if ($rawColumn === '') {
                continue;
            }

            if (str_ends_with($rawColumn, '_from')) {
                $baseColumn = substr($rawColumn, 0, -5);
                $mappedColumn = $map[$baseColumn] ?? $baseColumn;
                $dateRanges[$mappedColumn]['from'] = $value;

                continue;
            }

            if (str_ends_with($rawColumn, '_to')) {
                $baseColumn = substr($rawColumn, 0, -3);
                $mappedColumn = $map[$baseColumn] ?? $baseColumn;
                $dateRanges[$mappedColumn]['to'] = $value;

                continue;
            }

            $mappedColumn = $map[$rawColumn] ?? $rawColumn;
            $columnFilters[] = "{$mappedColumn}:{$value}";
        }

        return [$columnFilters, $dateRanges];
    }
}
