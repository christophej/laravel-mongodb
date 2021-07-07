<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\MorphTo;

class Photo extends Base
{
    protected $connection = 'mongodb';
    protected $collection = 'photos';
    protected static $unguarded = true;

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}
