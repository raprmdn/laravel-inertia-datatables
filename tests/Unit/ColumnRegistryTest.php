<?php

namespace Tests\Unit;

use InvalidArgumentException;
use Raprmdn\DataTables\Column;
use Raprmdn\DataTables\Support\ColumnRegistry;
use Raprmdn\DataTables\Support\FilterStrategy;
use Tests\TestCase;

class ColumnRegistryTest extends TestCase
{
    public function test_merges_duplicate_definitions_with_deterministic_precedence(): void
    {
        $firstFilter = fn () => null;
        $secondFilter = fn () => null;
        $firstSort = fn () => null;
        $secondSort = fn () => null;
        $registry = new ColumnRegistry();

        $registry->register([
            Column::make('author', 'user.name')
                ->searchable('user.email')
                ->filterable()
                ->filterAliases(['active' => 1, 'replaced' => 'first'])
                ->filterUsing($firstFilter)
                ->sortUsing($firstSort),
            Column::make('author', 'writer.name')
                ->searchable()
                ->sortable('writer.rank')
                ->filterAliases(['replaced' => 'second'])
                ->filterUsing($secondFilter)
                ->sortUsing($secondSort),
        ]);

        $definition = $registry->find('author');

        $this->assertTrue($definition->isSearchable());
        $this->assertTrue($definition->isFilterable());
        $this->assertTrue($definition->isSortable());
        $this->assertSame('user.email', $definition->searchSource());
        $this->assertSame('writer.name', $definition->filterSource());
        $this->assertSame('writer.rank', $definition->sortSource());
        $this->assertSame(['active' => 1, 'replaced' => 'second'], $definition->aliases());
        $this->assertSame($secondFilter, $definition->filterCallback());
        $this->assertSame($secondSort, $definition->sortCallback());
    }

    public function test_null_sources_do_not_erase_previous_explicit_sources(): void
    {
        $registry = new ColumnRegistry();
        $registry->register([
            Column::make('author', 'user.name')->searchable('user.email'),
            Column::make('author')->searchable(),
        ]);

        $this->assertSame('user.email', $registry->find('author')->searchSource());
    }

    public function test_groups_expand_and_duplicate_names_merge(): void
    {
        $registry = new ColumnRegistry();
        $registry->register([
            Column::group(['title', 'title'])->searchable(),
            Column::make('title')->sortable(),
        ]);

        $this->assertCount(1, $registry->definitions());
        $this->assertTrue($registry->find('title')->isSearchable());
        $this->assertTrue($registry->find('title')->isSortable());
    }

    public function test_registration_is_atomic_for_invalid_entries(): void
    {
        $registry = new ColumnRegistry();

        try {
            $registry->register([Column::make('title')->searchable(), new \stdClass()]);
        } catch (InvalidArgumentException) {
            $this->assertSame([], $registry->definitions());

            return;
        }

        $this->fail('Expected an InvalidArgumentException.');
    }

    public function test_legacy_replacement_does_not_remove_explicit_capabilities(): void
    {
        $registry = new ColumnRegistry();
        $registry->replaceLegacySearchable(['title']);
        $registry->register([Column::make('title')->sortable()]);
        $registry->replaceLegacySearchable(['slug']);

        $this->assertFalse($registry->find('title')->isSearchable());
        $this->assertTrue($registry->find('title')->isSortable());
        $this->assertTrue($registry->find('slug')->isSearchable());
    }

    public function test_legacy_filter_and_sort_replacements_are_independent(): void
    {
        $registry = new ColumnRegistry();
        $registry->replaceLegacyFilters(['status']);
        $registry->replaceLegacySorts(['name']);
        $registry->replaceLegacyFilters(['state']);
        $registry->replaceLegacySorts(['created_at']);

        $this->assertNull($registry->filterable('status'));
        $this->assertNotNull($registry->filterable('state'));
        $this->assertTrue($registry->dateRange('state')->hasDateRange());
        $this->assertNull($registry->sortable('name'));
        $this->assertNotNull($registry->sortable('created_at'));
    }

    public function test_legacy_json_configuration_and_callbacks_keep_current_precedence(): void
    {
        $callback = fn () => null;
        $registry = new ColumnRegistry(['metadata->tags']);
        $registry->registerLegacyFilterCallback('metadata->tags', $callback);
        $registry->replaceLegacyFilters(['metadata->tags']);

        $definition = $registry->filterable('metadata->tags');

        $this->assertSame(FilterStrategy::Custom, $definition->filterStrategy());
        $this->assertSame($callback, $definition->filterCallback());
    }

    public function test_explicit_definitions_do_not_inherit_legacy_json_by_source(): void
    {
        $registry = new ColumnRegistry(['metadata->tags']);
        $registry->replaceLegacyFilters(['metadata->tags']);
        $registry->register([
            Column::make('tags', 'metadata->tags')->filterable(),
        ]);

        $this->assertSame(FilterStrategy::JsonContains, $registry->filterable('metadata->tags')->filterStrategy());
        $this->assertSame(FilterStrategy::Exact, $registry->filterable('tags')->filterStrategy());
    }

    public function test_same_name_explicit_definition_does_not_inherit_legacy_json_configuration(): void
    {
        $registry = new ColumnRegistry(['metadata->tags']);
        $registry->replaceLegacyFilters(['metadata->tags']);
        $registry->register([
            Column::make('metadata->tags')->filterable(),
        ]);

        $this->assertSame(FilterStrategy::Exact, $registry->filterable('metadata->tags')->filterStrategy());
    }

    public function test_searchable_projection_is_deduplicated_and_invalidated(): void
    {
        $registry = new ColumnRegistry();
        $registry->register([
            Column::make('title')->searchable('content'),
            Column::make('excerpt')->searchable('content'),
        ]);

        $this->assertSame(['content'], $registry->searchableSources());

        $registry->register([Column::make('slug')->searchable()]);

        $this->assertSame(['content', 'slug'], $registry->searchableSources());
    }

    public function test_multiple_public_keys_may_share_one_backend_source(): void
    {
        $registry = new ColumnRegistry();
        $registry->register([
            Column::make('author', 'user.name')->filterable(),
            Column::make('editor', 'user.name')->filterable(),
        ]);

        $this->assertSame('user.name', $registry->filterable('author')->filterSource());
        $this->assertSame('user.name', $registry->filterable('editor')->filterSource());
        $this->assertCount(2, $registry->definitions());
    }
}
