# Blade Tailor

Blade Tailor allows you to customise (tailor) the default props, classes and attributes used by blade components without having to publish them. This is particularly useful for theming components from external packages. If you have a library of your own re-usable components you can also make those tailorable by using the provided directive and attribute method.

> [!WARNING] 
> This package needs to alter external component templates during compilation in order to hook into their rendering processes. [The changes it makes](https://github.com/jacksleight/blade-tailor/blob/815a1dd08602556bbdc3293041d16690b0038a12/src/TailorManager.php#L161-L171) are very minor and limited to the components you're tailoring, but there may be edge cases that result in unexpected behaviour.

## Installation

Run the following command from your project root:

```bash
composer require jacksleight/blade-tailor
```

## Usage

### Tailoring Components

Tailoring components is done via the `Tailor::component()` method. You can either make these calls in a service provider or create a dedicated file for your customisations in `resources/tailor.php` (this will be loaded automatically). Whenever you add an alteration for a brand new component you need to run `php artisan view:clear`, as the template will need to be recompiled. Further changes will not require recompiling.

```php
use JackSleight\BladeTailor\Tailor;

Tailor::component('flux::button')
    ->props([
        'variant' => 'primary', // Customise prop defaults
        'huge' => false, // Add new props
    ])
    ->classes(fn ($variant, $huge) => [
        'border-4 rounded-full -rotate-2 !shadow-drop-1g',
        'scale-150' => $huge,
        '[&>[data-flux-icon]]:: text-orange-500 size-10 -mx-2 mb-0.5 self-end',
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
Tailor::component(['flux::button', 'core::*'])
    ->replace([ // Replace default classes
        'text-sm' => 'text-base',
    ])
    ->remove([ // Remove default classes
        'rounded',
    ])
    ->reset(true); // Remove all default classes

Tailor::component('core::card')
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

You'll need to add any files where you're defining alterations to your Tailwind config's `content` array to ensure the compiler picks up the new classes:

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

Technicailly this isn't really necessary, as the package will attempt to make these changes itself if it detects a tailored component, however doing it explicitly is more robust.

### Using Tailwind Merge

This package does not require [Tailwind Merge](https://github.com/gehrisandro/tailwind-merge-laravel) as a dependency, but if it's installed it will be used when merging your custom classes with the default ones.

### Using Tailwind Variant Shorthand

The tailor method supports a custom shorthand for specifying Tailwind variant classes:

```
'hover:: text-orange-500 underline' -> 'hover:text-orange-500 hover:underline'
```

To make this work with the Tailwind compiler you'll need to add a custom extract method for PHP files to your Tailwind config, which you can import from this package:

```js
import { variantExtract } from './vendor/jacksleight/blade-tailor/tailwind.helpers.js';

export default {
    content: {
        files: [
            // ...
        ],
        extract: {
            php: variantExtract,
        },
    },
    // ...
}
```
