<?php

namespace JackSleight\BladeTailor;

use Illuminate\Support\Facades\Facade;

class Tailor extends Facade
{
    protected static function getFacadeAccessor()
    {
        return TailorManager::class;
    }
}
