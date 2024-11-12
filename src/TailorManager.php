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

        // If there are no alterations return data as is
        if ($alterations->isEmpty()) {
            return $data;
        }

        // Find attribute bags and convert them to custom instances
        foreach ($data as $key => $value) {
            if ($value instanceof ComponentAttributeBag) {
                $data[$key] = new View\ComponentAttributeBag($value->all());
            } elseif ($value instanceof ComponentSlot) {
                $data[$key]->attributes = new View\ComponentAttributeBag($value->attributes->all());
            }
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
            $result = [
                'name' => $name,
                'replace' => $alterations
                    ->flatMap(fn ($alteration) => $alteration->replace)
                    ->all(),
                'remove' => $alterations
                    ->flatMap(fn ($alteration) => $alteration->remove)
                    ->all(),
                'reset' => $alterations
                    ->contains(fn ($alteration) => $alteration->reset),
                'classes' => $alterations
                    ->flatMap(fn ($alteration) => Arr::wrap($this
                        ->evaluate($alteration->slots[$slot]['classes'] ?? [], $args)))
                    ->all(),
                'attributes' => $alterations
                    ->flatMap(fn ($alteration) => $this
                        ->evaluate($alteration->slots[$slot]['attributes'] ?? [], $args))
                    ->all(),
            ];
            $key = '__tailor_key_'.uniqid().'__';
            $this->results[$key] = $result;
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

        $default = Arr::toCssClasses($default);
        $passed = Arr::toCssClasses($bag['class']);
        $bag = $bag->except('class');

        if (! $key = Str::match('/__tailor_key_.*?__/', $default.$passed)) {
            return $bag->tailorClass([$default, $passed]);
        }

        $result = $this->results[$key] ?? null;

        $default = Str::replace($key, '', $default);
        $passed = Str::replace($key, '', $passed);

        if (! $result) {
            return $bag->tailorClass([$default, $passed]);
        }

        if ($result['reset']) {
            $default = null;
        } elseif ($result['replace'] || $result['remove']) {
            $default = collect(explode(' ', $default))
                ->reject(fn ($class) => in_array($class, $result['remove']))
                ->map(fn ($class) => $result['replace'][$class] ?? $class)
                ->join(' ');
        }

        return $bag
            ->tailorClass($this->resolveClasses([
                $default,
                $passed,
                ...$result['classes'],
            ]))
            ->merge($result['attributes'] ?? []);
    }

    public function inject($string)
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

        // Allow hooking into plain HTML tags with hard coded classes
        // I don't like this, ideally it wouldn't be necessary, very experimental
        // $tags = [];
        // $string = Str::replaceMatches(
        //     '/<((?!x-)[a-z-]+)(\s[^>]*?class=")((?!\{)[^"]+(?!\{))("[^>]*)>/i',
        //     function ($match) use (&$tags) {
        //         [$match, $tag, $before, $value, $after] = $match;
        //         $tags[$tag] = $tags[$tag] ?? 1;
        //         $id = '__tailor_tag_'.$tag.'_'.$tags[$tag]++;
        //         $call = '{{ $__tailor("'.$id.'", "'.$value.'") }}';

        //         return "<{$tag}{$before}{$call}{$after}>";
        //     },
        //     $string,
        // );

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

    public function resolveName($name)
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
