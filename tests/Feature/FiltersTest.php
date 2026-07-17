<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Raprmdn\DataTables\Facades\DataTable;
use Tests\Fixtures\Models\Country;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Record;
use Tests\TestCase;

class FiltersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $indonesia = Country::query()->create(['name' => 'Indonesia']);
        $canada = Country::query()->create(['name' => 'Canada']);
        $acme = Organization::query()->create(['country_id' => $indonesia->id, 'name' => 'Acme']);
        $beta = Organization::query()->create(['country_id' => $canada->id, 'name' => 'Beta Group']);

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

    public function test_applies_a_single_filter(): void
    {
        $this->assertSame(['Alpha'], $this->filter(['status:active'], ['status'])->pluck('name')->all());
    }

    public function test_multiple_values_for_one_column_are_or_grouped(): void
    {
        $result = $this->filter(['status:active', 'status:inactive'], ['status']);

        $this->assertSame(['Alpha', 'Beta'], $result->pluck('name')->all());
    }

    public function test_multiple_filter_columns_are_and_grouped(): void
    {
        $result = $this->filter(['status:active', 'name:Alpha'], ['status', 'name']);

        $this->assertSame(['Alpha'], $result->pluck('name')->all());
    }

    public function test_supports_null_and_not_null_filters(): void
    {
        $null = $this->filter(['status:NULL'], ['status']);
        $notNull = $this->filter(['status:NOT NULL'], ['status']);

        $this->assertSame(['Gamma'], $null->pluck('name')->all());
        $this->assertSame(['Alpha', 'Beta'], $notNull->pluck('name')->all());
    }

    public function test_filters_a_relation_column(): void
    {
        $result = $this->filter(['organization.name:Beta Group'], ['organization.name']);

        $this->assertSame(['Beta'], $result->pluck('name')->all());
    }

    public function test_filters_a_nested_relation_column(): void
    {
        $result = $this->filter(
            ['organization.country.name:Indonesia'],
            ['organization.country.name']
        );

        $this->assertSame(['Alpha', 'Gamma'], $result->pluck('name')->all());
    }

    public function test_filters_a_json_scalar_path(): void
    {
        $result = $this->filter(['metadata->channel:api'], ['metadata->channel']);

        $this->assertSame(['Beta'], $result->pluck('name')->all());
    }

    public function test_filters_json_array_containment_for_configured_paths(): void
    {
        config()->set('inertia-datatables.json_columns', ['metadata->tags']);

        $result = $this->filter(['metadata->tags:red'], ['metadata->tags']);

        $this->assertSame(['Alpha', 'Gamma'], $result->pluck('name')->all());
    }

    public function test_rejects_unapproved_filter_columns(): void
    {
        $result = $this->filter(['name:Beta'], ['status']);

        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $result->pluck('name')->all());
    }

    public function test_user_filter_values_remain_query_bound(): void
    {
        $value = "Alpha' OR 1=1 --";
        $query = Record::query();

        DataTable::query($query)
            ->applyFilters(["name:{$value}"])
            ->allowedFilters(['name'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertContains($value, $query->getBindings());
        $this->assertStringNotContainsString($value, $query->toSql());
    }

    public function test_filters_query_builder_columns(): void
    {
        $result = DataTable::query(DB::table('records'))
            ->applyFilters(['status:inactive'])
            ->allowedFilters(['status'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Beta'], $result->pluck('name')->all());
    }

    private function filter(array $filters, array $allowed): Collection
    {
        return DataTable::query(Record::query())
            ->applyFilters($filters)
            ->allowedFilters($allowed)
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();
    }
}
