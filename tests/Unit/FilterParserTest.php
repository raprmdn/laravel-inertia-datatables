<?php

namespace Tests\Unit;

use Raprmdn\DataTables\Facades\DataTable;
use Tests\TestCase;

class FilterParserTest extends TestCase
{
    public function test_standard_filters_are_mapped_and_allowed_columns_are_derived(): void
    {
        $result = DataTable::parseFilters(
            ['state:active', 'organization:Acme'],
            ['state' => 'status', 'organization' => 'organization.name']
        );

        $this->assertSame([
            ['status:active', 'organization.name:Acme'],
            ['status', 'organization.name'],
            [],
        ], $result);
    }

    public function test_exact_aliases_are_applied_before_column_mapping(): void
    {
        $result = DataTable::parseFilters(
            ['state:enabled', 'state:enabled:extra'],
            ['state' => 'status'],
            ['state:enabled' => 'state:active']
        );

        $this->assertSame(['status:active', 'status:enabled:extra'], $result[0]);
        $this->assertSame(['status'], $result[1]);
    }

    public function test_unknown_filters_are_preserved_but_not_added_to_allowed_filters(): void
    {
        [$filters, $allowed] = DataTable::parseFilters(
            ['unknown:value'],
            ['state' => 'status']
        );

        $this->assertSame(['unknown:value'], $filters);
        $this->assertSame(['status'], $allowed);
    }

    public function test_duplicate_filters_are_removed_in_first_occurrence_order(): void
    {
        [$filters] = DataTable::parseFilters(
            ['state:active', 'state:active', 'state:inactive'],
            ['state' => 'status']
        );

        $this->assertSame(['status:active', 'status:inactive'], $filters);
    }

    public function test_malformed_filters_are_ignored_and_additional_colons_are_preserved(): void
    {
        [$filters] = DataTable::parseFilters(
            ['missing-colon', ':missing-column', 'state:https://example.test:8443', 'state:'],
            ['state' => 'status']
        );

        $this->assertSame([
            'status:https://example.test:8443',
            'status:',
        ], $filters);
    }

    public function test_date_suffixes_are_mapped_to_date_ranges(): void
    {
        [$filters, $allowed, $ranges] = DataTable::parseFilters(
            ['created_from:01-01-2026', 'created_to:31-01-2026'],
            ['created' => 'created_at']
        );

        $this->assertSame([], $filters);
        $this->assertSame(['created_at'], $allowed);
        $this->assertSame([
            'created_at' => [
                'from' => '01-01-2026',
                'to' => '31-01-2026',
            ],
        ], $ranges);
    }

    public function test_map_only_shorthand_reads_the_configured_request_key(): void
    {
        config()->set('inertia-datatables.query_params.filters', 'facets');
        $this->setRequestQuery(['facets' => ['state:active']]);

        $result = DataTable::parseFilters(['state' => 'status']);

        $this->assertSame([
            ['status:active'],
            ['status'],
            [],
        ], $result);
    }

    public function test_non_array_input_and_non_string_entries_are_ignored(): void
    {
        $map = ['state' => 'status'];

        $this->assertSame([[], ['status'], []], DataTable::parseFilters('invalid', $map));
        $this->assertSame(
            [['status:active'], ['status'], []],
            DataTable::parseFilters([null, 10, ['state:inactive'], 'state:active'], $map)
        );
    }

    public function test_allowed_filters_are_unique_map_values_and_exclude_alias_targets(): void
    {
        [, $allowed] = DataTable::parseFilters(
            ['state:enabled'],
            ['state' => 'status', 'other_state' => 'status'],
            ['state:enabled' => 'unmapped:active']
        );

        $this->assertSame(['status'], $allowed);
    }
}
