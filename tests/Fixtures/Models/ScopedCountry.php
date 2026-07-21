<?php

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;

class ScopedCountry extends Country
{
    protected $table = 'countries';

    protected static function booted(): void
    {
        static::addGlobalScope('visible', function (Builder $query): void {
            $query->where($query->qualifyColumn('name'), '!=', 'Hidden Country');
        });
    }
}
