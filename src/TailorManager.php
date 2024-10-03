<?php

namespace JackSleight\BladeTailor;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\ComponentSlot;
use TailwindMerge\Laravel\Facades\TailwindMerge;

class TailorManager
{
    protected $alterations = [];

    protected $results = [];

    public function component(string|array $names): Alteration
    {
        $names = collect($names)
            ->map(fn ($name) => $this->normalizeName($name))
            ->all();

        $this->alterations[] = $alteration = new Alteration($names);

        return $alteration;
    }

    public function alterations(?string $name): Collection
    {
        return collect($this->alterations)
            ->filter(fn ($alteration) => $alteration->matches($name));
    }

    public function resolve($data, $props = [])
    {
        $name = $data['__tailor_name'] ?? null;

        $alterations = $this->alterations($name);

        // Merge result props into default props
        $props = $alterations
            ->map(fn ($alteration) => $alteration->props())
            ->unshift($props)
            ->flatMap(fn ($props) => $props)
            ->all();

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

        if ($alterations->isEmpty()) {
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

        // Collect args to pass to closures
        $args = Arr::only($data, array_merge(['attributes'], array_keys($props)));

        // Merge and store alterations and persist key in class attribute
        foreach ($slots as $slot) {
            $key = '__tailor_'.uniqid().'__';
            $this->results[$key] = [
                'reset' => $alterations
                    ->contains(fn ($alteration) => $alteration->reset()),
                'replace' => $alterations
                    ->flatMap(fn ($alteration) => $alteration->replace())
                    ->all(),
                'classes' => $alterations
                    ->flatMap(fn ($alteration) => Arr::wrap($this->evaluate($alteration->classes($slot), $args)))
                    ->all(),
                'attributes' => $alterations
                    ->flatMap(fn ($alteration) => $this->evaluate($alteration->attributes($slot), $args))
                    ->all(),
            ];
            if ($slot === 'root') {
                $data['attributes'] = $data['attributes']->class([$key]);
            } else {
                $data[$slot]->attributes = $data[$slot]->attributes->class([$key]);
            }
        }

        return $data;
    }

    public function apply(ComponentAttributeBag $bag, $default)
    {
        if (is_object($default)) {
            $default = (string) $default;
        }

        $default = Arr::toCssClasses($default);
        $passed = Arr::toCssClasses($bag->get('class'));
        $bag = $bag->except('class');

        if (! $key = Str::match('/__tailor_.*?__/', $passed)) {
            return $bag->class([$default, $passed]);
        }

        $result = $this->results[$key] ?? null;
        
        $passed = Str::replace($key, '', $passed);

        if (! $result) {
            return $bag->class([$default, $passed]);
        }

        if ($result['reset']) {
            $default = null;
        } elseif ($result['replace']) {
            $default = collect(explode(' ', $default))
                ->map(fn ($class) => $result['replace'][$class] ?? $class)
                ->join(' ');
        }

        return $bag
            ->class($this->resolveClasses([
                $default,
                ...$result['classes'],
                $passed,
            ]))
            ->merge($result['attributes'] ?? []);
    }

    public function inject($string)
    {
        if (Str::contains($string, '@tailor')) {
            return $string;
        }

        $name = $this->lookupName(app('blade.compiler')->getPath());

        if ($this->alterations($name)->isEmpty()) {
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

    public function prepare($view)
    {
        $name = $this->resolveName($view->name());

        if ($this->alterations($name)->isEmpty()) {
            return $view;
        }

        return $view->with('__tailor_name', $name);
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

    protected function evaluate($value, $args)
    {
        return $value instanceof Closure
            ? app()->call($value->bindTo($this, $this), $args)
            : $value;
    }

    protected function normalizeName($name)
    {
        if (Str::contains($name, '::')) {
            return $name;
        }

        return Str::replace(':', '::', $name);
    }

    protected function resolveName($name)
    {
        if (! Str::contains($name, '::')) {
            return;
        }

        [$prefix, $name] = explode('::', $name);

        $group = collect(app('blade.compiler')->getAnonymousComponentPaths())
            ->mapWithKeys(fn ($group) => [$group['prefixHash'] => $group])
            ->get($prefix);

        if (! $group) {
            return;
        }

        $prefix = $group['prefix'];

        $name = Str::between($name, 'components.', '.index');

        return $prefix.'::'.$name;
    }

    protected function lookupName($path)
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
