<?php

namespace Tests\Unit;

use InvalidArgumentException;
use Raprmdn\DataTables\Column;
use Raprmdn\DataTables\Support\FilterStrategy;
use Tests\TestCase;

class ColumnDefinitionTest extends TestCase
{
    public function test_resolves_implicit_default_and_capability_sources(): void
    {
        $implicit = Column::make('title')->searchable()->filterable()->sortable()->dateRange();
        $mapped = Column::make('author', 'user.name')
            ->searchable()
            ->filterable('user.id')
            ->sortable()
            ->dateRange('user.created_at');

        $this->assertSame('title', $implicit->searchSource());
        $this->assertSame('title', $implicit->filterSource());
        $this->assertSame('title', $implicit->sortSource());
        $this->assertSame('title', $implicit->dateRangeSource());
        $this->assertSame('user.name', $mapped->searchSource());
        $this->assertSame('user.id', $mapped->filterSource());
        $this->assertSame('user.name', $mapped->sortSource());
        $this->assertSame('user.created_at', $mapped->dateRangeSource());
    }

    public function test_date_range_enables_only_the_date_capability(): void
    {
        $definition = Column::make('created_at')->dateRange();

        $this->assertTrue($definition->hasDateRange());
        $this->assertFalse($definition->isFilterable());
    }

    public function test_aliases_preserve_supported_types_and_merge_by_key(): void
    {
        $definition = Column::make('status')
            ->filterAliases([
                'integer' => 1,
                'float' => 1.5,
                'boolean' => false,
                'null' => null,
                'json' => ['state' => 'live'],
                'replaced' => 'old',
            ])
            ->filterAliases(['replaced' => 'new']);

        $this->assertSame([
            'integer' => 1,
            'float' => 1.5,
            'boolean' => false,
            'null' => null,
            'json' => ['state' => 'live'],
            'replaced' => 'new',
        ], $definition->aliases());
    }

    public function test_strategy_and_callbacks_do_not_enable_capabilities(): void
    {
        $filter = fn () => null;
        $sort = fn () => null;
        $definition = Column::make('score')->filterUsing($filter)->sortUsing($sort);

        $this->assertSame(FilterStrategy::Custom, $definition->filterStrategy());
        $this->assertSame($filter, $definition->filterCallback());
        $this->assertSame($sort, $definition->sortCallback());
        $this->assertFalse($definition->isFilterable());
        $this->assertFalse($definition->isSortable());
    }

    public function test_rejects_empty_names_and_sources(): void
    {
        $rejected = 0;

        foreach ([
            fn () => Column::make(''),
            fn () => Column::make('   '),
            fn () => Column::make('title', ''),
            fn () => Column::make('title')->searchable('   '),
            fn () => Column::make('title')->filterable(''),
            fn () => Column::make('title')->sortable('   '),
            fn () => Column::make('title')->dateRange(''),
        ] as $operation) {
            try {
                $operation();
            } catch (InvalidArgumentException) {
                $rejected++;
                continue;
            }

            $this->fail('Expected an InvalidArgumentException.');
        }

        $this->assertSame(7, $rejected);
    }

    public function test_rejects_non_json_compatible_alias_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Column::make('status')->filterAliases(['invalid' => new \stdClass()]);
    }
}
