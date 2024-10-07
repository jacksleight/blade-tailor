<?php

namespace JackSleight\BladeTailor;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
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

        Factory::macro('tailorInfo', function () {
            return [
                'parents' => collect($this->componentStack)
                    ->map(fn ($name) => Tailor::resolveName($name))
                    ->all(),
            ];
        });

        if (file_exists($file = resource_path('tailor.php'))) {
            require_once $file;
        }
    }
}
