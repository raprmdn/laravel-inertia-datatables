<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Raprmdn\DataTables\Column;
use Raprmdn\DataTables\Facades\DataTable;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Record;
use Tests\TestCase;

class ColumnDateRangesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $january = Organization::query()->create([
            'name' => 'January Org',
            'created_at' => '2026-01-10 12:00:00',
            'updated_at' => '2026-01-10 12:00:00',
        ]);
        $february = Organization::query()->create([
            'name' => 'February Org',
            'created_at' => '2026-02-10 12:00:00',
            'updated_at' => '2026-02-10 12:00:00',
        ]);

        Record::query()->create([
            'organization_id' => $january->id,
            'name' => 'Alpha',
            'created_at' => '2026-01-15 10:00:00',
            'updated_at' => '2026-01-15 10:00:00',
        ]);
        Record::query()->create([
            'organization_id' => $february->id,
            'name' => 'Beta',
            'created_at' => '2026-02-15 10:00:00',
            'updated_at' => '2026-02-15 10:00:00',
        ]);
    }

    public function test_raw_mapped_date_ranges_resolve_when_definitions_are_registered_later(): void
    {
        $result = DataTable::query(Record::query())
            ->applyFilters(['created_from:01-01-2026', 'created_to:31-01-2026'])
            ->columnDefinitions([
                Column::make('created', 'created_at')->dateRange(),
            ])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha'], $result->pluck('name')->all());
    }

    public function test_relation_date_range_uses_capability_override(): void
    {
        $result = DataTable::query(Record::query())
            ->columnDefinitions([
                Column::make('organization_date')->dateRange('organization.created_at'),
            ])
            ->applyFilters([
                'organization_date_from:01-01-2026',
                'organization_date_to:31-01-2026',
            ])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha'], $result->pluck('name')->all());
    }

    public function test_empty_date_boundaries_are_absent(): void
    {
        $result = DataTable::query(Record::query())
            ->columnDefinitions([Column::make('created', 'created_at')->dateRange()])
            ->applyFilters(['created_from:', 'created_to:'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha', 'Beta'], $result->pluck('name')->all());
    }

    public function test_exact_public_key_wins_over_date_suffix_interpretation(): void
    {
        $result = DataTable::query(Record::query())
            ->columnDefinitions([
                Column::make('created', 'created_at')->dateRange(),
                Column::make('created_from', 'name')->filterable(),
            ])
            ->applyFilters(['created_from:Alpha'])
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha'], $result->pluck('name')->all());
    }

    public function test_explicit_legacy_date_ranges_resolve_against_definitions(): void
    {
        $result = DataTable::query(Record::query())
            ->columnDefinitions([Column::make('created', 'created_at')->dateRange()])
            ->applyDateRanges([
                'created' => ['from' => '01-02-2026', 'to' => '28-02-2026'],
            ])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Beta'], $result->pluck('name')->all());
    }

    public function test_query_builder_uses_definition_date_sources(): void
    {
        $result = DataTable::query(DB::table('records'))
            ->columnDefinitions([Column::make('created', 'created_at')->dateRange()])
            ->applyFilters(['created_from:01-01-2026', 'created_to:31-01-2026'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha'], $result->pluck('name')->all());
    }
}
