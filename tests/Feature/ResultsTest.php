<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Raprmdn\DataTables\DataTableBuilder;
use Raprmdn\DataTables\Facades\DataTable;
use stdClass;
use Tests\Fixtures\Models\Record;
use Tests\TestCase;

class ResultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (range(1, 15) as $number) {
            Record::query()->create([
                'name' => sprintf('Record %02d', $number),
                'created_at' => sprintf('2026-01-%02d 10:00:00', $number),
                'updated_at' => sprintf('2026-01-%02d 10:00:00', $number),
            ]);
        }
    }

    public function test_uses_default_pagination_size(): void
    {
        $result = DataTable::query(Record::query())->make();

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame(10, $result->perPage());
        $this->assertCount(10, $result->items());
        $this->assertSame(15, $result->total());
    }

    public function test_uses_requested_pagination_limit(): void
    {
        $this->setRequestQuery(['limit' => 4]);

        $result = DataTable::query(Record::query())->make();

        $this->assertSame(4, $result->perPage());
        $this->assertCount(4, $result->items());
    }

    public function test_caps_requested_limit_at_configured_maximum(): void
    {
        config()->set('inertia-datatables.pagination.max_per_page', 3);
        $this->setRequestQuery(['limit' => 100]);

        $result = DataTable::query(Record::query())->make();

        $this->assertSame(3, $result->perPage());
        $this->assertCount(3, $result->items());
    }

    public function test_appends_query_parameters_to_paginator_links(): void
    {
        $this->setRequestQuery(['search' => 'alpha', 'limit' => 4]);

        $url = DataTable::query(Record::query())->make()->url(2);

        $this->assertStringContainsString('search=alpha', $url);
        $this->assertStringContainsString('limit=4', $url);
        $this->assertStringContainsString('page=2', $url);
    }

    public function test_applies_configured_pagination_link_window(): void
    {
        config()->set('inertia-datatables.pagination.on_each_side', 2);

        $result = DataTable::query(Record::query())->make();

        $this->assertSame(2, $result->onEachSide);
    }

    public function test_collection_output_is_unpaginated(): void
    {
        $this->setRequestQuery(['limit' => 2]);

        $result = DataTable::query(Record::query())
            ->type('collection')
            ->make();

        $this->assertInstanceOf(EloquentCollection::class, $result);
        $this->assertCount(15, $result);
    }

    public function test_eloquent_builder_returns_models(): void
    {
        $result = DataTable::query(Record::query())
            ->type('collection')
            ->make();

        $this->assertInstanceOf(EloquentCollection::class, $result);
        $this->assertInstanceOf(Record::class, $result->first());
    }

    public function test_query_builder_returns_plain_objects(): void
    {
        $result = DataTable::query(DB::table('records'))
            ->type('collection')
            ->make();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertInstanceOf(stdClass::class, $result->first());
    }

    public function test_invalid_datatable_type_throws_an_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DataTable type must be pagination or collection.');

        DataTable::query(Record::query())
            ->type('invalid')
            ->make();
    }

    public function test_missing_query_throws_an_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Query must be set before calling make().');

        (new DataTableBuilder())->make();
    }
}
