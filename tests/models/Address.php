<?php

declare(strict_types=1);

use Jenssegers\Mongodb\Relations\EmbedsMany;

class Address extends Base
{
    protected $connection = 'mongodb';
    protected static $unguarded = true;

    public function addresses(): EmbedsMany
    {
        return $this->embedsMany('Address');
    }
}
