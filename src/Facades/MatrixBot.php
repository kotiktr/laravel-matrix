<?php

namespace Kotiktr\LaravelMatrix\Facades;

use Illuminate\Support\Facades\Facade;

class MatrixBot extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'matrix.bot';
    }
}
