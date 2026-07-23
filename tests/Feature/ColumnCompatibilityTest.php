<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Raprmdn\DataTables\Column;
use Raprmdn\DataTables\Facades\DataTable;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Record;
use Tests\TestCase;

class ColumnCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $acme = Organization::query()->create(['name' => 'Acme']);
        $beta = Organization::query()->create(['name' => 'Beta Group']);
        Record::query()->create(['organization_id' => $acme->id, 'name' => 'Charlie', 'status' => 'active']);
        Record::query()->create(['organization_id' => $beta->id, 'name' => 'Alpha', 'status' => 'inactive']);
        Record::query()->create(['organization_id' => $acme->id, 'name' => 'Bravo', 'status' => 'active']);
    }

    public function test_existing_parser_workflow_remains_unchanged(): void
    {
        [$filters, $allowedFilters, $dateRanges] = DataTable::parseFilters(
            ['state:active'],
            ['state' => 'status'],
        );
        [$sort, $allowedSorts] = DataTable::parseSort('label', ['label' => 'name']);
        $this->setRequestQuery(['sort' => 'asc']);

        $result = DataTable::query(Record::query())
            ->applyFilters($filters)
            ->allowedFilters($allowedFilters)
            ->applyDateRanges($dateRanges)
            ->applySort($sort)
            ->allowedSorts($allowedSorts)
            ->type('collection')
            ->make();

        $this->assertSame(['Bravo', 'Charlie'], $result->pluck('name')->all());
    }

    public function test_parsed_backend_sources_and_public_definitions_coexist(): void
    {
        [$filters, $allowedFilters] = DataTable::parseFilters(
            ['author:Acme'],
            ['author' => 'organization.name'],
        );
        [$sort, $allowedSorts] = DataTable::parseSort(
            'author',
            ['author' => 'organization.name'],
        );
        $this->setRequestQuery(['sort' => 'asc']);

        $result = DataTable::query(Record::query())
            ->columnDefinitions([
                Column::make('author', 'organization.name')->filterable()->sortable(),
            ])
            ->applyFilters($filters)
            ->allowedFilters($allowedFilters)
            ->applySort($sort)
            ->allowedSorts($allowedSorts)
            ->type('collection')
            ->make();

        $this->assertSame(['Charlie', 'Bravo'], $result->pluck('name')->all());
    }

    public function test_runtime_values_may_be_registered_before_definitions(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);

        $result = DataTable::query(Record::query())
            ->applyFilters(['state:active'])
            ->applySort('label')
            ->columnDefinitions([
                Column::make('state', 'status')->filterable(),
                Column::make('label', 'name')->sortable(),
            ])
            ->type('collection')
            ->make();

        $this->assertSame(['Bravo', 'Charlie'], $result->pluck('name')->all());
    }

    public function test_repeated_legacy_setters_still_replace_previous_state(): void
    {
        $this->setRequestQuery(['search' => 'Charlie', 'sort' => 'asc']);

        $result = DataTable::query(Record::query())
            ->searchable(['name'])
            ->searchable(['status'])
            ->allowedFilters(['status'])
            ->allowedFilters(['name'])
            ->applyFilters(['status:active'])
            ->allowedSorts(['name'])
            ->allowedSorts(['status'])
            ->applySort('name')
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame([], $result->pluck('name')->all());
    }

    public function test_column_registry_state_does_not_leak_between_builders(): void
    {
        $configured = DataTable::query(Record::query())
            ->columnDefinitions([Column::make('state', 'status')->filterable()])
            ->applyFilters(['state:active'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();
        $fresh = DataTable::query(Record::query())
            ->applyFilters(['state:active'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Charlie', 'Bravo'], $configured->pluck('name')->all());
        $this->assertSame(['Charlie', 'Alpha', 'Bravo'], $fresh->pluck('name')->all());
    }

    public function test_definition_workflow_keeps_paginator_output_and_query_parameters(): void
    {
        $this->setRequestQuery([
            'filters' => ['state:active'],
            'col' => 'label',
            'sort' => 'asc',
            'limit' => 1,
        ]);

        $result = DataTable::query(Record::query())
            ->columnDefinitions([
                Column::make('state', 'status')->filterable(),
                Column::make('label', 'name')->sortable(),
            ])
            ->applyFilters(['state:active'])
            ->applySort('label')
            ->make();

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(2, $result->total());
        $this->assertSame(1, $result->perPage());
        $this->assertSame('Bravo', $result->items()[0]->name);
        $this->assertStringContainsString('col=label', $result->url(2));
        $this->assertStringContainsString('sort=asc', $result->url(2));
        $this->assertStringContainsString('limit=1', $result->url(2));
        $this->assertStringContainsString('filters%5B0%5D=state%3Aactive', $result->url(2));
    }
}
