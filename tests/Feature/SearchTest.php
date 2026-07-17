<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Raprmdn\DataTables\Facades\DataTable;
use Tests\Fixtures\Models\Country;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Record;
use Tests\TestCase;

class SearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $indonesia = Country::query()->create(['name' => 'Indonesia']);
        $canada = Country::query()->create(['name' => 'Canada']);
        $acme = Organization::query()->create(['country_id' => $indonesia->id, 'name' => 'Acme']);
        $beta = Organization::query()->create(['country_id' => $canada->id, 'name' => 'Beta Group']);

        Record::query()->create(['organization_id' => $acme->id, 'name' => 'Alpha', 'status' => 'open']);
        Record::query()->create(['organization_id' => $beta->id, 'name' => 'Beta', 'status' => 'closed']);
        Record::query()->create(['organization_id' => $acme->id, 'name' => 'Open Beta', 'status' => 'open']);
    }

    public function test_searches_a_normal_column(): void
    {
        $this->setRequestQuery(['search' => 'alpha']);

        $this->assertSame(['Alpha'], $this->search(['name'])->pluck('name')->all());
    }

    public function test_searches_multiple_columns_with_or_grouping(): void
    {
        $this->setRequestQuery(['search' => 'closed']);

        $this->assertSame(['Beta'], $this->search(['name', 'status'])->pluck('name')->all());
    }

    public function test_searches_a_relation_column(): void
    {
        $this->setRequestQuery(['search' => 'acme']);

        $this->assertSame(
            ['Alpha', 'Open Beta'],
            $this->search(['organization.name'])->pluck('name')->all()
        );
    }

    public function test_searches_a_nested_relation_column(): void
    {
        $this->setRequestQuery(['search' => 'indonesia']);

        $this->assertSame(
            ['Alpha', 'Open Beta'],
            $this->search(['organization.country.name'])->pluck('name')->all()
        );
    }

    public function test_empty_search_leaves_query_unfiltered(): void
    {
        $this->setRequestQuery(['search' => '']);

        $this->assertSame(
            ['Alpha', 'Beta', 'Open Beta'],
            $this->search(['name'])->pluck('name')->all()
        );
    }

    public function test_search_predicates_remain_grouped_with_existing_constraints(): void
    {
        $this->setRequestQuery(['search' => 'beta']);
        $query = Record::query()->where('status', 'open');

        $this->assertSame(
            ['Open Beta'],
            $this->search(['name', 'status'], $query)->pluck('name')->all()
        );
    }

    public function test_uses_the_configured_search_query_parameter(): void
    {
        config()->set('inertia-datatables.query_params.search', 'query');
        $this->setRequestQuery(['search' => 'beta', 'query' => 'alpha']);

        $this->assertSame(['Alpha'], $this->search(['name'])->pluck('name')->all());
    }

    public function test_searches_query_builder_columns(): void
    {
        $this->setRequestQuery(['search' => 'alpha']);

        $result = DataTable::query(DB::table('records'))
            ->searchable(['name'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha'], $result->pluck('name')->all());
    }

    public function test_search_value_remains_query_bound(): void
    {
        $value = "Alpha' OR 1=1 --";
        $this->setRequestQuery(['search' => $value]);
        $query = Record::query();

        $this->search(['name'], $query);

        $binding = '%' . strtolower($value) . '%';
        $this->assertContains($binding, $query->getBindings());
        $this->assertStringNotContainsString($value, $query->toSql());
    }

    private function search(array $columns, ?Builder $query = null): Collection
    {
        return DataTable::query($query ?? Record::query())
            ->searchable($columns)
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();
    }
}
