# WP Hooks Generator

Generates a JSON representation of the WordPress actions and filters in your code. Can be used with WordPress plugins, themes, and core.

Note: If you just want the hook files without generating them yourself, use the following packages instead:

* [wp-hooks/wordpress-core](https://github.com/wp-hooks/wordpress-core) for WordPress core

## Requirements

PHP 8.3 or higher.

## Installation

```shell
composer require wp-hooks/generator
```

## Generating the Hook Files

```shell
./bin/wp-hooks-generator --input=src --output=hooks
```

## Usage of the Generated Hook Files in PHP

```php
// Get hooks as JSON:
$actions_json = file_get_contents( 'hooks/actions.json' );
$filters_json = file_get_contents( 'hooks/filters.json' );

// Convert hooks to PHP:
$actions = json_decode( $actions_json, true )['hooks'];
$filters = json_decode( $filters_json, true )['hooks'];

// Search for filters matching a string:
$search = 'permalink';
$results = array_filter( $filters, function( array $hook ) use ( $search ) {
    return ( strpos( $hook['name'], $search ) !== false );
} );

var_dump( $results );
```

## Usage of the Generated Hook Files in JavaScript

```js
// Get hooks as array of objects:
const actions = require('hooks/actions.json').hooks;
const filters = require('hooks/filters.json').hooks;

// Search for actions matching a string:
const search = 'menu';
const results = actions.filter( hook => ( hook.name.match( search ) !== null ) );

console.log(results);
```

## Ignoring Files or Directories

You can ignore files or directories in two ways:

### On the Command Line

    ./vendor/bin/wp-hooks-generator --input=src --output=hooks --ignore-files="ignore/this,ignore/that"

### In composer.json

```json
"extra": {
    "wp-hooks": {
        "ignore-files": [
            "ignore/this",
            "ignore/that"
        ]
    }
}
```

## Ignoring Hooks

You can ignore hooks in two ways:

### On the Command Line

    ./vendor/bin/wp-hooks-generator --input=src --output=hooks --ignore-hooks="this_hook,that_hook"

### In composer.json

```json
"extra": {
    "wp-hooks": {
        "ignore-hooks": [
            "this_hook",
            "that_hook"
        ]
    }
}
```

## Including Deprecated Hooks

Hooks fired via `do_action_deprecated()` and `apply_filters_deprecated()` are omitted by default. You can include them in two ways:

### On the Command Line

    ./vendor/bin/wp-hooks-generator --input=src --output=hooks --include-deprecated

### In composer.json

```json
"extra": {
    "wp-hooks": {
        "include-deprecated": true
    }
}
```

Deprecated hooks are written to `actions.json` and `filters.json` alongside the other hooks, with a type of `action_deprecated` or `filter_deprecated` so they can be identified:

```php
$deprecated = array_filter( $filters, function( array $hook ) : bool {
    return ( 'filter_deprecated' === $hook['type'] );
} );
```

They also carry the deprecation information passed to the function call:

* `deprecated_version`: The version the hook was deprecated in.
* `deprecated_replacement`: The name of the hook that should be used instead, if one was given.
* `deprecated_message`: The message that accompanies the deprecation notice, if one was given.

## TypeScript Interfaces for the Hook Files

The TypeScript interfaces for the hook files can be found in [`interface/index.d.ts`](interface/index.d.ts). Usage:

```typescript
import { Hooks, Hook, Doc, Tags, Tag } from 'hooks/index.d.ts';
```

## JSON Schema for the Hook Files

The JSON schema for the hook files can be found in [`schema.json`](schema.json).
