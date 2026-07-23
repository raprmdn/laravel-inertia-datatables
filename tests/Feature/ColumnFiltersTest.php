<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Raprmdn\DataTables\Column;
use Raprmdn\DataTables\Facades\DataTable;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Record;
use Tests\TestCase;

class ColumnFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $acme = Organization::query()->create(['name' => 'Acme']);
        $beta = Organization::query()->create(['name' => 'Beta Group']);

        Record::query()->create([
            'organization_id' => $acme->id,
            'name' => 'Alpha',
            'status' => 'active',
            'metadata' => ['channel' => 'web', 'tags' => ['red', 'blue']],
        ]);
        Record::query()->create([
            'organization_id' => $beta->id,
            'name' => 'Beta',
            'status' => 'inactive',
            'metadata' => ['channel' => 'api', 'tags' => ['green']],
        ]);
        Record::query()->create([
            'organization_id' => $acme->id,
            'name' => 'Gamma',
            'status' => null,
            'metadata' => ['channel' => 'web', 'tags' => ['red']],
        ]);
    }

    public function test_raw_public_keys_map_direct_and_relation_filters(): void
    {
        $result = DataTable::query(Record::query())
            ->applyFilters(['state:active', 'author:Acme'])
            ->columnDefinitions([
                Column::make('state', 'status')->filterable(),
                Column::make('author', 'organization.name')->filterable(),
            ])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha'], $result->pluck('name')->all());
    }

    public function test_values_keep_order_colons_and_unknown_aliases(): void
    {
        $received = null;

        DataTable::query(Record::query())
            ->columnDefinitions([
                Column::make('state')
                    ->filterable()
                    ->filterAliases([
                        'live' => 1,
                        'disabled' => false,
                        'nothing' => null,
                        'many' => ['active', 'inactive'],
                    ])
                    ->filterUsing(function (EloquentBuilder $query, array $values) use (&$received): void {
                        $received = $values;
                    }),
            ])
            ->applyFilters([
                'state:live',
                'state:https://example.test:8443',
                'state:disabled',
                'state:nothing',
                'state:many',
                'state:unknown',
            ])
            ->type('collection')
            ->make();

        $this->assertSame([
            1,
            'https://example.test:8443',
            false,
            null,
            ['active', 'inactive'],
            'unknown',
        ], $received);
    }

    public function test_typed_null_alias_remains_typed_for_exact_filtering(): void
    {
        $result = $this->filter(
            ['state:missing'],
            Column::make('state', 'status')->filterable()->filterAliases(['missing' => null]),
        );

        $this->assertSame(['Gamma'], $result->pluck('name')->all());
    }

    public function test_filter_callback_does_not_run_without_filterable_capability(): void
    {
        $called = false;

        $result = DataTable::query(Record::query())
            ->columnDefinitions([
                Column::make('state')->filterUsing(function () use (&$called): void {
                    $called = true;
                }),
            ])
            ->applyFilters(['state:active'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertFalse($called);
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $result->pluck('name')->all());
    }

    public function test_null_aliases_use_existing_null_operators(): void
    {
        $null = $this->filter(
            ['verification:unverified'],
            Column::make('verification', 'status')
                ->filterable()
                ->filterAliases(['verified' => 'NOT NULL', 'unverified' => 'NULL']),
        );
        $notNull = $this->filter(
            ['verification:verified'],
            Column::make('verification', 'status')
                ->filterable()
                ->filterAliases(['verified' => 'NOT NULL', 'unverified' => 'NULL']),
        );

        $this->assertSame(['Gamma'], $null->pluck('name')->all());
        $this->assertSame(['Alpha', 'Beta'], $notNull->pluck('name')->all());
    }

    public function test_multiple_values_are_or_grouped_and_public_keys_are_and_grouped(): void
    {
        $result = DataTable::query(Record::query())
            ->columnDefinitions([
                Column::make('first', 'status')->filterable(),
                Column::make('second', 'status')->filterable(),
            ])
            ->applyFilters(['first:active', 'first:inactive', 'second:inactive'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Beta'], $result->pluck('name')->all());
    }

    public function test_malformed_unknown_and_non_string_filters_are_ignored(): void
    {
        $result = DataTable::query(Record::query())
            ->columnDefinitions([Column::make('state', 'status')->filterable()])
            ->applyFilters(['missing-colon', ':value', 'unknown:value', null, 10, ['state:active']])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $result->pluck('name')->all());
    }

    public function test_array_alias_requires_json_or_custom_strategy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Array filter values require jsonContains() or filterUsing().');

        $this->filter(
            ['state:live'],
            Column::make('state', 'status')->filterable()->filterAliases(['live' => ['active']]),
        );
    }

    public function test_json_contains_is_definition_owned_and_json_paths_remain_exact(): void
    {
        $contains = DataTable::query(Record::query())
            ->columnDefinitions([
                Column::make('labels', 'metadata->tags')
                    ->filterable()
                    ->jsonContains()
                    ->filterAliases(['primary' => 'red']),
            ])
            ->applyFilters(['labels:primary'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();
        $exact = DataTable::query(Record::query())
            ->columnDefinitions([
                Column::make('channel', 'metadata->channel')->filterable(),
            ])
            ->applyFilters(['channel:api'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();
        $object = DataTable::query(Record::query())
            ->columnDefinitions([
                Column::make('metadata')
                    ->filterable()
                    ->jsonContains()
                    ->filterAliases(['web' => ['channel' => 'web']]),
            ])
            ->applyFilters(['metadata:web'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha', 'Gamma'], $contains->pluck('name')->all());
        $this->assertSame(['Beta'], $exact->pluck('name')->all());
        $this->assertSame(['Alpha', 'Gamma'], $object->pluck('name')->all());
    }

    public function test_json_contains_accepts_array_aliases(): void
    {
        $result = DataTable::query(Record::query())
            ->columnDefinitions([
                Column::make('labels', 'metadata->tags')
                    ->filterable()
                    ->jsonContains()
                    ->filterAliases(['both' => ['red', 'blue']]),
            ])
            ->applyFilters(['labels:both'])
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha'], $result->pluck('name')->all());
    }

    public function test_empty_json_array_alias_matches_only_array_values(): void
    {
        Record::query()->create(['name' => 'Missing Tags', 'metadata' => ['channel' => 'web']]);

        $result = DataTable::query(Record::query())
            ->columnDefinitions([
                Column::make('labels', 'metadata->tags')
                    ->filterable()
                    ->jsonContains()
                    ->filterAliases(['empty' => []]),
            ])
            ->applyFilters(['labels:empty'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $result->pluck('name')->all());
    }

    public function test_json_object_null_alias_distinguishes_null_from_missing_keys(): void
    {
        Record::query()->create(['name' => 'Null Channel', 'metadata' => ['channel' => null]]);
        Record::query()->create(['name' => 'Missing Channel', 'metadata' => []]);

        $result = DataTable::query(Record::query())
            ->columnDefinitions([
                Column::make('metadata')
                    ->filterable()
                    ->jsonContains()
                    ->filterAliases(['null-channel' => ['channel' => null]]),
            ])
            ->applyFilters(['metadata:null-channel'])
            ->type('collection')
            ->make();

        $this->assertSame(['Null Channel'], $result->pluck('name')->all());
    }

    public function test_query_builder_uses_the_same_resolved_filter_path(): void
    {
        $result = DataTable::query(DB::table('records'))
            ->columnDefinitions([Column::make('state', 'status')->filterable()])
            ->applyFilters(['state:inactive'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Beta'], $result->pluck('name')->all());
    }

    private function filter(array $filters, $definition)
    {
        return DataTable::query(Record::query())
            ->columnDefinitions([$definition])
            ->applyFilters($filters)
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();
    }
}
