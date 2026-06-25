<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Query Parameter Names
    |--------------------------------------------------------------------------
    |
    | These keys define how the datatable reads values from the request query.
    |
    */
    'query_params' => [
        'search'    => 'search',
        'filters'   => 'filters',
        'column'    => 'col',
        'direction' => 'sort',
        'limit'     => 'limit',
    ],

    /*
    |--------------------------------------------------------------------------
    | Date Format
    |--------------------------------------------------------------------------
    |
    | Incoming date range filters use this format.
    | Example: 01-06-2026
    |
    */
    'date_format' => 'd-m-Y',

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'default_per_page' => 10,
        'max_per_page'     => 100,
        'on_each_side'     => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | JSON Columns
    |--------------------------------------------------------------------------
    |
    | Columns listed here use whereJsonContains when filtered.
    |
    */
    'json_columns' => [],
];
