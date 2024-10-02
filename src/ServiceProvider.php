<?php

namespace JackSleight\BladeTailor;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\Support\Str;
use Illuminate\View\ComponentAttributeBag;

class ServiceProvider extends BaseServiceProvider
{
    public function register()
    {
        $this->app->singleton(TailorManager::class, fn () => new TailorManager);
    }

    public function boot()
    {
        Blade::directive('tailor', fn ($expression) => Tailor::compile($expression));

        ComponentAttributeBag::macro('tailor', fn ($classes) => Tailor::apply($this, $classes));

        View::creator('*', function ($view) {
            $view->with('__tailor_name', $view->name());
        });

        app('blade.compiler')->prepareStringsForCompilationUsing(function ($string) {
            if (! Str::contains($string, '@props(')) {
                return $string;
            }
            $string = Str::replace('@props(', '@tailor(', $string);
            $string = Str::replaceMatches(
                '/(attributes(->\w+\(.*\))*->)class\(/i', // Needs more work
                '$1tailor(',
                $string,
            );

            return $string;
        });
    }
}
