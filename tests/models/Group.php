<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Group extends Base
{
    protected $connection = 'mongodb';
    protected $collection = 'groups';
    protected static $unguarded = true;

    public function users(): BelongsToMany
    {
        return $this->belongsToMany('User', 'users', 'groups', 'users', '_id', '_id', 'users');
    }
}
