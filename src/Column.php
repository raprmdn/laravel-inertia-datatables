<?php

namespace Raprmdn\DataTables;

final class Column
{
    public static function make(string $name, ?string $source = null): ColumnDefinition
    {
        return new ColumnDefinition($name, $source);
    }

    public static function group(array $columns): ColumnDefinitionGroup
    {
        return new ColumnDefinitionGroup($columns);
    }
}
