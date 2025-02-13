# Blade Tailor

Blade Tailor allows you to build blade components that can be customised (tailored) without publishing their templates. This is particularly useful when bulding a shared component library that should be customisable. It's possible to alter the default props, classes and attributes used by tailored components externally.

## Installation

Run the following command from your project root:

```bash
composer require jacksleight/blade-tailor
```

If you need to customise the config you can publish it with:

```bash
php artisan vendor:publish --tag="tailor-config"
```

## Usage

### Making Components Tailorable

You can make a component tailorable by replacing the `@props` directive with `@tailor`.

```blade
@tailor([
    'type' => 'info',
    'message',
])
<div {{ $attributes->class('text-blue-500') }}>
    {{ $message }}
</div>
```

### Tailoring Components

Tailoring components is done via the `Tailor::component()` method. You can either make these calls in a service provider or create a dedicated file for your customisations in `resources/tailor.php` (this will be loaded automatically). Whenever you add an alteration for a brand new component you need to run `php artisan view:clear`, as the template will need to be recompiled. Further changes will not require recompiling.

```php
use JackSleight\BladeTailor\Tailor;

Tailor::component('package::button')
    ->props([
        'size' => 'lg', // Customise prop defaults
        'huge' => false, // Add new props
    ])
    ->classes(fn ($variant, $huge) => [
        'border-4 rounded-full -rotate-2 !shadow-drop-1g',
        'scale-150' => $huge,
        match ($variant) {
            'primary' => 'border-blue-300',
            'danger' => 'border-red-800',
            default => '',
        },
    ]),
    ->attributes([ // Add new attributes
        'data-thing' => 'foo',
    ]);

// Target multiple components
Tailor::component(['package::*', 'other::input'])
    ->replace([ // Replace default classes
        'text-sm' => 'text-base',
    ])
    ->remove([ // Remove default classes
        'rounded',
    ])
    ->reset(true); // Remove all default classes

Tailor::component('package::card')
    ->root( // Customise root and slot elements
        classes: 'rounded-2xl',
        attributes: ['data-thing' => 'bar'],
    )
    ->slot('image',
        classes: 'rounded',
    )
    ->slot('text',
        classes: 'p-4',
    );
```

The remove, replace and reset options are not slot specific, they will apply to all slots.

You'll need to add any files where you're defining alterations to your Tailwind config's `content` array to ensure the compiler picks up the new classes:

```js
content: [
    // ...
    "./resources/tailor.php",
],
```

### Using Tailwind Merge

This package does not require [Tailwind Merge](https://github.com/gehrisandro/tailwind-merge-laravel) as a dependency, but if it's installed it will be used when merging your custom classes with the default ones.

### Tailoring External Components

It is possible to make non-tailorable components from external libraries tailorable as well with the intercept feature. To do that you will need to publish the config and then enable the `tailor.intercept` option.

> [!WARNING] 
> Intercept makes some minor changes to external component templates during compilation, in order to hook into their rendering processes. These are limited to the components you're tailoring but there may be edge cases that result in unexpected behaviour.

## Sponsoring 

This package is completely free to use. However fixing bugs, adding features and helping users takes time and effort. If you find this addon useful and would like to support its development any [contribution](https://github.com/sponsors/jacksleight) would be greatly appreciated. Thanks! 🙂
