# wp-hooks-generator

Generates a list of WordPress actions and filters from code and outputs them in machine-readable JSON format. Can be used with WordPress plugins, themes, and core.

**Note:** This is still a work in progress. The code that generates the actions and filters data is not yet finalised.

Note: If you just want the built hook files, use the following packages instead:

* [`johnbillion/wp-hooks`](https://github.com/johnbillion/wp-hooks) for WordPress core

## Installation

`composer require johnbillion/wp-hooks-generator`

## Actions and Filters

* Actions can be found in [`hooks/actions.json`](hooks/actions.json).
* Filters can be found in [`hooks/filters.json`](hooks/filters.json).

## Usage of the Generated Hook Files in PHP

```php
// Get hooks as JSON:
$actions_json = file_get_contents( 'hook/actions.json' );
$filters_json = file_get_contents( 'hook/filters.json' );

// Get hooks as PHP:
$actions = json_decode( $actions_json, true );
$filters = json_decode( $filters_json, true );

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
const actions = require('hooks/actions.json');
const filters = require('hooks/filters.json');

// Search for actions matching a string:
const search = 'menu';
const results = actions.filter( hook => ( null !== hook.name.match( search ) ) );

console.log(results);
```

## TypeScript Interfaces for the Hook Files

The TypeScript interfaces for the hook files can be found in [`interface/index.d.ts`](interface/index.d.ts). Usage:

```typescript
import { Hooks, Hook, Doc, Tags, Tag } from '@johnbillion/wp-hooks/interface';
```

## JSON Schema for the Hook Files

The JSON schema for the hook files can be found in [`schema.json`](schema.json).

## Implementation Details

The hook extraction component of the [WP Parser library](https://github.com/WordPress/phpdoc-parser) is used to scan files in order to generate the hook data. WordPress nightly is used so hooks are always up to date.

## Regenerating the Hook Files

`composer generate`

## Validating the Hook Files

`composer validate-files`

## Regenerating the TypeScript Interfaces

`npm run generate-interfaces`
