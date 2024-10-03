<?php

namespace JackSleight\BladeTailor;

use Closure;
use Illuminate\Support\Str;

/**
 * @todo Refactor this, classes and attributes should
 * be grouped by slot, rather than slots being grouped
 * by classes and attributes.
 */
class Alteration
{
    public array $names;

    public array $props = [];

    public array $slots = [];

    public bool $reset = false;

    public function __construct(array $names)
    {
        $this->names = $names;
    }

    public function matches($name)
    {
        foreach ($this->names as $pattern) {
            if (Str::is($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    public function props(array $props): static
    {
        $this->props = $props;

        return $this;
    }

    public function root(
        string|array|Closure $classes = [],
        array|Closure $attributes = [],
    ) {
        return $this->slot('root', $classes, $attributes);
    }

    public function slot(
        $name,
        string|array|Closure $classes = [],
        array|Closure $attributes = [],
    ): static {
        $this->slots[$name] = [
            'classes' => $classes,
            'attributes' => $attributes,
        ];

        return $this;
    }

    public function reset(boolean $reset): static
    {
        $this->reset = $reset;

        return $this;
    }
}
