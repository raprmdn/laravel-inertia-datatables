<?php

namespace Tests\Unit;

use InvalidArgumentException;
use Raprmdn\DataTables\Column;
use Raprmdn\DataTables\Support\FilterStrategy;
use Tests\TestCase;

class ColumnDefinitionGroupTest extends TestCase
{
    public function test_supports_indexed_associative_and_mixed_entries(): void
    {
        $definitions = Column::group([
            'title',
            'slug',
            'author' => 'user.name',
        ])->searchable()->sortable()->definitions();

        $this->assertSame(['title', 'slug', 'author'], array_map(fn ($column) => $column->name(), $definitions));
        $this->assertSame(['title', 'slug', 'user.name'], array_map(fn ($column) => $column->searchSource(), $definitions));
        $this->assertTrue($definitions[0]->isSortable());
    }

    public function test_capability_override_aliases_and_callbacks_apply_to_every_child(): void
    {
        $filter = fn () => null;
        $sort = fn () => null;
        $definitions = Column::group(['primary', 'secondary'])
            ->filterable('status')
            ->sortable('rank')
            ->filterAliases(['live' => 1])
            ->filterUsing($filter)
            ->sortUsing($sort)
            ->definitions();

        foreach ($definitions as $definition) {
            $this->assertSame('status', $definition->filterSource());
            $this->assertSame('rank', $definition->sortSource());
            $this->assertSame(['live' => 1], $definition->aliases());
            $this->assertSame(FilterStrategy::Custom, $definition->filterStrategy());
            $this->assertSame($filter, $definition->filterCallback());
            $this->assertSame($sort, $definition->sortCallback());
        }
    }

    public function test_children_and_returned_definitions_do_not_share_mutable_state(): void
    {
        $group = Column::group(['first', 'second'])->searchable();
        $firstRead = $group->definitions();
        $firstRead[0]->filterable()->filterAliases(['yes' => true]);
        $secondRead = $group->definitions();

        $this->assertFalse($secondRead[0]->isFilterable());
        $this->assertFalse($secondRead[1]->isFilterable());
        $this->assertSame([], $secondRead[1]->aliases());
    }

    public function test_empty_group_is_a_no_op(): void
    {
        $this->assertSame([], Column::group([])->searchable()->definitions());
    }

    public function test_group_validation_finishes_before_definitions_are_created(): void
    {
        $rejected = 0;

        foreach ([
            ['valid', 10],
            ['valid' => 'source', 'invalid' => null],
            ['valid', '   '],
        ] as $columns) {
            try {
                Column::group($columns);
            } catch (InvalidArgumentException) {
                $rejected++;
                continue;
            }

            $this->fail('Expected an InvalidArgumentException.');
        }

        $this->assertSame(3, $rejected);
    }
}
