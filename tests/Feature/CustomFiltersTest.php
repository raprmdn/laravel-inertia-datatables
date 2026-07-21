<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Raprmdn\DataTables\Facades\DataTable;
use RuntimeException;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Record;
use Tests\Fixtures\Models\Tag;
use Tests\TestCase;

class CustomFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $acme = Organization::query()->create(['name' => 'Acme']);
        $beta = Organization::query()->create(['name' => 'Beta Group']);

        $alpha = Record::query()->create([
            'organization_id' => $acme->id,
            'name' => 'Alpha',
            'status' => 'active',
            'created_at' => '2026-01-10 10:00:00',
            'updated_at' => '2026-01-10 10:00:00',
        ]);
        Record::query()->create([
            'organization_id' => $beta->id,
            'name' => 'Beta',
            'status' => 'inactive',
            'created_at' => '2026-01-20 10:00:00',
            'updated_at' => '2026-01-20 10:00:00',
        ]);
        Record::query()->create([
            'name' => 'Gamma',
            'status' => 'active',
            'created_at' => '2026-02-10 10:00:00',
            'updated_at' => '2026-02-10 10:00:00',
        ]);

        $alpha->tags()->attach(Tag::query()->create(['name' => 'Featured']));
    }

    public function test_rejects_an_empty_or_whitespace_custom_filter_key(): void
    {
        foreach (['', '   '] as $key) {
            try {
                DataTable::query(Record::query())->filterUsing($key, fn () => null);
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Custom filter key must not be empty.', $exception->getMessage());

                continue;
            }

            $this->fail('Expected an InvalidArgumentException.');
        }
    }

    public function test_allowlisted_callback_receives_original_builder_and_ordered_values_once(): void
    {
        $query = Record::query();
        $calls = 0;

        $result = DataTable::query($query)
            ->applyFilters(['custom:second', 'custom:first', 'custom:second'])
            ->allowedFilters(['custom'])
            ->filterUsing(
                'custom',
                function ($receivedQuery, array $values) use ($query, &$calls): void {
                    $calls++;
                    $this->assertSame($query, $receivedQuery);
                    $this->assertSame(['second', 'first', 'second'], $values);
                    $receivedQuery->where('status', 'active');
                }
            )
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(1, $calls);
        $this->assertSame(['Alpha', 'Gamma'], $result->pluck('name')->all());
    }

    public function test_non_allowlisted_custom_filter_does_not_execute(): void
    {
        $executed = false;

        $result = DataTable::query(Record::query())
            ->applyFilters(['custom:value', 'status:active'])
            ->allowedFilters(['status'])
            ->filterUsing('custom', function () use (&$executed): void {
                $executed = true;
            })
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertFalse($executed);
        $this->assertSame(['Alpha', 'Gamma'], $result->pluck('name')->all());
    }

    public function test_custom_filter_takes_precedence_over_builtin_filtering(): void
    {
        $result = DataTable::query(Record::query())
            ->applyFilters(['status:active'])
            ->allowedFilters(['status'])
            ->filterUsing('status', function ($query): void {
                $query->where('status', 'inactive');
            })
            ->type('collection')
            ->make();

        $this->assertSame(['Beta'], $result->pluck('name')->all());
    }

    public function test_different_custom_filters_accumulate(): void
    {
        $acmeId = Organization::query()->where('name', 'Acme')->value('id');

        $result = DataTable::query(Record::query())
            ->applyFilters(['state:active', 'organization:acme'])
            ->allowedFilters(['state', 'organization'])
            ->filterUsing('state', function ($query): void {
                $query->where('status', 'active');
            })
            ->filterUsing('organization', function ($query) use ($acmeId): void {
                $query->where('organization_id', $acmeId);
            })
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha'], $result->pluck('name')->all());
    }

    public function test_duplicate_registration_replaces_the_previous_callback(): void
    {
        $firstCalls = 0;
        $secondCalls = 0;

        $result = DataTable::query(Record::query())
            ->applyFilters(['state:any'])
            ->allowedFilters(['state'])
            ->filterUsing('state', function () use (&$firstCalls): void {
                $firstCalls++;
            })
            ->filterUsing('state', function ($query) use (&$secondCalls): void {
                $secondCalls++;
                $query->where('status', 'inactive');
            })
            ->type('collection')
            ->make();

        $this->assertSame(0, $firstCalls);
        $this->assertSame(1, $secondCalls);
        $this->assertSame(['Beta'], $result->pluck('name')->all());
    }

    public function test_callback_return_value_is_ignored(): void
    {
        $result = DataTable::query(Record::query())
            ->applyFilters(['custom:value'])
            ->allowedFilters(['custom'])
            ->filterUsing('custom', function () {
                return Record::query()->where('name', 'Beta');
            })
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $result->pluck('name')->all());
    }

    public function test_callback_exception_propagates_unchanged(): void
    {
        $expected = new RuntimeException('Custom filter failed.');

        try {
            DataTable::query(Record::query())
                ->applyFilters(['custom:value'])
                ->allowedFilters(['custom'])
                ->filterUsing('custom', function () use ($expected): void {
                    throw $expected;
                })
                ->make();
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception);

            return;
        }

        $this->fail('Expected callback exception to propagate.');
    }

    public function test_malformed_filters_do_not_invoke_callbacks(): void
    {
        $calls = 0;

        DataTable::query(Record::query())
            ->applyFilters(['custom', null, 10, ['custom:value'], ':value'])
            ->allowedFilters(['custom'])
            ->filterUsing('custom', function () use (&$calls): void {
                $calls++;
            })
            ->type('collection')
            ->make();

        $this->assertSame(0, $calls);
    }

    public function test_missing_filter_key_does_not_invoke_callback(): void
    {
        $calls = 0;

        DataTable::query(Record::query())
            ->applyFilters(['status:active'])
            ->allowedFilters(['status', 'custom'])
            ->filterUsing('custom', function () use (&$calls): void {
                $calls++;
            })
            ->type('collection')
            ->make();

        $this->assertSame(0, $calls);
    }

    public function test_custom_filter_state_does_not_leak_between_fresh_builders(): void
    {
        $custom = DataTable::query(Record::query())
            ->applyFilters(['status:active'])
            ->allowedFilters(['status'])
            ->filterUsing('status', function ($query): void {
                $query->where('status', 'inactive');
            })
            ->type('collection')
            ->make();

        $builtin = DataTable::query(Record::query())
            ->applyFilters(['status:active'])
            ->allowedFilters(['status'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Beta'], $custom->pluck('name')->all());
        $this->assertSame(['Alpha', 'Gamma'], $builtin->pluck('name')->all());
    }

    public function test_eloquent_custom_filter_supports_has_and_doesnt_have(): void
    {
        $categorized = $this->categoryState(['category_state:categorized']);
        $uncategorized = $this->categoryState(['category_state:uncategorized']);
        $both = $this->categoryState([
            'category_state:categorized',
            'category_state:uncategorized',
        ]);

        $this->assertSame(['Alpha'], $categorized->pluck('name')->all());
        $this->assertSame(['Beta', 'Gamma'], $uncategorized->pluck('name')->all());
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $both->pluck('name')->all());
    }

    public function test_query_builder_custom_filter_supports_where_exists(): void
    {
        $query = DB::table('records');

        $result = DataTable::query($query)
            ->applyFilters(['tagged:yes'])
            ->allowedFilters(['tagged'])
            ->filterUsing('tagged', function (QueryBuilder $receivedQuery) use ($query): void {
                $this->assertSame($query, $receivedQuery);
                $receivedQuery->whereExists(function (QueryBuilder $subquery): void {
                    $subquery
                        ->selectRaw('1')
                        ->from('record_tag')
                        ->whereColumn('record_tag.record_id', 'records.id');
                });
            })
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha'], $result->pluck('name')->all());
    }

    public function test_custom_filters_work_with_search_dates_sorting_and_pagination(): void
    {
        $this->setRequestQuery([
            'search' => 'a',
            'sort' => 'desc',
            'limit' => 1,
        ]);

        $result = DataTable::query(Record::query())
            ->searchable(['name'])
            ->applyFilters(['included:yes'])
            ->allowedFilters(['included', 'created_at'])
            ->filterUsing('included', function (EloquentBuilder $query): void {
                $query->whereIn('status', ['active', 'inactive']);
            })
            ->applyDateRanges([
                'created_at' => ['from' => '01-01-2026', 'to' => '31-01-2026'],
            ])
            ->applySort('name')
            ->allowedSorts(['name'])
            ->make();

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(2, $result->total());
        $this->assertSame(['Beta'], collect($result->items())->pluck('name')->all());
    }

    private function categoryState(array $filters)
    {
        return DataTable::query(Record::query())
            ->applyFilters($filters)
            ->allowedFilters(['category_state'])
            ->filterUsing('category_state', function (EloquentBuilder $query, array $values): void {
                $categorized = in_array('categorized', $values, true);
                $uncategorized = in_array('uncategorized', $values, true);

                if ($categorized === $uncategorized) {
                    return;
                }

                if ($categorized) {
                    $query->has('tags');

                    return;
                }

                $query->doesntHave('tags');
            })
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();
    }
}
