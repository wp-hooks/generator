#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace JohnBillion\WPHooksGenerator;

use DOMDocument;

require_once file_exists( 'vendor/autoload.php' ) ? 'vendor/autoload.php' : dirname( __DIR__, 4 ) . '/vendor/autoload.php';

$options = getopt( '', [
	"input:",
	"output:",
	"ignore-files::",
	"ignore-hooks::",
] );

if ( empty( $options['input' ] ) || empty( $options['output'] ) ) {
	printf(
		"Usage: %s --input=src --output=hooks [--ignore-files=ignore/this,ignore/that] [--ignore-hooks=this_hook,that_hook] \n",
		$argv[0]
	);
	exit( 1 );
}

// Read ignore-files from cli args:
if ( ! empty( $options['ignore-files'] ) ) {
	$options['ignore-files'] = explode( ',', $options['ignore-files'] );
}

// Read ignore-hooks from cli args:
if ( ! empty( $options['ignore-hooks'] ) ) {
	$options['ignore-hooks'] = explode( ',', $options['ignore-hooks'] );
}

$config = ( file_exists( 'composer.json' ) ? json_decode( file_get_contents( 'composer.json' ) ) : false );

if ( ! empty( $config ) && ! empty( $config->extra ) && ! empty( $config->extra->{"wp-hooks"} ) ) {
	// Read ignore-files from Composer config:
	if ( empty( $options['ignore-files'] ) && ! empty( $config->extra->{"wp-hooks"}->{"ignore-files"} ) ) {
		$options['ignore-files'] = array_values( $config->extra->{"wp-hooks"}->{"ignore-files"} );
	}

	// Read ignore-hooks from Composer config:
	if ( empty( $options['ignore-hooks'] ) && ! empty( $config->extra->{"wp-hooks"}->{"ignore-hooks"} ) ) {
		$options['ignore-hooks'] = array_values( $config->extra->{"wp-hooks"}->{"ignore-hooks"} );
	}
}

if ( empty( $options['ignore-files'] ) ) {
	$options['ignore-files'] = [];
}

if ( empty( $options['ignore-hooks'] ) ) {
	$options['ignore-hooks'] = [];
}

$source_dir = $options['input'];
$target_dir = $options['output'];
$ignore_files = $options['ignore-files'];
$ignore_hooks = $options['ignore-hooks'];

if ( ! file_exists( $source_dir ) ) {
	printf(
		'The source directory "%s" does not exist.' . "\n",
		$source_dir
	);
	exit( 1 );
}

if ( ! file_exists( $target_dir ) ) {
	printf(
		'The target directory "%s" does not exist. Please create it first.' . "\n",
		$target_dir
	);
	exit( 1 );
}

echo "Scanning for files...\n";

/** @var array<int,string> */
$files = \WP_Parser\get_wp_files( $source_dir );
$files = array_values( array_filter( $files, function( string $file ) use ( $ignore_files ) : bool {
	foreach ( $ignore_files as $i ) {
		if ( false !== strpos( $file, $i ) ) {
			return false;
		}
	}

	return true;
} ) );

printf(
	"Found %d files. Parsing hooks...\n",
	count( $files )
);

/**
 * @param array<int,string> $files
 * @param string            $root
 * @param array<int,string> $ignore_hooks
 * @return array
 */
function hooks_parse_files( array $files, string $root, array $ignore_hooks ) : array {
	$output = array();

	foreach ( $files as $filename ) {
		if ( !is_readable( $filename ) ) {
			continue;
		}
		$file = new \WP_Parser\File_Reflector( $filename );
		$file_hooks = [];
		$path = ltrim( substr( $filename, strlen( $root ) ), DIRECTORY_SEPARATOR );
		$file->setFilename( $path );

		// should throw things, but for some reason returns errors instead, so we just collect them manually
		ob_start();
		$file->process();
		$processing_errors = ob_get_clean();
		if ( !empty( $processing_errors ) ) {
			fwrite( STDERR, $filename . PHP_EOL );
			fwrite( STDERR, $processing_errors . PHP_EOL );
		}

		if ( ! empty( $file->uses['hooks'] ) ) {
			$file_hooks = array_merge( $file_hooks, export_hooks( $file->uses['hooks'], $path ) );
		}

		if ( ! empty( $file->uses['functions'] ) ) {
			$file_hooks = array_merge( $file_hooks, export_scheduled_hooks( $file->uses['functions'], $path ) );
		}

		foreach ( $file->getFunctions() as $function ) {
			if ( ! empty( $function->uses ) && ! empty( $function->uses['hooks'] ) ) {
				$file_hooks = array_merge( $file_hooks, export_hooks( $function->uses['hooks'], $path ) );
			}

			if ( ! empty( $function->uses ) && ! empty( $function->uses['functions'] ) ) {
				$file_hooks = array_merge( $file_hooks, export_scheduled_hooks( $function->uses['functions'], $path ) );
			}
		}

		foreach ( $file->getClasses() as $class ) {
			foreach ( $class->getMethods() as $method ) {
				if ( ! empty( $method->uses ) && ! empty( $method->uses['hooks'] ) ) {
					$file_hooks = array_merge( $file_hooks, export_hooks( $method->uses['hooks'], $path ) );
				}

				if ( ! empty( $method->uses ) && ! empty( $method->uses['functions'] ) ) {
					$file_hooks = array_merge( $file_hooks, export_scheduled_hooks( $method->uses['functions'], $path ) );
				}
			}
		}

		$output = array_merge( $output, $file_hooks );
	}

	$output = array_filter( $output, function( array $hook ) use ( $ignore_hooks ) : bool {
		if ( ! empty( $hook['doc'] ) && ! empty( $hook['doc']['description'] ) ) {
			if ( 0 === strpos( $hook['doc']['description'], 'This filter is documented in ' ) ) {
				return false;
			}
			if ( 0 === strpos( $hook['doc']['description'], 'This action is documented in ' ) ) {
				return false;
			}
		}

		if ( in_array( $hook['name'], $ignore_hooks, true ) ) {
			return false;
		}

		return true;
	} );

	usort( $output, function( array $a, array $b ) : int {
		return strcmp( $a['name'], $b['name'] );
	} );

	return $output;
}

/**
 * @param \WP_Parser\Function_Call_Reflector[] $nodes Array of hook references.
 * @param string                               $path  The file path.
 * @return array<int,array<string,mixed>>
 */
function export_scheduled_hooks( array $nodes, string $path ) : array {
	$out = array();

	$event_functions = [
		'wp_schedule_event',
		// WooCommerce action scheduler
		'as_schedule_recurring_action',
		'as_schedule_cron_action',
	];

	$single_event_functions = [
		'wp_schedule_single_event',
		// WooCommerce action scheduler
		'as_schedule_single_action',
		'as_enqueue_async_action',
	];

	foreach ( $nodes as $wp_node ) {
		if ( !$wp_node instanceof \WP_Parser\Function_Call_Reflector ) {
			continue;
		}

		$origNode = $wp_node->getNode();

		// same code used in psalm-plugin-wordpress
		if ( $origNode instanceof \PhpParser\Node\Expr\FuncCall && $origNode->name instanceof \PhpParser\Node\Name ) {
			if ( in_array( (string) $origNode->name, $event_functions, true ) ) {
				$hook_type = 'cron-action';
				// the 3rd arg (index key 2) is the hook name
				$hook_index = 2;
			} elseif ( in_array( (string) $origNode->name, $single_event_functions, true ) ) {
				$hook_type = 'cron-action';
				$hook_index = 1;

				if ( (string) $origNode->name === 'as_enqueue_async_action' ) {
					$hook_index = 0;
				}
			} else {
				continue;
			}

			if ( ! $origNode->args[ $hook_index ]->value instanceof \PhpParser\Node\Scalar\String_ ) {
				continue;
			}

			$out[] = array(
				'name'     => $origNode->args[ $hook_index ]->value->value,
				'file'     => $path,
				'type'     => 'action',
				'doc'      => array(
					'description' =>'',
					'long_description' => '',
					'tags' => array(),
					'long_description_html' => '',
				),
				'args'     => 0,
			);
		}
	}

	return $out;
}

/**
 * @param \WP_Parser\Hook_Reflector[] $hooks Array of hook references.
 * @param string                      $path  The file path.
 * @return array<int,array<string,mixed>>
 */
function export_hooks( array $hooks, string $path ) : array {
	$out = array();

	foreach ( $hooks as $hook ) {
		$doc      = \WP_Parser\export_docblock( $hook );
		$docblock = $hook->getDocBlock();

		$doc['long_description_html'] = $doc['long_description'];

		if ( $docblock ) {
			$doc['long_description'] = \WP_Parser\fix_newlines( $docblock->getLongDescription() );
			$doc['long_description'] = str_replace(
				'  - ',
				"\n  - ",
				$doc['long_description']
			);
			$doc['long_description'] = preg_replace_callback(
				'# ([1-9])\. #',
				function( array $matches ) : string {
					return "\n {$matches[1]}. ";
				},
				$doc['long_description']
			);

			foreach ( $docblock->getTags() as $i => $tag ) {
				$content = '';

				if ( ! method_exists( $tag, 'getVersion' ) ) {
					$content = $tag->getDescription();
					$content = \WP_Parser\format_description( preg_replace( '#\n\s+#', ' ', $content ) );
				}

				if ( empty( $content ) ) {
					continue;
				}

				$doc['tags'][ $i ]['content'] = $content;
			}
		} else {
			$doc['long_description'] = '';
		}

		$aliases = parse_aliases( $doc['long_description_html'] );

		$result = [];

		$result['name'] = $hook->getName();

		if ( $aliases ) {
			$result['aliases'] = $aliases;
		}

		$result['file'] = $path;
		$result['type'] = $hook->getType();
		$result['doc'] = $doc;
		$result['args'] = count( $hook->getNode()->args ) - 1;

		$out[] = $result;
	}

	return $out;
}

/**
 * @return array<int, string>
 */
function parse_aliases( string $html ) : array {
	if ( false === strpos( $html, 'Possible hook names include' ) ) {
		return [];
	}

	$aliases = [];

	$html = explode( 'Possible hook names include', $html, 2 );
	$html = explode( '</ul>', end( $html ) );

	$dom = new DOMDocument();
	$dom->loadHTML( reset( $html ) );

	foreach ( $dom->getElementsByTagName( 'li' ) as $li ) {
		$aliases[] = $li->nodeValue;
	}

	sort( $aliases );

	return $aliases;
}

$output = hooks_parse_files( $files, $source_dir, $ignore_hooks );

// Actions
$actions = array_values( array_filter( $output, function( array $hook ) : bool {
	return in_array( $hook['type'], [ 'action', 'action_reference' ], true );
} ) );

$actions = [
	'$schema' => 'https://raw.githubusercontent.com/wp-hooks/generator/0.9.0/schema.json',
	'hooks' => $actions,
];

$result = file_put_contents( $target_dir . '/actions.json', json_encode( $actions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

// Filters
$filters = array_values( array_filter( $output, function( array $hook ) : bool {
	return in_array( $hook['type'], [ 'filter', 'filter_reference' ], true );
} ) );

$filters = [
	'$schema' => 'https://raw.githubusercontent.com/wp-hooks/generator/0.9.0/schema.json',
	'hooks' => $filters,
];

$result = file_put_contents( $target_dir . '/filters.json', json_encode( $filters, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

echo "Done\n";
