<?php

namespace JackSleight\BladeTailor;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register()
    {
        $this->app->singleton(TailorManager::class, fn () => new TailorManager);
    }

    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/tailor.php' => config_path('tailor.php'),
        ], 'tailor-config');

        $this->mergeConfigFrom(
            __DIR__.'/../config/tailor.php', 'tailor'
        );

        if (config('tailor.intercept')) {
            app('blade.compiler')->prepareStringsForCompilationUsing(function ($string) {
                return Tailor::intercept($string);
            });
        }

        View::creator('*', function ($view) {
            return Tailor::prepare($view);
        });

        Blade::directive('tailor', function ($expression) {
            return Tailor::compile($expression);
        });

        if (file_exists($file = resource_path('tailor.php'))) {
            require_once $file;
        }
    }
}
