<?php

namespace JackSleight\BladeTailor;

use Closure;
use Flux\ClassBuilder as FluxClassBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\ComponentSlot;
use TailwindMerge\Laravel\Facades\TailwindMerge;

class TailorManager
{
    protected $rules = [];

    protected $customs = [];

    public function component($name)
    {
        $name = $this->normalizeComponentName($name);

        if (! isset($this->rules[$name])) {
            $this->rules[$name] = new Rule($name);
        }

        return $this->rules[$name];
    }

    public function resolve($data, $props = [])
    {
        $name = $this->resolveComponentName($data['__tailor_name']);

        $rule = $this->rules[$name] ?? null;

        // Merge custom props into default props
        $props = array_merge($props, $rule->props ?? []);

        // Same logic as Laravel's @props directive
        $data['attributes'] = $data['attributes'] ?? new ComponentAttributeBag;
        foreach ($data['attributes']->onlyProps($props) as $key => $value) {
            $data[$key] = $data[$key] ?? $value;
        }
        $data['attributes'] = $data['attributes']->exceptProps($props);
        foreach (array_filter($props, 'is_string', ARRAY_FILTER_USE_KEY) as $key => $value) {
            $data[$key] = $data[$key] ?? $value;
        }
        foreach ($data['attributes'] as $key => $value) {
            if (array_key_exists($key, $data)) {
                unset($data[$key]);
            }
        }

        if (! $rule) {
            return $data;
        }

        // Find slot definitions and hydrate strings into instances
        $slots = ['root'];
        foreach ($props as $key => $value) {
            if ($value instanceof ComponentSlot) {
                $slots[] = $key;
                if (! $data[$key] instanceof ComponentSlot) {
                    $data[$key] = new ComponentSlot(null, ['name' => $data[$key]]);
                }
            }
        }

        // Collect data to pass to closures
        $pass = Arr::only($data, array_merge(['attributes'], array_keys($props)));

        // Store custom data and persist key in class attribute
        foreach ($slots as $slot) {
            $key = '__tailor_'.uniqid().'__';
            $classes = $rule->classes[$slot] ?? [];
            $attributes = $rule->attributes[$slot] ?? [];
            $this->customs[$key] = [
                'reset' => $rule->reset,
                'classes' => $classes instanceof Closure
                    ? app()->call($classes->bindTo($this, $this), $pass)
                    : Arr::wrap($classes),
                'attributes' => $attributes instanceof Closure
                    ? app()->call($attributes->bindTo($this, $this), $pass)
                    : Arr::wrap($attributes),
            ];
            if ($slot === 'root') {
                $data['attributes'] = $data['attributes']->class([$key]);
            } else {
                $data[$slot]->attributes = $data[$slot]->attributes->class([$key]);
            }
        }

        return $data;
    }

    public function apply(ComponentAttributeBag $bag, $classes)
    {
        if ($classes instanceof FluxClassBuilder) {
            $classes = (string) $classes;
        }

        $passed = Arr::toCssClasses($bag->get('class'));

        if (! $key = Str::match('/__tailor_.*?__/', $passed)) {
            return $bag->class($classes);
        }

        if (! $custom = $this->customs[$key] ?? null) {
            return $bag->class($classes);
        }

        return $bag
            ->merge([
                'class' => $this->resolveClasses([
                    ...Arr::wrap($custom['reset'] ? [] : $classes),
                    ...Arr::wrap($custom['classes']),
                    ...Arr::wrap(Str::replace($key, '', $passed)),
                ]),
                ...$custom['attributes'] ?? [],
            ], false);
    }

    public function inject($string)
    {
        if (Str::contains($string, '@tailor')) {
            return $string;
        }

        $name = $this->lookupComponentName(app('blade.compiler')->getPath());

        if (! ($this->rules[$name] ?? null)) {
            return $string;
        }

        if (Str::contains($string, '@props(')) {
            $string = Str::replace('@props(', '@tailor(', $string);
        } else {
            $string = "@tailor\n".$string;
        }

        $string = Str::replaceMatches(
            '/(attributes(->\w+\(.*\))*->)class\(/i',
            '$1tailor(',
            $string,
        );

        return $string;
    }

    public function compile($expression)
    {
        return "<?php
\$__tailor_vars = get_defined_vars();
\$__tailor_data = \JackSleight\BladeTailor\Tailor::resolve(\Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']), {$expression});
foreach (\$__tailor_data as \$__tailor_key => \$__tailor_value) {
    \$\$__tailor_key = \$__tailor_value;
}
foreach (\$__tailor_vars as \$__tailor_key => \$__tailor_value) {
    if (!array_key_exists(\$__tailor_key, \$__tailor_data)) unset(\$\$__tailor_key);
}
unset(\$__tailor_key);
unset(\$__tailor_value);
unset(\$__tailor_data);
unset(\$__tailor_vars);
unset(\$__tailor_name);
?>";
    }

    protected function resolveClasses($classes)
    {
        $classes = collect($classes)
            ->mapWithKeys(function ($value, $key) {
                $var = is_int($key) ? 'value' : 'key';
                if (preg_match('/^([^\s]+)\:\:\s(.*?)$/', ${$var} ?? '', $match)) {
                    ${$var} = collect(preg_split('/\s+/', $match[2]))
                        ->map(fn ($class) => "{$match[1]}:{$class}")
                        ->join(' ');
                }

                return [$key => $value];
            })
            ->all();

        $classes = Arr::toCssClasses($classes);

        if (class_exists(TailwindMerge::class)) {
            $classes = TailwindMerge::merge($classes);
        }

        return $classes;
    }

    protected function normalizeComponentName($name)
    {
        if (Str::contains($name, '::')) {
            return $name;
        }

        return Str::replace(':', '::', $name);
    }

    protected function resolveComponentName($name)
    {
        [$prefix, $name] = explode('::', $name);

        $group = collect(app('blade.compiler')->getAnonymousComponentPaths())
            ->mapWithKeys(fn ($group) => [$group['prefixHash'] => $group])
            ->get($prefix);

        $prefix = $group['prefix'];

        $name = Str::between($name, 'components.', '.index');

        return $prefix.'::'.$name;
    }

    protected function lookupComponentName($path)
    {
        $group = collect(app('blade.compiler')->getAnonymousComponentPaths())
            ->filter(fn ($group) => Str::startsWith($path, $group['path']))
            ->first();

        if (! $group) {
            return;
        }

        $prefix = $group['prefix'];

        $name = Str::of($path)
            ->after($group['path'])
            ->before('.blade.php')
            ->trim('/')
            ->replace('/', '.')
            ->between('components.', '.index');

        return $prefix.'::'.$name;
    }
}
