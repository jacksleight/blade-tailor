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

    public function alterations(?string $name, ?array $parents = null): Collection
    {
        return collect($this->alterations)
            ->filter(fn ($alteration) => $alteration->matches($name, $parents));
    }

    public function resolve($data, $props = [])
    {
        $name = $data['__tailor_name'] ?? null;

        $alterations = $this->alterations($name, view()->tailorInfo()['parents']);

        // Merge alteration props into default props
        $props = $alterations
            ->map(fn ($alteration) => $alteration->props)
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

        // Find attribute bags and convert them to custom instances
        foreach ($data as $key => $value) {
            if ($value instanceof ComponentAttributeBag) {
                $data[$key] = new View\ComponentAttributeBag($value->all());
            } elseif ($value instanceof ComponentSlot) {
                $data[$key]->attributes = new View\ComponentAttributeBag($value->attributes->all());
            }
        }

        // If there are no alterations return data as is
        if ($alterations->isEmpty()) {
            return $data;
        }

        // Collect args to pass to closures
        $args = Arr::only($data, array_merge(['attributes'], array_keys($props)));

        // Merge and store alterations and persist key in class attribute
        $bags = [];
        $slots = $alterations
            ->flatMap(fn ($alteration) => array_keys($alteration->slots))
            ->all();
        foreach ($slots as $slot) {
            $bag = $slot === 'root'
                ? $data['attributes']
                : ($data[$slot] ?? null)?->attributes;
            $replace = $alterations
                ->flatMap(fn ($alteration) => $alteration->replace);
            $remove = $alterations
                ->flatMap(fn ($alteration) => $alteration->remove);
            $reset = $alterations
                ->contains(fn ($alteration) => $alteration->reset);
            $classes = $alterations
                ->flatMap(fn ($alteration) => Arr::wrap($this
                    ->evaluate($alteration->slots[$slot]['classes'] ?? [], $args)));
            $attributes = $alterations
                ->flatMap(fn ($alteration) => $this
                    ->evaluate($alteration->slots[$slot]['attributes'] ?? [], $args));
            $key = '__tailor_key_'.uniqid().'__';
            $this->results[$key] = [
                'name' => $name,
                'replace' => $replace->all(),
                'remove' => $remove->all(),
                'reset' => $reset,
                'classes' => $classes->all(),
                'attributes' => $attributes->all(),
            ];
            if (! $bag) {
                $bag = new View\ComponentAttributeBag;
                $bags[$slot] = $bag;
            }
            $bag->tailorKeyInject($key);
        }

        $data['__tailor'] = function ($id, $default) use ($bags) {
            if (! $bag = $bags[$id] ?? null) {
                return $default.' '.$id;
            }

            return $bag->class($default)['class'];
        };

        return $data;
    }

    public function apply(View\ComponentAttributeBag $bag, $default)
    {
        if (is_object($default)) {
            $default = (string) $default;
        }

        $default = Arr::wrap($default);
        $passed = Arr::wrap($bag['class']);
        $merged = [...$default, ...$passed];

        $string = Arr::toCssClasses($merged);
        $bag = $bag->except('class');

        if (! $key = Str::match('/__tailor_key_.*?__/', $string)) {
            return $bag->tailorClass($this->resolveClasses($merged));
        }

        $result = $this->results[$key] ?? null;

        if (! $result) {
            $resolved = $this->resolveClasses($merged);
            $resolved = Str::replace($key, '', $resolved);

            return $bag->tailorClass($resolved);
        }

        if ($result['reset']) {
            $default = [];
        } elseif ($result['replace'] || $result['remove']) {
            $default = collect(explode(' ', Arr::toCssClasses($default)))
                ->reject(fn ($class) => in_array($class, $result['remove']))
                ->map(fn ($class) => $result['replace'][$class] ?? $class)
                ->all();
        }

        $resolved = $this->resolveClasses([
            ...$default,
            ...$passed,
            ...$result['classes'],
        ]);
        $resolved = Str::replace($key, '', $resolved);

        return $bag
            ->tailorClass($resolved)
            ->merge($result['attributes'] ?? []);
    }

    public function intercept($string)
    {
        if (Str::contains($string, '@tailor')) {
            return $string;
        }

        $name = $this->lookupName(app('blade.compiler')->getPath());

        $alterations = $this->alterations($name);
        if ($alterations->isEmpty()) {
            return $string;
        }

        if (Str::contains($string, '@props(')) {
            $string = Str::replace('@props(', '@tailor(', $string);
        } else {
            $string = "@tailor\n".$string;
        }

        if (Str::contains($string, 'Flux::classes()')) {
            $string = Str::replace(
                '$classes = Flux::classes()',
                '$classes = Flux::classes()->add($attributes->tailorKeyExtract())',
                $string,
            );
        }

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

    public function resolveClasses($classes)
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

    public function resolveName($name)
    {
        if (! Str::contains($name, '::')) {
            return;
        }

        [$prefix, $name] = explode('::', $name);

        $group = collect(app('blade.compiler')->getAnonymousComponentPaths())
            ->mapWithKeys(fn ($group) => [$group['prefixHash'] => $group])
            ->get($prefix);

        if ($group) {
            $prefix = $group['prefix'];
        }

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
