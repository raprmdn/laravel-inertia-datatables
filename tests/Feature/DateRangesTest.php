<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Raprmdn\DataTables\Facades\DataTable;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Record;
use Tests\TestCase;

class DateRangesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Record::query()->create([
            'name' => 'Early',
            'created_at' => '2026-01-01 10:00:00',
            'updated_at' => '2026-01-01 10:00:00',
        ]);
        Record::query()->create([
            'name' => 'Middle',
            'created_at' => '2026-01-15 12:00:00',
            'updated_at' => '2026-01-15 12:00:00',
        ]);
        Record::query()->create([
            'name' => 'Late',
            'created_at' => '2026-02-01 10:00:00',
            'updated_at' => '2026-02-01 10:00:00',
        ]);
    }

    public function test_filters_from_date_only(): void
    {
        $result = $this->dates(['created_at' => ['from' => '15-01-2026']]);

        $this->assertSame(['Middle', 'Late'], $result->pluck('name')->all());
    }

    public function test_filters_to_date_only(): void
    {
        $result = $this->dates(['created_at' => ['to' => '15-01-2026']]);

        $this->assertSame(['Early', 'Middle'], $result->pluck('name')->all());
    }

    public function test_filters_between_both_date_boundaries(): void
    {
        $result = $this->dates([
            'created_at' => ['from' => '02-01-2026', 'to' => '31-01-2026'],
        ]);

        $this->assertSame(['Middle'], $result->pluck('name')->all());
    }

    public function test_filters_a_relation_date_column(): void
    {
        $january = Organization::query()->create([
            'name' => 'January Org',
            'created_at' => '2026-01-05 10:00:00',
            'updated_at' => '2026-01-05 10:00:00',
        ]);
        $february = Organization::query()->create([
            'name' => 'February Org',
            'created_at' => '2026-02-05 10:00:00',
            'updated_at' => '2026-02-05 10:00:00',
        ]);
        Record::query()->create(['organization_id' => $january->id, 'name' => 'January Related']);
        Record::query()->create(['organization_id' => $february->id, 'name' => 'February Related']);

        $result = $this->dates(
            ['organization.created_at' => ['from' => '01-02-2026']],
            ['organization.created_at']
        );

        $this->assertSame(['February Related'], $result->pluck('name')->all());
    }

    public function test_uses_custom_date_format(): void
    {
        config()->set('inertia-datatables.date_format', 'Y/m/d');

        $result = $this->dates(['created_at' => ['from' => '2026/01/15']]);

        $this->assertSame(['Middle', 'Late'], $result->pluck('name')->all());
    }

    public function test_invalid_date_format_throws_an_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date format for value [not-a-date].');

        $this->dates(['created_at' => ['from' => 'not-a-date']]);
    }

    public function test_non_string_date_value_throws_an_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date value. Expected format: d-m-Y.');

        $this->dates(['created_at' => ['from' => ['2026-01-15']]]);
    }

    public function test_date_values_remain_query_bound(): void
    {
        $query = Record::query();

        DataTable::query($query)
            ->applyDateRanges(['created_at' => ['from' => '15-01-2026']])
            ->allowedFilters(['created_at'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $this->assertContains('2026-01-15 00:00:00', $query->getBindings());
        $this->assertStringNotContainsString('2026-01-15 00:00:00', $query->toSql());
    }

    public function test_date_range_sql_is_qualified_and_index_friendly(): void
    {
        $query = Record::query();

        DataTable::query($query)
            ->applyDateRanges([
                'created_at' => ['from' => '01-01-2026', 'to' => '31-01-2026'],
            ])
            ->allowedFilters(['created_at'])
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();

        $sql = strtolower($query->toSql());
        $column = strtolower($query->getQuery()->getGrammar()->wrap('records.created_at'));

        $this->assertStringContainsString("{$column} >= ?", $sql);
        $this->assertStringContainsString("{$column} < ?", $sql);
        $this->assertStringNotContainsString('date(', $sql);
        $this->assertStringNotContainsString('cast(', $sql);
    }

    public function test_query_builder_date_ranges_preserve_caller_columns(): void
    {
        $query = DB::table('records')->select(['records.id', 'records.name']);

        $result = DataTable::query($query)
            ->applyDateRanges(['records.created_at' => ['from' => '15-01-2026']])
            ->allowedFilters(['records.created_at'])
            ->orderBy('records.id', 'asc')
            ->type('collection')
            ->make();

        $this->assertSame(['Middle', 'Late'], $result->pluck('name')->all());
        $this->assertSame(['records.id', 'records.name'], $query->columns);
    }

    public function test_valid_leap_date_is_accepted(): void
    {
        Record::query()->create([
            'name' => 'Leap Day',
            'created_at' => '2024-02-29 12:00:00',
            'updated_at' => '2024-02-29 12:00:00',
        ]);

        $result = $this->dates([
            'created_at' => ['from' => '29-02-2024', 'to' => '29-02-2024'],
        ]);

        $this->assertSame(['Leap Day'], $result->pluck('name')->all());
    }

    public function test_invalid_overflow_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->dates(['created_at' => ['from' => '31-02-2026']]);
    }

    public function test_to_date_includes_fractional_seconds_at_end_of_day(): void
    {
        DB::table('records')->insert([
            'name' => 'Fractional',
            'created_at' => '2026-01-31 23:59:59.500000',
            'updated_at' => '2026-01-31 23:59:59.500000',
        ]);

        $result = $this->dates([
            'created_at' => ['from' => '31-01-2026', 'to' => '31-01-2026'],
        ]);

        $this->assertSame(['Fractional'], $result->pluck('name')->all());
    }

    private function dates(array $ranges, array $allowed = ['created_at']): Collection
    {
        return DataTable::query(Record::query())
            ->applyDateRanges($ranges)
            ->allowedFilters($allowed)
            ->orderBy('id', 'asc')
            ->type('collection')
            ->make();
    }
}
