<?php
declare(strict_types=1);

use Jenssegers\Mongodb\Eloquent\Model as Eloquent;

class Base extends Eloquent
{
	public function getIdAttribute($value = null)
	{
		return (string)$value;
	}
}
