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

    public array $replace = [];

    public array $remove = [];

    public bool $reset = false;

    public function __construct(array $names)
    {
        $this->names = $names;
    }

    public function matches($name): bool
    {
        foreach ($this->names as $pattern) {
            if (Str::is($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    public function props(?array $props): static
    {
        $this->props = $props;

        return $this;
    }

    public function classes(string|array|Closure $classes): static
    {
        $this->slots['root']['classes'] = $classes;

        return $this;
    }

    public function attributes(array|Closure $attributes): static
    {
        $this->slots['root']['attributes'] = $attributes;

        return $this;
    }

    public function root(
        string|array|Closure $classes = [],
        array|Closure $attributes = [],
    ): static {
        $this->slots['root'] = [
            'classes' => $classes,
            'attributes' => $attributes,
        ];

        return $this;
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

    public function replace(?array $replace): static
    {
        $this->replace = $replace;

        return $this;
    }

    public function remove(?array $remove): static
    {
        $this->remove = $remove;

        return $this;
    }

    public function reset(?bool $reset): static
    {
        $this->reset = $reset;

        return $this;
    }
}
