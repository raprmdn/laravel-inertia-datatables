<?php

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $guarded = [];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function scopedCountry(): BelongsTo
    {
        return $this->belongsTo(ScopedCountry::class, 'country_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }
}
