<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Raprmdn\DataTables\Column;
use Raprmdn\DataTables\Facades\DataTable;
use RuntimeException;
use Tests\Fixtures\Models\Comment;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Record;
use Tests\TestCase;

class ColumnSortingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $zulu = Organization::query()->create(['name' => 'Zulu Org']);
        $alpha = Organization::query()->create(['name' => 'Alpha Org']);
        $charlie = Record::query()->create(['organization_id' => $zulu->id, 'name' => 'Charlie']);
        $first = Record::query()->create(['organization_id' => $alpha->id, 'name' => 'Alpha']);
        Record::query()->create(['name' => 'Bravo']);
        Comment::query()->create(['record_id' => $charlie->id, 'body' => 'One']);
        Comment::query()->create(['record_id' => $charlie->id, 'body' => 'Two']);
        Comment::query()->create(['record_id' => $first->id, 'body' => 'One']);
    }

    public function test_public_sort_key_maps_to_direct_source(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);

        $result = DataTable::query(Record::query())
            ->columnDefinitions([Column::make('label', 'name')->sortable()])
            ->applySort('label')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $result->pluck('name')->all());
    }

    public function test_public_sort_key_maps_to_relation_source(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);

        $result = DataTable::query(Record::query()->whereNotNull('organization_id'))
            ->columnDefinitions([Column::make('organization', 'organization.name')->sortable()])
            ->applySort('organization')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha', 'Charlie'], $result->pluck('name')->all());
    }

    public function test_definition_sort_supports_with_count_aliases(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);

        $result = DataTable::query(Record::query())
            ->withCount('comments')
            ->columnDefinitions([Column::make('comments', 'comments_count')->sortable()])
            ->applySort('comments')
            ->type('collection')
            ->make();

        $this->assertSame(['Bravo', 'Alpha', 'Charlie'], $result->pluck('name')->all());
    }

    public function test_sort_callback_receives_original_builder_and_normalized_direction_once(): void
    {
        $this->setRequestQuery(['sort' => 'DESC']);
        $query = Record::query();
        $calls = 0;

        $result = DataTable::query($query)
            ->columnDefinitions([
                Column::make('score')
                    ->sortable()
                    ->sortUsing(function ($receivedQuery, string $direction) use ($query, &$calls) {
                        $calls++;
                        $this->assertSame($query, $receivedQuery);
                        $this->assertSame('desc', $direction);
                        $receivedQuery->orderBy('name', $direction);

                        return Record::query()->orderBy('id');
                    }),
            ])
            ->applySort('score')
            ->type('collection')
            ->make();

        $this->assertSame(1, $calls);
        $this->assertSame(['Charlie', 'Bravo', 'Alpha'], $result->pluck('name')->all());
    }

    public function test_sort_callback_does_not_run_without_sortable_capability(): void
    {
        $called = false;

        $result = DataTable::query(Record::query())
            ->columnDefinitions([
                Column::make('score')->sortUsing(function () use (&$called): void {
                    $called = true;
                }),
            ])
            ->applySort('score')
            ->orderBy('name', 'asc')
            ->type('collection')
            ->make();

        $this->assertFalse($called);
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $result->pluck('name')->all());
    }

    public function test_sort_callback_exception_propagates(): void
    {
        $expected = new RuntimeException('Sort failed.');

        try {
            DataTable::query(Record::query())
                ->columnDefinitions([
                    Column::make('score')->sortable()->sortUsing(function () use ($expected): void {
                        throw $expected;
                    }),
                ])
                ->applySort('score')
                ->make();
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception);

            return;
        }

        $this->fail('Expected callback exception to propagate.');
    }

    public function test_unsupported_automatic_relation_sort_still_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported relation type for sorting: comments.');

        DataTable::query(Record::query())
            ->columnDefinitions([Column::make('latest_comment', 'comments.body')->sortable()])
            ->applySort('latest_comment')
            ->type('collection')
            ->make();
    }

    public function test_query_builder_dotted_source_keeps_current_alias_behavior(): void
    {
        $this->setRequestQuery(['sort' => 'asc']);
        $query = DB::table('records')
            ->select('records.*')
            ->leftJoin('organizations as organization', 'organization.id', '=', 'records.organization_id')
            ->whereNotNull('records.organization_id');

        $result = DataTable::query($query)
            ->columnDefinitions([Column::make('organization', 'organization.name')->sortable()])
            ->applySort('organization')
            ->type('collection')
            ->make();

        $this->assertSame(['Alpha', 'Charlie'], $result->pluck('name')->all());
    }
}
