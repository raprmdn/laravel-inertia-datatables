<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Raprmdn\DataTables\Facades\DataTable;
use Tests\Fixtures\Models\Comment;
use Tests\Fixtures\Models\Country;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Profile;
use Tests\Fixtures\Models\Record;
use Tests\Fixtures\Models\Tag;
use Tests\TestCase;

class RelationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::query()->create(['name' => 'Indonesia']);
        $organization = Organization::query()->create([
            'country_id' => $country->id,
            'name' => 'Acme',
        ]);
        $record = Record::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Record',
        ]);

        Profile::query()->create(['record_id' => $record->id, 'label' => 'Primary']);
        Comment::query()->create(['record_id' => $record->id, 'body' => 'Visible One']);
        Comment::query()->create(['record_id' => $record->id, 'body' => 'Visible Two']);
        Comment::query()->create(['record_id' => $record->id, 'body' => 'Hidden']);

        $firstTag = Tag::query()->create(['name' => 'First']);
        $secondTag = Tag::query()->create(['name' => 'Second']);
        $record->tags()->attach([$firstTag->id, $secondTag->id]);
    }

    public function test_with_accepts_a_single_relation_string(): void
    {
        $record = DataTable::query(Record::query())
            ->with('organization')
            ->type('collection')
            ->make()
            ->first();

        $this->assertTrue($record->relationLoaded('organization'));
        $this->assertSame('Acme', $record->organization->name);
    }

    public function test_with_accepts_a_nested_relation_string(): void
    {
        $record = DataTable::query(Record::query())
            ->with('organization.country')
            ->type('collection')
            ->make()
            ->first();

        $this->assertTrue($record->relationLoaded('organization'));
        $this->assertTrue($record->organization->relationLoaded('country'));
        $this->assertSame('Indonesia', $record->organization->country->name);
    }

    public function test_with_accepts_an_indexed_relation_array(): void
    {
        $record = DataTable::query(Record::query())
            ->with(['organization.country', 'profile'])
            ->type('collection')
            ->make()
            ->first();

        $this->assertTrue($record->organization->relationLoaded('country'));
        $this->assertTrue($record->relationLoaded('profile'));
    }

    public function test_with_preserves_an_associative_closure_constraint(): void
    {
        $record = DataTable::query(Record::query())
            ->with([
                'comments' => fn ($query) => $query
                    ->where('body', 'like', 'Visible%')
                    ->orderBy('id'),
            ])
            ->type('collection')
            ->make()
            ->first();

        $this->assertSame(['Visible One', 'Visible Two'], $record->comments->pluck('body')->all());
    }

    public function test_repeated_with_calls_accumulate_relations(): void
    {
        $record = DataTable::query(Record::query())
            ->with('organization')
            ->with('profile')
            ->type('collection')
            ->make()
            ->first();

        $this->assertTrue($record->relationLoaded('organization'));
        $this->assertTrue($record->relationLoaded('profile'));
    }

    public function test_duplicate_plain_relations_are_deduplicated(): void
    {
        $query = Record::query();
        $record = DataTable::query($query)
            ->with('organization')
            ->with('organization')
            ->type('collection')
            ->make()
            ->first();

        $this->assertSame(['organization'], array_keys($query->getEagerLoads()));
        $this->assertTrue($record->relationLoaded('organization'));
    }

    public function test_later_with_constraint_replaces_the_earlier_constraint(): void
    {
        $record = DataTable::query(Record::query())
            ->with(['comments' => fn ($query) => $query->where('body', 'like', 'Visible%')])
            ->with(['comments' => fn ($query) => $query->where('body', 'Hidden')])
            ->type('collection')
            ->make()
            ->first();

        $this->assertSame(['Hidden'], $record->comments->pluck('body')->all());
    }

    public function test_constrained_with_definition_wins_in_either_call_order(): void
    {
        $plainFirst = DataTable::query(Record::query())
            ->with('comments')
            ->with(['comments' => fn ($query) => $query->where('body', 'Hidden')])
            ->type('collection')
            ->make()
            ->first();

        $constrainedFirst = DataTable::query(Record::query())
            ->with(['comments' => fn ($query) => $query->where('body', 'Hidden')])
            ->with('comments')
            ->type('collection')
            ->make()
            ->first();

        $this->assertSame(['Hidden'], $plainFirst->comments->pluck('body')->all());
        $this->assertSame(['Hidden'], $constrainedFirst->comments->pluck('body')->all());
    }

    public function test_empty_with_array_is_a_no_op(): void
    {
        $record = DataTable::query(Record::query())
            ->with([])
            ->type('collection')
            ->make()
            ->first();

        $this->assertFalse($record->relationLoaded('organization'));
    }

    public function test_with_rejects_an_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Relationship names must not be empty.');

        DataTable::query(Record::query())->with('');
    }

    public function test_with_rejects_a_whitespace_only_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Relationship names must not be empty.');

        DataTable::query(Record::query())->with('   ');
    }

    public function test_with_rejects_empty_indexed_relation_names(): void
    {
        foreach ([[''], ['   ']] as $relationships) {
            try {
                DataTable::query(Record::query())->with($relationships);
                $this->fail('An empty indexed relationship name was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Relationship names must not be empty.', $exception->getMessage());
            }
        }
    }

    public function test_with_rejects_empty_associative_relation_keys(): void
    {
        foreach (['', '   '] as $name) {
            try {
                DataTable::query(Record::query())->with([$name => fn ($query) => $query]);
                $this->fail('An empty associative relationship name was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Relationship names must not be empty.', $exception->getMessage());
            }
        }
    }

    public function test_with_validation_failure_does_not_partially_mutate_state(): void
    {
        $builder = DataTable::query(Record::query())->with('organization');

        try {
            $builder->with(['profile', '']);
            $this->fail('An invalid relationship definition was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Relationship names must not be empty.', $exception->getMessage());
        }

        $record = $builder->type('collection')->make()->first();

        $this->assertTrue($record->relationLoaded('organization'));
        $this->assertFalse($record->relationLoaded('profile'));
    }

    public function test_with_state_does_not_leak_between_builders(): void
    {
        $loaded = DataTable::query(Record::query())
            ->with('organization')
            ->type('collection')
            ->make()
            ->first();
        $unloaded = DataTable::query(Record::query())
            ->type('collection')
            ->make()
            ->first();

        $this->assertTrue($loaded->relationLoaded('organization'));
        $this->assertFalse($unloaded->relationLoaded('organization'));
    }

    public function test_with_count_accepts_a_single_relation_string(): void
    {
        $record = DataTable::query(Record::query())
            ->withCount('comments')
            ->type('collection')
            ->make()
            ->first();

        $this->assertSame(3, $record->comments_count);
    }

    public function test_with_count_accepts_an_indexed_relation_array(): void
    {
        $record = DataTable::query(Record::query())
            ->withCount(['comments', 'tags'])
            ->type('collection')
            ->make()
            ->first();

        $this->assertSame(3, $record->comments_count);
        $this->assertSame(2, $record->tags_count);
    }

    public function test_with_count_preserves_an_associative_closure_constraint(): void
    {
        $record = DataTable::query(Record::query())
            ->withCount([
                'comments' => fn ($query) => $query->where('body', 'like', 'Visible%'),
            ])
            ->type('collection')
            ->make()
            ->first();

        $this->assertSame(2, $record->comments_count);
    }

    public function test_repeated_with_count_calls_accumulate_relations(): void
    {
        $record = DataTable::query(Record::query())
            ->withCount('comments')
            ->withCount('tags')
            ->type('collection')
            ->make()
            ->first();

        $this->assertSame(3, $record->comments_count);
        $this->assertSame(2, $record->tags_count);
    }

    public function test_duplicate_plain_count_relations_are_deduplicated(): void
    {
        $query = Record::query();
        $record = DataTable::query($query)
            ->withCount('comments')
            ->withCount('comments')
            ->type('collection')
            ->make()
            ->first();

        $this->assertSame(3, $record->comments_count);
        $this->assertSame(1, substr_count(strtolower($query->toSql()), 'comments_count'));
    }

    public function test_later_with_count_constraint_replaces_the_earlier_constraint(): void
    {
        $record = DataTable::query(Record::query())
            ->withCount(['comments' => fn ($query) => $query->where('body', 'like', 'Visible%')])
            ->withCount(['comments' => fn ($query) => $query->where('body', 'Hidden')])
            ->type('collection')
            ->make()
            ->first();

        $this->assertSame(1, $record->comments_count);
    }

    public function test_constrained_with_count_definition_wins_in_either_call_order(): void
    {
        $plainFirst = DataTable::query(Record::query())
            ->withCount('comments')
            ->withCount(['comments' => fn ($query) => $query->where('body', 'Hidden')])
            ->type('collection')
            ->make()
            ->first();

        $constrainedFirst = DataTable::query(Record::query())
            ->withCount(['comments' => fn ($query) => $query->where('body', 'Hidden')])
            ->withCount('comments')
            ->type('collection')
            ->make()
            ->first();

        $this->assertSame(1, $plainFirst->comments_count);
        $this->assertSame(1, $constrainedFirst->comments_count);
    }

    public function test_with_count_aliases_remain_distinct(): void
    {
        $record = DataTable::query(Record::query())
            ->withCount([
                'comments',
                'comments as visible_comments_count' => fn ($query) => $query
                    ->where('body', 'like', 'Visible%'),
            ])
            ->type('collection')
            ->make()
            ->first();

        $this->assertSame(3, $record->comments_count);
        $this->assertSame(2, $record->visible_comments_count);
    }

    public function test_empty_with_count_array_is_a_no_op(): void
    {
        $record = DataTable::query(Record::query())
            ->withCount([])
            ->type('collection')
            ->make()
            ->first();

        $this->assertArrayNotHasKey('comments_count', $record->getAttributes());
    }

    public function test_with_count_rejects_empty_relation_names(): void
    {
        foreach (['', '   '] as $relationship) {
            try {
                DataTable::query(Record::query())->withCount($relationship);
                $this->fail('An empty count relationship name was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Relationship names must not be empty.', $exception->getMessage());
            }
        }
    }

    public function test_with_count_validation_failure_does_not_partially_mutate_state(): void
    {
        $builder = DataTable::query(Record::query())->withCount('comments');

        try {
            $builder->withCount(['tags', '']);
            $this->fail('An invalid count relationship definition was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Relationship names must not be empty.', $exception->getMessage());
        }

        $record = $builder->type('collection')->make()->first();

        $this->assertSame(3, $record->comments_count);
        $this->assertArrayNotHasKey('tags_count', $record->getAttributes());
    }

    public function test_with_count_state_does_not_leak_between_builders(): void
    {
        $counted = DataTable::query(Record::query())
            ->withCount('comments')
            ->type('collection')
            ->make()
            ->first();
        $uncounted = DataTable::query(Record::query())
            ->type('collection')
            ->make()
            ->first();

        $this->assertSame(3, $counted->comments_count);
        $this->assertArrayNotHasKey('comments_count', $uncounted->getAttributes());
    }

    public function test_relations_and_counts_work_with_pagination(): void
    {
        $result = DataTable::query(Record::query())
            ->with('organization')
            ->withCount('comments')
            ->make();
        $record = $result->items()[0];

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertTrue($record->relationLoaded('organization'));
        $this->assertSame(3, $record->comments_count);
    }

    public function test_query_builder_relation_calls_remain_no_ops(): void
    {
        $record = DataTable::query(DB::table('records'))
            ->with('organization')
            ->withCount('comments')
            ->type('collection')
            ->make()
            ->first();

        $this->assertSame('Record', $record->name);
        $this->assertObjectNotHasProperty('comments_count', $record);
    }
}
