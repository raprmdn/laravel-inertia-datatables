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

    public function test_malformed_search_value_leaves_query_unfiltered(): void
    {
        $this->setRequestQuery(['search' => ['alpha']]);

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

        $binding = '%' . $value . '%';
        $this->assertContains($binding, $query->getBindings());
        $this->assertStringNotContainsString($value, $query->toSql());
    }

    public function test_search_columns_are_qualified_and_wrapped(): void
    {
        $this->setRequestQuery(['search' => 'alpha']);
        $query = Record::query();

        $this->search(['name'], $query);

        $column = $query->getQuery()->getGrammar()->wrap('records.name');

        $this->assertStringContainsString($column, $query->toSql());
        $this->assertStringNotContainsString('LOWER(', $query->toSql());
    }

    public function test_relation_search_columns_are_qualified_and_wrapped(): void
    {
        $this->setRequestQuery(['search' => 'acme']);
        $query = Record::query();

        $this->search(['organization.name'], $query);

        $column = $query->getQuery()->getGrammar()->wrap('organizations.name');

        $this->assertStringContainsString($column, $query->toSql());
        $this->assertStringNotContainsString('LOWER(', $query->toSql());
    }

    public function test_search_matches_like_wildcards_literally(): void
    {
        Record::query()->create(['name' => 'Rate 50%', 'status' => 'open']);

        foreach (['%' => ['Rate 50%'], 'a_p' => [], '!' => []] as $value => $expected) {
            $this->setRequestQuery(['search' => $value]);

            $this->assertSame($expected, $this->search(['name'])->pluck('name')->all(), "Search [{$value}]");
        }
    }

    public function test_columns_on_one_relation_path_share_one_subquery(): void
    {
        $alpha = Record::query()->where('name', 'Alpha')->first();
        Record::query()->where('name', 'Beta')->update(['manager_id' => $alpha->id]);
        $this->setRequestQuery(['search' => 'closed']);
        $query = Record::query();

        $result = $this->search(['directReports.name', 'directReports.status'], $query);

        $this->assertSame(['Alpha'], $result->pluck('name')->all());
        $this->assertSame(1, substr_count(strtolower($query->toSql()), 'exists'));
    }

    public function test_belongs_to_search_matches_through_the_foreign_key_and_keeps_relation_constraints(): void
    {
        $hidden = Organization::query()->create(['name' => 'Hidden Org']);
        Record::query()->create(['organization_id' => $hidden->id, 'name' => 'Concealed', 'status' => 'open']);
        $query = Record::query();

        $this->setRequestQuery(['search' => 'acme']);
        $result = $this->search(['visibleOrganization.name'], $query);

        $this->assertSame(['Alpha', 'Open Beta'], $result->pluck('name')->all());
        $this->assertStringContainsString(' in (select ', strtolower($query->toSql()));
        $this->assertStringNotContainsString('exists', strtolower($query->toSql()));

        $this->setRequestQuery(['search' => 'hidden']);
        $this->assertSame([], $this->search(['visibleOrganization.name'])->pluck('name')->all());
        $this->assertSame(['Concealed'], $this->search(['organization.name'])->pluck('name')->all());
    }

    public function test_belongs_to_search_keeps_related_global_scopes(): void
    {
        $hidden = Country::query()->create(['name' => 'Hidden Country']);
        Organization::query()->create(['country_id' => $hidden->id, 'name' => 'Gamma']);
        $search = fn (string $relation) => DataTable::query(Organization::query())
            ->searchable(["{$relation}.name"])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make()
            ->pluck('name')
            ->all();

        $this->setRequestQuery(['search' => 'hidden']);

        $this->assertSame(['Gamma'], $search('country'));
        $this->assertSame([], $search('scopedCountry'));
    }

    public function test_belongs_to_search_supports_a_self_referencing_relation(): void
    {
        $alpha = Record::query()->where('name', 'Alpha')->first();
        Record::query()->where('name', 'Beta')->update(['manager_id' => $alpha->id]);
        $this->setRequestQuery(['search' => 'alpha']);

        $this->assertSame(['Beta'], $this->search(['manager.name'])->pluck('name')->all());
    }

    public function test_search_remains_unambiguous_with_relation_sort_joins(): void
    {
        $this->setRequestQuery(['search' => 'alpha', 'sort' => 'asc']);
        $query = Record::query()
            ->select(['records.id', 'records.name'])
            ->whereNotNull('organization_id');

        $result = DataTable::query($query)
            ->searchable(['name'])
            ->applySort('organization.country.name')
            ->allowedSorts(['organization.country.name'])
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha'], $result->pluck('name')->all());
        $this->assertSame(['records.id', 'records.name'], $query->getQuery()->columns);
    }

    public function test_eloquent_from_alias_is_used_when_qualifying_search_columns(): void
    {
        $this->setRequestQuery(['search' => 'alpha']);
        $query = Record::query()
            ->from('records as base_records')
            ->select(['base_records.id', 'base_records.name']);

        $result = DataTable::query($query)
            ->searchable(['name'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha'], $result->pluck('name')->all());
        $this->assertSame(['base_records.id', 'base_records.name'], $query->getQuery()->columns);
    }

    public function test_eloquent_subquery_alias_is_used_when_qualifying_search_columns(): void
    {
        $this->setRequestQuery(['search' => 'alpha']);
        $query = Record::query()
            ->fromSub(Record::query(), 'base_records')
            ->select(['base_records.id', 'base_records.name']);

        $result = DataTable::query($query)
            ->searchable(['name'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha'], $result->pluck('name')->all());
        $this->assertSame(['base_records.id', 'base_records.name'], $query->getQuery()->columns);
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
