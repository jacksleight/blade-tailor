<?php

namespace JackSleight\BladeTailor\View;

use Illuminate\View\ComponentSlot as BaseComponentSlot;

class ComponentSlot extends BaseComponentSlot
{
    protected $hydrate;

    public function hydrate($hydrate = null)
    {
        if (func_num_args()) {
            $this->hydrate = $hydrate;

            return $this;
        }

        return $this->hydrate;
    }

    public function withAttributes(array $attributes)
    {
        $this->attributes = new ComponentAttributeBag($attributes);

        return $this;
    }
}
