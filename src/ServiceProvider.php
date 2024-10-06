<?php

namespace JackSleight\BladeTailor;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\Factory;

class ServiceProvider extends BaseServiceProvider
{
    public function register()
    {
        $this->app->singleton(TailorManager::class, fn () => new TailorManager);
    }

    public function boot()
    {
        app('blade.compiler')->prepareStringsForCompilationUsing(function ($string) {
            return Tailor::inject($string);
        });

        View::creator('*', function ($view) {
            return Tailor::prepare($view);
        });

        Blade::directive('tailor', function ($expression) {
            return Tailor::compile($expression);
        });

        ComponentAttributeBag::macro('tailor', function ($classes) {
            return Tailor::apply($this, $classes);
        });
        ComponentAttributeBag::macro('tailorKey', function () {
            return Tailor::extract($this);
        });

        Factory::macro('tailorParents', function () {
            return Arr::map($this->componentStack, fn ($name) => Tailor::resolveName($name));
        });

        if (file_exists($file = resource_path('tailor.php'))) {
            require_once $file;
        }
    }
}
