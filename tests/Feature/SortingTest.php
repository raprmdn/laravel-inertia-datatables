<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Raprmdn\DataTables\DataTableBuilder;
use Raprmdn\DataTables\Facades\DataTable;
use Tests\Fixtures\Models\Comment;
use Tests\Fixtures\Models\Country;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Profile;
use Tests\Fixtures\Models\Record;
use Tests\TestCase;

class SortingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $alphaCountry = Country::query()->create(['name' => 'Alpha Country']);
        $zuluCountry = Country::query()->create(['name' => 'Zulu Country']);
        $zuluOrganization = Organization::query()->create([
            'country_id' => $alphaCountry->id,
            'name' => 'Zulu Org',
        ]);
        $alphaOrganization = Organization::query()->create([
            'country_id' => $zuluCountry->id,
            'name' => 'Alpha Org',
        ]);

        $charlie = Record::query()->create([
            'organization_id' => $zuluOrganization->id,
            'name' => 'Charlie',
            'status' => 'active',
            'created_at' => '2026-01-01 10:00:00',
            'updated_at' => '2026-01-01 10:00:00',
        ]);
        $alpha = Record::query()->create([
            'organization_id' => $alphaOrganization->id,
            'name' => 'Alpha',
            'status' => 'inactive',
            'created_at' => '2026-01-03 10:00:00',
            'updated_at' => '2026-01-03 10:00:00',
        ]);
        $bravo = Record::query()->create([
            'name' => 'Bravo',
            'status' => 'active',
            'created_at' => '2026-01-02 10:00:00',
            'updated_at' => '2026-01-02 10:00:00',
        ]);

        Profile::query()->create(['record_id' => $charlie->id, 'label' => 'Beta Profile']);
        Profile::query()->create(['record_id' => $alpha->id, 'label' => 'Zulu Profile']);
        Profile::query()->create(['record_id' => $bravo->id, 'label' => 'Alpha Profile']);
    }

    public function test_sorts_a_normal_column(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);

        $result = $this->sort(Record::query(), 'name', ['name']);

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $result->pluck('name')->all());
    }

    public function test_sorts_a_with_count_alias_without_qualifying_it(): void
    {
        $charlie = Record::query()->where('name', 'Charlie')->firstOrFail();
        $alpha = Record::query()->where('name', 'Alpha')->firstOrFail();
        Comment::query()->create(['record_id' => $charlie->id, 'body' => 'First']);
        Comment::query()->create(['record_id' => $charlie->id, 'body' => 'Second']);
        Comment::query()->create(['record_id' => $alpha->id, 'body' => 'Only']);
        $this->setRequestQuery(['sort' => 'asc']);
        $query = Record::query();

        $result = DataTable::query($query)
            ->withCount('comments')
            ->applySort('comments_count')
            ->allowedSorts(['comments_count'])
            ->type('collection')
            ->make();

        $this->assertSame(['Bravo', 'Alpha', 'Charlie'], $result->pluck('name')->all());
        $this->assertSame('comments_count', $query->getQuery()->orders[0]['column']);
    }

    public function test_uses_default_sort_when_no_sort_is_applied(): void
    {
        $result = DataTable::query(Record::query())
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $result->pluck('name')->all());
    }

    public function test_missing_requested_sort_uses_custom_default_sort(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);

        $result = DataTable::query(Record::query())
            ->applySort(null)
            ->allowedSorts(['name'])
            ->orderBy('name', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $result->pluck('name')->all());
    }

    public function test_invalid_direction_falls_back_to_default_direction(): void
    {
        $this->setRequestQuery(['sort' => 'sideways']);

        $result = DataTable::query(Record::query())
            ->applySort('name')
            ->allowedSorts(['name'])
            ->orderBy('name', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $result->pluck('name')->all());
    }

    public function test_malformed_direction_falls_back_to_default_direction(): void
    {
        $this->setRequestQuery(['sort' => ['asc']]);

        $result = DataTable::query(Record::query())
            ->applySort('name')
            ->allowedSorts(['name'])
            ->orderBy('name', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $result->pluck('name')->all());
    }

    public function test_normal_sort_is_qualified_and_preserves_explicit_columns(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);
        $query = Record::query()
            ->select(['records.id', 'records.name'])
            ->leftJoin('organizations', 'organizations.id', '=', 'records.organization_id');

        $result = $this->sort($query, 'name', ['name']);

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $result->pluck('name')->all());
        $this->assertSame(['records.id', 'records.name'], $query->getQuery()->columns);
    }

    public function test_subquery_alias_is_not_double_prefixed_when_sorting(): void
    {
        $connection = DB::connection();
        $connection->setTablePrefix('pre_');

        try {
            $query = Record::query()->fromSub(Record::query(), 'base_records');
            $builder = new class extends DataTableBuilder
            {
                public function applySorting(): void
                {
                    $this->sort();
                }
            };

            $builder->query($query)->orderBy('id', 'asc');
            $builder->applySorting();

            $column = $query->getQuery()->getGrammar()->wrap('base_records.id');

            $this->assertStringContainsString("order by {$column} asc", $query->toSql());
            $this->assertStringNotContainsString('pre_pre_base_records', $query->toSql());
        } finally {
            $connection->setTablePrefix('');
        }
    }

    public function test_rejected_unapproved_sort_uses_default_column(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);

        $result = DataTable::query(Record::query())
            ->applySort('name')
            ->allowedSorts(['status'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Charlie', 'Alpha', 'Bravo'], $result->pluck('name')->all());
    }

    public function test_sorts_a_belongs_to_relation(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);
        $query = Record::query()->whereNotNull('organization_id');

        $result = $this->sort($query, 'organization.name', ['organization.name']);

        $this->assertSame(['Alpha', 'Charlie'], $result->pluck('name')->all());
    }

    public function test_relation_sort_uses_an_aliased_eloquent_from(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);
        $query = Record::query()
            ->from('records as base_records')
            ->select(['base_records.id', 'base_records.name'])
            ->whereNotNull('base_records.organization_id');

        $result = $this->sort($query, 'organization.name', ['organization.name']);

        $this->assertSame(['Alpha', 'Charlie'], $result->pluck('name')->all());
        $this->assertSame(['base_records.id', 'base_records.name'], $query->getQuery()->columns);
    }

    public function test_sorts_a_has_one_relation(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);

        $result = $this->sort(Record::query(), 'profile.label', ['profile.label']);

        $this->assertSame(['Bravo', 'Charlie', 'Alpha'], $result->pluck('name')->all());
    }

    public function test_sorts_nested_supported_relations(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);
        $query = Record::query()->whereNotNull('organization_id');

        $result = $this->sort(
            $query,
            'organization.country.name',
            ['organization.country.name']
        );

        $this->assertSame(['Charlie', 'Alpha'], $result->pluck('name')->all());
    }

    public function test_relation_sort_preserves_relation_constraints(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);
        $query = Record::query()->whereNotNull('organization_id');

        $this->sort($query, 'visibleOrganization.name', ['visibleOrganization.name']);

        $this->assertContains('Hidden Org', $query->getBindings());
    }

    public function test_nested_relation_sort_preserves_related_global_scopes(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);
        $query = Record::query()->whereNotNull('organization_id');

        $this->sort($query, 'organization.scopedCountry.name', ['organization.scopedCountry.name']);

        $this->assertContains('Hidden Country', $query->getBindings());
    }

    public function test_nested_relation_sort_does_not_add_outer_joins(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);
        $query = Record::query()->whereNotNull('organization_id');

        $this->sort($query, 'organization.country.name', ['organization.country.name']);

        $this->assertEmpty($query->getQuery()->joins);
    }

    public function test_nested_relation_sort_keeps_joins_out_of_pagination_count_query(): void
    {
        $this->setRequestQuery(['sort' => 'asc', 'limit' => 1]);
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $result = DataTable::query(Record::query()->whereNotNull('organization_id'))
            ->applySort('organization.country.name')
            ->allowedSorts(['organization.country.name'])
            ->make();

        $countQuery = collect($queries)->first(
            fn (string $sql): bool => str_contains(strtolower($sql), 'count(')
        );

        $this->assertSame(2, $result->total());
        $this->assertNotNull($countQuery);
        $this->assertStringNotContainsString(' join ', strtolower($countQuery));
    }

    public function test_has_many_sorting_throws_an_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported relation type for sorting: comments.');

        $this->sort(Record::query(), 'comments.body', ['comments.body']);
    }

    public function test_belongs_to_many_sorting_throws_an_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported relation type for sorting: tags.');

        $this->sort(Record::query(), 'tags.name', ['tags.name']);
    }

    public function test_existing_generated_relation_joins_are_not_duplicated(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);
        $query = Record::query()
            ->leftJoin(
                'organizations as organizations_organization_0',
                'organizations_organization_0.id',
                '=',
                'records.organization_id'
            )
            ->leftJoin(
                'countries as countries_country_1',
                'countries_country_1.id',
                '=',
                'organizations_organization_0.country_id'
            );

        $this->sort($query, 'organization.country.name', ['organization.country.name']);

        $this->assertCount(2, $query->getQuery()->joins);
    }

    public function test_nested_sort_preserves_explicit_base_model_columns(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);
        $query = Record::query()
            ->select(['records.id', 'records.name'])
            ->whereNotNull('organization_id');

        $this->sort($query, 'organization.country.name', ['organization.country.name']);

        $this->assertSame(['records.id', 'records.name'], $query->getQuery()->columns);
    }

    public function test_nested_sort_does_not_replace_the_default_select(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);
        $query = Record::query()->whereNotNull('organization_id');

        $this->sort($query, 'organization.country.name', ['organization.country.name']);

        $this->assertNull($query->getQuery()->columns);
    }

    public function test_query_builder_dotted_sort_uses_caller_provided_table_alias(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);
        $query = DB::table('records')
            ->select('records.*')
            ->join('organizations as organization', 'organization.id', '=', 'records.organization_id');

        $result = DataTable::query($query)
            ->applySort('organization.name')
            ->allowedSorts(['organization.name'])
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha', 'Charlie'], $result->pluck('name')->all());
        $this->assertSame([
            'column' => 'organization.name',
            'direction' => 'asc',
        ], $query->orders[0]);
    }

    public function test_query_builder_nested_dotted_sort_uses_underscore_alias(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);
        $query = DB::table('records')
            ->select('records.*')
            ->join('organizations as organization', 'organization.id', '=', 'records.organization_id')
            ->join(
                'countries as organization_country',
                'organization_country.id',
                '=',
                'organization.country_id'
            );

        $result = DataTable::query($query)
            ->applySort('organization.country.name')
            ->allowedSorts(['organization.country.name'])
            ->type('collection')
            ->make();

        $this->assertSame(['Charlie', 'Alpha'], $result->pluck('name')->all());
        $this->assertSame([
            'column' => 'organization_country.name',
            'direction' => 'asc',
        ], $query->orders[0]);
    }

    public function test_self_referencing_belongs_to_sort_is_correlated(): void
    {
        $alphaManager = Record::query()->create(['name' => 'Alpha Manager']);
        $zuluManager = Record::query()->create(['name' => 'Zulu Manager']);
        Record::query()->create(['manager_id' => $zuluManager->id, 'name' => 'Child Zulu']);
        Record::query()->create(['manager_id' => $alphaManager->id, 'name' => 'Child Alpha']);
        $this->setRequestQuery(['sort' => 'asc']);
        $ascendingQuery = Record::query()->where('name', 'like', 'Child%');

        $ascending = $this->sort(
            $ascendingQuery,
            'manager.name',
            ['manager.name']
        );

        $this->setRequestQuery(['sort' => 'desc']);
        $descending = $this->sort(
            Record::query()->where('name', 'like', 'Child%'),
            'manager.name',
            ['manager.name']
        );

        $this->assertSame(['Child Alpha', 'Child Zulu'], $ascending->pluck('name')->all());
        $this->assertSame(['Child Zulu', 'Child Alpha'], $descending->pluck('name')->all());
        $this->assertStringContainsString('records_manager_relation_sort', $ascendingQuery->toSql());
    }

    public function test_self_referencing_has_one_sort_is_correlated(): void
    {
        $alphaManager = Record::query()->create(['name' => 'Manager Alpha']);
        $zuluManager = Record::query()->create(['name' => 'Manager Zulu']);
        Record::query()->create(['manager_id' => $zuluManager->id, 'name' => 'Zulu Report']);
        Record::query()->create(['manager_id' => $alphaManager->id, 'name' => 'Alpha Report']);
        $this->setRequestQuery(['sort' => 'asc']);
        $ascendingQuery = Record::query()->where('name', 'like', 'Manager%');

        $ascending = $this->sort(
            $ascendingQuery,
            'firstDirectReport.name',
            ['firstDirectReport.name']
        );

        $this->setRequestQuery(['sort' => 'desc']);
        $descending = $this->sort(
            Record::query()->where('name', 'like', 'Manager%'),
            'firstDirectReport.name',
            ['firstDirectReport.name']
        );

        $this->assertSame(['Manager Alpha', 'Manager Zulu'], $ascending->pluck('name')->all());
        $this->assertSame(['Manager Zulu', 'Manager Alpha'], $descending->pluck('name')->all());
        $this->assertStringContainsString(
            'records_firstDirectReport_relation_sort',
            $ascendingQuery->toSql()
        );
    }

    private function sort($query, ?string $column, array $allowed)
    {
        return DataTable::query($query)
            ->applySort($column)
            ->allowedSorts($allowed)
            ->type('collection')
            ->make();
    }
}
