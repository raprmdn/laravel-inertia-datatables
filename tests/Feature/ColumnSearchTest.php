<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Raprmdn\DataTables\Column;
use Raprmdn\DataTables\Facades\DataTable;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Record;
use Tests\TestCase;

class ColumnSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $acme = Organization::query()->create(['name' => 'Acme']);
        $beta = Organization::query()->create(['name' => 'Beta Group']);
        Record::query()->create(['organization_id' => $acme->id, 'name' => 'Alpha', 'status' => 'open']);
        Record::query()->create(['organization_id' => $beta->id, 'name' => 'Beta', 'status' => 'closed']);
    }

    public function test_grouped_and_mapped_definitions_drive_existing_search_path(): void
    {
        $this->setRequestQuery(['search' => 'acme']);

        $result = DataTable::query(Record::query())
            ->columnDefinitions([
                Column::group(['name', 'status'])->searchable(),
                Column::make('author', 'organization.name')->searchable(),
            ])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha'], $result->pluck('name')->all());
    }

    public function test_legacy_and_definition_search_configuration_merge(): void
    {
        $this->setRequestQuery(['search' => 'closed']);

        $result = DataTable::query(Record::query())
            ->searchable(['name'])
            ->columnDefinitions([
                Column::make('status')->searchable(),
            ])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Beta'], $result->pluck('name')->all());
    }

    public function test_query_builder_uses_definition_sources_as_sql_references(): void
    {
        $this->setRequestQuery(['search' => 'acme']);
        $query = DB::table('records')
            ->select('records.*')
            ->leftJoin('organizations as organization', 'organization.id', '=', 'records.organization_id');

        $result = DataTable::query($query)
            ->columnDefinitions([
                Column::make('organization', 'organization.name')->searchable(),
            ])
            ->orderBy('records.id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha'], $result->pluck('name')->all());
    }
}
