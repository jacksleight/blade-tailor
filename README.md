![Packagist version](https://flat.badgen.net/packagist/v/jacksleight/blade-tailor)
![License](https://flat.badgen.net/github/license/jacksleight/blade-tailor)

# Blade Tailor

Blade Tailor allows you to customise (tailor) the props, classes and attributes used by blade components from outside the template files. This is particularly useful for theming components from external packages without having to publish them. If you have a library of your own re-usable components you can also make those tailorable by using the provided directive and attribute method.

> [!WARNING] 
> This package needs to alter external component templates during compilation in order to hook into their rendering processes. [The changes it makes](https://github.com/jacksleight/blade-tailor/blob/main/src/TailorManager.php#L135-L145) are very minor and limited to the components you're tailoring, but there may be edge cases that result in unexpected behaviour.

## Installation

Run the following command from your project root:

```bash
composer require jacksleight/blade-tailor
```

## Usage

### Tailoring Components

Tailoring components is done via the `Tailor::component()` method. You can either make these calls in a service provider or create a dedicated file for your customisations in `resources/tailor.php` (this will be loaded automatically). Whenever you add a rule for a brand new component you need to run `php artisan view:clear`, as the template will need to be recompiled. Further changes will not require recompiling.

```php
use JackSleight\BladeTailor\Tailor;

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
    )
    ->reset(true); // Remove all built-in styles 
```

You'll need to add any files where you're defining tailoring rules to your Tailwind config's `content` array to ensure the compiler picks up the new classes:

```js
content: [
    // ...
    "./resources/tailor.php",
],
```

### Making Components Tailorable

If you have a library of your own re-usable components you can make them tailorable by replacing the `@props` directive with `@tailor` and the `$attributes->class(...)` call with `$attributes->tailor(...)`.

```blade
@tailor([
    'type' => 'info',
    'message',
])
<div {{ $attributes->tailor('text-blue-500') }}>
    {{ $message }}
</div>
```

Technicailly this isn't really necessary, as the package will attempt to make these changes itself if it detects a tailored component, however doing it explicitly is probably better.

### Using Tailwind Merge

This package does not require [Tailwind Merge](https://github.com/gehrisandro/tailwind-merge-laravel) as a dependency, but if it's installed it will be used when merging your custom classes with the default ones.

### Using Tailwind Variant Shorthand

The tailor method supports a custom shorthand for specifying Tailwind variant classes:

```
'hover:: text-orange-500 underline' -> 'hover:text-orange-500 hover:underline'
```

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
