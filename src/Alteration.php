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
    protected array $names;

    protected array $props = [];

    protected array $slots = [];

    protected bool $reset = false;

    protected array $replace = [];

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

    public function props(?array $props = null): array|static
    {
        if (func_num_args() > 0) {
            $this->props = $props;

            return $this;
        }

        return $this->props;
    }

    public function root(
        string|array|Closure $classes = [],
        array|Closure $attributes = [],
    ) {
        return $this->slot(
            'root',
            $classes,
            $attributes,
        );
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

    public function reset(?bool $reset = null): bool|static
    {
        if (func_num_args() > 0) {
            $this->reset = $reset;

            return $this;
        }

        return $this->reset;
    }

    public function replace(?array $replace = null): array|static
    {
        if (func_num_args() > 0) {
            $this->replace = $replace;

            return $this;
        }

        return $this->replace;
    }

    public function classes(string $slot)
    {
        return $this->slots[$slot]['classes'] ?? [];
    }

    public function attributes(string $slot)
    {
        return $this->slots[$slot]['attributes'] ?? [];
    }
}
