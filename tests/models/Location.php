<?php

declare(strict_types=1);

class Location extends Base
{
    protected $connection = 'mongodb';
    protected $collection = 'locations';
    protected static $unguarded = true;
}
