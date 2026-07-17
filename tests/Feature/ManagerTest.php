<?php

namespace Tests\Feature;

use Raprmdn\DataTables\DataTableBuilder;
use Raprmdn\DataTables\Facades\DataTable;
use Tests\Fixtures\Models\Record;
use Tests\TestCase;

class ManagerTest extends TestCase
{
    public function test_each_query_call_returns_a_fresh_builder(): void
    {
        $first = DataTable::query(Record::query());
        $second = DataTable::query(Record::query());

        $this->assertInstanceOf(DataTableBuilder::class, $first);
        $this->assertInstanceOf(DataTableBuilder::class, $second);
        $this->assertNotSame($first, $second);
    }

    public function test_builder_state_does_not_leak_between_query_chains(): void
    {
        Record::query()->create(['name' => 'Alpha']);
        Record::query()->create(['name' => 'Beta']);
        $this->setRequestQuery(['search' => 'Alpha']);

        $searched = DataTable::query(Record::query())
            ->searchable(['name'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $unsearched = DataTable::query(Record::query())
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha'], $searched->pluck('name')->all());
        $this->assertSame(['Alpha', 'Beta'], $unsearched->pluck('name')->all());
    }

    public function test_parse_sort_maps_requested_and_allowed_columns(): void
    {
        $result = DataTable::parseSort('organization', [
            'record' => 'name',
            'organization' => 'organization.name',
        ]);

        $this->assertSame([
            'organization.name',
            ['name', 'organization.name'],
        ], $result);
    }

    public function test_parse_sort_returns_null_for_missing_or_unknown_columns(): void
    {
        $map = ['record' => 'name'];

        $this->assertSame([null, ['name']], DataTable::parseSort(null, $map));
        $this->assertSame([null, ['name']], DataTable::parseSort('unknown', $map));
    }

    public function test_map_only_sort_reads_the_configured_column_parameter(): void
    {
        config()->set('inertia-datatables.query_params.column', 'column');
        $this->setRequestQuery(['column' => 'record']);

        $this->assertSame(
            ['name', ['name']],
            DataTable::parseSort(['record' => 'name', 'title' => 'name'])
        );
    }

    public function test_map_only_sort_safely_ignores_non_string_request_values(): void
    {
        config()->set('inertia-datatables.query_params.column', 'column');

        foreach ([['record'], (object) ['key' => 'record'], 10, null] as $value) {
            $this->setRequestQuery(['column' => $value]);

            $this->assertSame([null, ['name']], DataTable::parseSort(['record' => 'name']));
        }
    }
}
