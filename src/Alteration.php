<?php

namespace JackSleight\BladeTailor;

use Closure;

/**
 * @todo Refactor this, classes and attributes should
 * be grouped by slot, rather than slots being grouped
 * by classes and attributes.
 */
class Alteration
{
    public string $name;

    public array $props = [];

    public array $classes = [];

    public array $attributes = [];

    public bool $reset = false;

    public function __construct(string $name)
    {
        $this->name = $name;
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
        $this->classes[$name] = $classes;
        $this->attributes[$name] = $attributes;

        return $this;
    }

    public function reset(boolean $reset): static
    {
        $this->reset = $reset;

        return $this;
    }
}
