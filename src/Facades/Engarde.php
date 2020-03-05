<?php namespace Tjphippen\Engarde\Facades;

use Illuminate\Support\Facades\Facade;

class Engarde extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'engarde';
    }
}