<?php

namespace JackSleight\BladeTailor;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\View\ComponentAttributeBag;

class ServiceProvider extends BaseServiceProvider
{
    public function register()
    {
        $this->app->singleton(TailorManager::class, fn () => new TailorManager);
    }

    public function boot()
    {
        app('blade.compiler')->prepareStringsForCompilationUsing(
            fn ($string) => Tailor::inject($string)
        );

        View::creator('*',
            fn ($view) => $view->with('__tailor_name', $view->name())
        );

        Blade::directive('tailor',
            fn ($expression) => Tailor::compile($expression)
        );

        ComponentAttributeBag::macro('tailor',
            fn ($classes) => Tailor::apply($this, $classes)
        );

        if (file_exists($file = resource_path('tailor.php'))) {
            require_once $file;
        }
    }
}
