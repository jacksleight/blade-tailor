![Packagist version](https://flat.badgen.net/packagist/v/jacksleight/blade-tailor)
![License](https://flat.badgen.net/github/license/jacksleight/blade-tailor)

# Blade Tailor

Blade Tailor allows you to customise (tailor) the props, classes and attributes used by blade components from outside the template files. This is particularly useful for themeing components from external packages without having to publish their files. If you have a library of your own re-usable components you can also make those tailorable by using the provided directive and attribute method.

::warning
This package has to do some slightly "creative" things in order to hook into the rendering process of external components. While the changes it makes are only very minor and limited to the components you're tailoring, they may have unintended side effects. I can't promise this will always work and there may be edge cases it simply can't handle.

## Installation

Run the following command from your project root:

```bash
composer require jacksleight/blade-tailor
```

## Usage

### Tailoring External Components

Tailoring components is done via the `Tailor` facade. You can etiher make these calls in a service provider or create a dedicated file for your customisaitons in `resources/tailor.php` (this will be loaded automatically). You'll need to add any files where you're defining tailoring rules to your Tailwind config's `content` array to ensure the compiler picks up the new classes.

```php
Tailor::component('flux::button')
    ->props([
        'variant' => 'primary', // Customise prop defaults
        'huge' => false, // Add new props
    ])
    ->root( // Customise root element
        classes: fn ($variant, $huge) => [
            'border-4 rounded-full -rotate-2 !shadow-drop-1g',
            'scale-150' => $huge,
            '[&>[data-flux-icon]]:: text-orange-500 size-10 -mx-2 mb-0.5 self-end',
            match ($variant) {
                'primary' => 'border-blue-300',
                'danger' => 'border-red-800',
                default => '',
            },
        ],
        attributes: [ // Add new attributes
            'data-thing' => config('thing.enabled'),
        ]
    )
    ->slot( // Customise slot elements
        name: 'item',
        classes: [
            'text-red-500',
        ]
    );
```

### Making Components Tailorable

If you have a library of your own re-usable components you can make them tailorable simply by replcaing the `@props` directive with `@tailor` and the `$attributes->class(...)` call with `$attributes->tailor(...)`.

```blade
@tailor([
    'type' => 'info',
    'message',
])
<div {{ $attributes->tailor('text-blue-500') }}>
    {{ $message }}
</div>
```

### Using Tailwind Merge

This package does not require [TailwindMerge for Laravel](https://github.com/gehrisandro/tailwind-merge-laravel) as a dependency, but if it's installed it will be used when merging your custom classes with the default ones.

### Using Tailwind Variant Shorthand

The `$attributes->tailor(...)` method supports a custom shorthand for specifying variant classes:

To make this work with the Tailwind compiler you'll need to add a custom extract method for PHP files to your Tailwind config:

```js
content: {
    files: [
        // ...
    ],
    extract: {
        php: (content) => [
            ...content.match(/[^"'`\s]*/g),
            ...Array.from(content.matchAll(/'([^\s]+)\:\:\s(.*?)'/g))
                .map((match) => match[2].split(/\s+/).map(name => `${match[1]}:${name}`))
                .flat(),
        ],
    },
},