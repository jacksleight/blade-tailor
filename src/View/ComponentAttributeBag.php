<?php

namespace JackSleight\BladeTailor\View;

use Illuminate\Support\Str;
use Illuminate\View\ComponentAttributeBag as BaseComponentAttributeBag;
use JackSleight\BladeTailor\Tailor;

class ComponentAttributeBag extends BaseComponentAttributeBag
{
    public function class($classList)
    {
        return Tailor::apply($this, $classList);
    }

    public function tailorClass($classList)
    {
        return parent::class($classList);
    }

    public function tailorKeyInject($key)
    {
        $this['class'] .= ' '.$key;
    }

    public function tailorKeyExtract()
    {
        if (! $key = Str::match('/__tailor_key_.*?__/', $this['class'])) {
            return;
        }

        $this['class'] = Str::replace($key, '', $this['class']);

        return $key;
    }

    public function tailorInject($attributes)
    {
        foreach ($attributes as $key => $value) {
            $this[$key] = $value;
        }

        return $this;
    }

    public function tailorExtract($prefix)
    {
        $attributes = [];
        foreach ($this->attributes as $key => $value) {
            if (Str::startsWith($key, $prefix.'.')) {
                $attributes[Str::after($key, $prefix.'.')] = $value;
                unset($this[$key]);
            }
        }

        return $attributes;
    }
}
