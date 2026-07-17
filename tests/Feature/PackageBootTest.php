<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Raprmdn\DataTables\DataTableManager;
use Tests\Fixtures\Models\Comment;
use Tests\Fixtures\Models\Country;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Profile;
use Tests\Fixtures\Models\Record;
use Tests\Fixtures\Models\Tag;
use Tests\TestCase;

class PackageBootTest extends TestCase
{
    public function test_package_boots_with_database_fixtures(): void
    {
        $expectedConnection = getenv('DB_CONNECTION') ?: 'sqlite';

        $this->assertInstanceOf(DataTableManager::class, $this->app->make('inertia-datatables'));
        $this->assertSame($expectedConnection, config('database.default'));
        $this->assertTrue(Schema::hasTable('records'));

        $country = Country::query()->create(['name' => 'Indonesia']);
        $organization = Organization::query()->create([
            'country_id' => $country->id,
            'name' => 'Acme',
        ]);
        $manager = Record::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Manager',
        ]);
        $record = Record::query()->create([
            'organization_id' => $organization->id,
            'manager_id' => $manager->id,
            'name' => 'Record',
            'status' => null,
            'metadata' => ['channel' => 'web'],
        ]);

        Profile::query()->create(['record_id' => $record->id, 'label' => 'Primary']);
        Comment::query()->create(['record_id' => $record->id, 'body' => 'Ready']);
        $tag = Tag::query()->create(['name' => 'Beta']);
        $record->tags()->attach($tag);

        $record->load('organization.country', 'profile', 'comments', 'tags', 'manager');

        $this->assertSame('Indonesia', $record->organization->country->name);
        $this->assertSame('Primary', $record->profile->label);
        $this->assertCount(1, $record->comments);
        $this->assertCount(1, $record->tags);
        $this->assertSame('Manager', $record->manager->name);
        $this->assertNull($record->status);
        $this->assertSame(['channel' => 'web'], $record->metadata);
    }
}
