<?php

declare(strict_types=1);

class Guarded extends Base
{
    protected $connection = 'mongodb';
    protected $collection = 'guarded';
    protected $guarded = ['foobar', 'level1->level2'];
}
