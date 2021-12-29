# wp-hooks-generator

Generates a JSON representation of the WordPress actions and filters in your code. Can be used with WordPress plugins, themes, and core.

Note: If you just want the hook files without generating them yourself, use the following packages instead:

* [johnbillion/wp-hooks](https://github.com/johnbillion/wp-hooks) for WordPress core

## Installation

    composer require johnbillion/wp-hooks-generator

## Generating the Hook Files

    ./bin/wp-hooks-generator --input=src --output=hooks

## Usage of the Generated Hook Files in PHP

```php
// Get hooks as JSON:
$actions_json = file_get_contents( 'hook/actions.json' );
$filters_json = file_get_contents( 'hook/filters.json' );

// Convert hooks to PHP:
$actions = json_decode( $actions_json, true )['hooks'];
$filters = json_decode( $filters_json, true )['hooks'];

// Search for filters matching a string:
$search = 'permalink';
$results = array_filter( $filters, function( array $hook ) use ( $search ) {
    return ( false !== strpos( $hook['name'], $search ) );
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
const results = actions.filter( hook => ( null !== hook.name.match( search ) ) );

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

## TypeScript Interfaces for the Hook Files

The TypeScript interfaces for the hook files can be found in [`interface/index.d.ts`](interface/index.d.ts). Usage:

```typescript
import { Hooks, Hook, Doc, Tags, Tag } from 'hooks/index.d.ts';
```

## JSON Schema for the Hook Files

The JSON schema for the hook files can be found in [`schema.json`](schema.json).
