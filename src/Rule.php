<?php

namespace JackSleight\BladeTailor;

use Closure;

// @todo Refactor this to have the properties grouped by slot rather than the slots grouped by properties
class Rule
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
        $reset = false
    ) {
        return $this->slot('root', $classes, $attributes, $reset);
    }

    public function slot(
        $name,
        string|array|Closure $classes = [],
        array|Closure $attributes = [],
        $reset = false
    ): static {
        $this->classes[$name] = $classes;
        $this->attributes[$name] = $attributes;
        $this->reset = $reset;

        return $this;
    }
}
