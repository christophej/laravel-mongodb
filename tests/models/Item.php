<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jenssegers\Mongodb\Eloquent\Builder;

/**
 * Class Item.
 * @property \Carbon\Carbon $created_at
 */
class Item extends Base
{
    protected $connection = 'mongodb';
    protected $collection = 'items';
    protected static $unguarded = true;

    public function user(): BelongsTo
    {
        return $this->belongsTo('User');
    }

    public function scopeSharp(Builder $query)
    {
        return $query->where('type', 'sharp');
    }
}
