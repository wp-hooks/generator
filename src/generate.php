#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace WPHooks\Generator;

use DOMDocument;
use Exception;
use Parsedown;
use phpDocumentor\Reflection\DocBlock\Tags\Deprecated;
use phpDocumentor\Reflection\DocBlock\Tags\Generic;
use phpDocumentor\Reflection\DocBlock\Tags\InvalidTag;
use phpDocumentor\Reflection\DocBlock\Tags\Link;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use phpDocumentor\Reflection\DocBlock\Tags\See;
use phpDocumentor\Reflection\DocBlock\Tags\Since;
use phpDocumentor\Reflection\DocBlock\Tags\Throws;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\Context;
use PhpParser;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\FindingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use UnexpectedValueException;

require_once file_exists( 'vendor/autoload.php' ) ? 'vendor/autoload.php' : dirname( __DIR__, 4 ) . '/vendor/autoload.php';

$options = getopt( '', [
	"input:",
	"output:",
	"ignore-files::",
	"ignore-hooks::",
] );

if ( empty( $options['input'] ) || empty( $options['output'] ) ) {
	fwrite(
		STDERR,
		sprintf(
			"Usage: %s --input=src --output=hooks [--ignore-files=ignore/this,ignore/that] [--ignore-hooks=this_hook,that_hook] \n",
			$argv[0],
		),
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
	$options['ignore-hooks'] = [
		'autocomplete_users_for_site_admins',
		'enable_edit_any_user_configuration',
		'show_recent_comments_widget_style',
	];
}

$source_dir = $options['input'];
$target_dir = $options['output'];
$ignore_files = $options['ignore-files'];
$ignore_hooks = $options['ignore-hooks'];

if ( ! file_exists( $source_dir ) ) {
	fwrite(
		STDERR,
		sprintf(
			"The source directory '%s' does not exist.\n",
			$source_dir,
		),
	);
	exit( 1 );
}

if ( ! file_exists( $target_dir ) ) {
	fwrite(
		STDERR,
		sprintf(
			"The target directory '%s' does not exist. Please create it first.\n",
			$target_dir,
		),
	);
	exit( 1 );
}

echo "Scanning for files...\n";

/**
 * @return list<non-empty-string>
 */
function get_wp_files( string $directory, array $ignore_files ) {
	$iterableFiles = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory ),
	);
	$files = array();

	foreach ( $iterableFiles as $file ) {
		if ( 'php' !== $file->getExtension() ) {
			continue;
		}

		$file_path = $file->getPathname();
		foreach ( $ignore_files as $key => $i ) {
			if ( false === strpos( $file_path, $i ) ) {
				continue;
			}

			// if we have a match, the next file will most likely also match this "ignore"
			// prepend to speed up
			if ( $key !== 0 ) {
				unset( $ignore_files[ $key ] );
				array_unshift( $ignore_files, $i );
			}

			continue 2;
		}

		$files[] = $file_path;
	}

	return $files;
}

$files = get_wp_files( $source_dir, $ignore_files );

printf(
	"Found %d files. Parsing hooks...\n",
	count( $files )
);

/**
 * Fixes newline handling in parsed text.
 *
 * DocBlock lines, particularly for descriptions, generally adhere to a given character width. For sentences and
 * paragraphs that exceed that width, what is intended as a manual soft wrap (via line break) is used to ensure
 * on-screen/in-file legibility of that text. These line breaks are retained by phpDocumentor. However, consumers
 * of this parsed data may believe the line breaks to be intentional and may display the text as such.
 *
 * This function fixes text by merging consecutive lines of text into a single line. A special exception is made
 * for text appearing in `<code>` and `<pre>` tags, as newlines appearing in those tags are always intentional.
 *
 * @param string $text
 *
 * @return string
 */
function fix_newlines( $text ) {
	// Non-naturally occurring string to use as temporary replacement.
	$replacement_string = '{{{{{}}}}}';

	// Replace newline characters within 'code' and 'pre' tags with replacement string.
	$text = preg_replace_callback(
		'/(?<=<pre><code>)(.+)(?=<\/code><\/pre>)/s',
		function ( $matches ) use ( $replacement_string ) {
			return preg_replace( '/[\n\r]/', $replacement_string, $matches[1] );
		},
		$text,
	);

	// Merge consecutive non-blank lines together by replacing the newlines with a space.
	$text = preg_replace(
		"/[\n\r](?!\s*[\n\r])/m",
		' ',
		$text,
	);

	// Restore newline characters into code blocks.
	$text = str_replace( $replacement_string, "\n", $text );

	return $text;
}

class DocblockFinderVisitor extends FindingVisitor {
	private ?Doc $latest_comment = null;

	public function enterNode( Node $node ) {
		$comment = $node->getDocComment();

		if ( $comment ) {
			$this->latest_comment = $comment;
		}

		$filterCallback = $this->filterCallback;
		if ( $filterCallback( $node ) ) {
			if ( $this->latest_comment && $this->latest_comment->getEndLine() + 1 === $node->getStartLine() ) {
				$node->setDocComment( $this->latest_comment );
			}

			$this->foundNodes[] = $node;
		}

		return null;
	}
}

/**
 * @param array<int,string> $files
 * @param string            $root
 * @param array<int,string> $ignore_hooks
 * @return array
 */
function hooks_parse_files( array $files, string $root, array $ignore_hooks ): array {
	$output = array();

	// Create a new parser instance
	$parser = ( new ParserFactory() )->createForNewestSupportedVersion();

	$funcs = [
		'do_action',
		'apply_filters',
		'do_action_ref_array',
		'apply_filters_ref_array',
		'do_action_deprecated',
		'apply_filters_deprecated',
	];

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
	];

	$single_event_functions_no_timestamp = [
		// WooCommerce action scheduler
		'as_enqueue_async_action',
	];

	$single_event_functions = array_merge( $single_event_functions, $single_event_functions_no_timestamp );

	$functions_regex = implode( '|', array_merge( $funcs, $event_functions, $single_event_functions ) );

	foreach ( $files as $filename ) {
		// Parse the PHP file
		$contents = file_get_contents( $filename );

		if ( $contents === false ) {
			throw new Exception( 'Failed to read file ' . $filename );
		}

		// if the file doesn't contain a relevant function we can skip it - reduces runtime massively
		if ( preg_match( '/\b(?:' . $functions_regex . ')\s*\([^)]/', $contents ) === 0 ) {
			continue;
		}

		$stmts = $parser->parse( $contents );

		if ( ! is_array( $stmts ) ) {
			throw new Exception( 'Failed to parse file ' . $filename );
		}

		// Create a new FindingVisitor instance
		$visitor = new DocblockFinderVisitor(
			fn ( Node $node ) => (
				$node instanceof Node\Expr\FuncCall ||
				$node instanceof Node\Stmt\Namespace_ ||
				$node instanceof Node\Stmt\GroupUse ||
				$node instanceof Node\Stmt\Use_
			),
		);

		// Traverse the AST and resolve names
		$traverser = new NodeTraverser();
		$traverser->addVisitor( $visitor );
		$traverser->traverse( $stmts );

		$found = $visitor->getFoundNodes();

		$namespace = '';
		$use_statements = [];
		$context = null;

		// Process the parsed statements to find calls to do_action() and apply_filters()
		foreach ( $found as $node ) {
			if ( $node instanceof Node\Stmt\Namespace_ ) {
				// as soon as there is a new namespace, we need to empty the useStatements, as there will be new ones for the given namespace
				if ( isset( $node->name ) ) {
					$namespace = $node->name->toString();
				} else {
					// back to global namespace
					$namespace = '';
				}

				$use_statements = [];
				$context = null;
				continue;
			}

			$fqcnPrefix = '';

			// like "use superWC\special\{Order, Extra\Refund, User};"
			if ( $node instanceof Node\Stmt\GroupUse ) {
				// some have unnecessary leading \ in them, we need to remove first
				// cannot be empty, as that is not valid in PHP GroupUse
				// also directly add trailing backslash to simplify later processing
				$fqcnPrefix = ltrim( $node->prefix->toString(), '\\' ) . '\\';
			}

			// normal "use" statements
			// "use" for traits is a different class, so it will correctly not be handled here
			// can still be multiple, e.g. "use Foo, Bar;"
			// GroupUse can be processed the same way
			if ( $node instanceof Node\Stmt\Use_ || $node instanceof Node\Stmt\GroupUse ) {
				// constant and function "use" statements are irrelevant, we are only interested in class imports
				if ( in_array( $node->type, array( 2, 3 ), true ) ) {
					continue;
				}

				foreach ( $node->uses as $useObject ) {
					if ( in_array( $node->type, array( 2, 3 ), true ) ) {
						continue;
					}

					$fqcn = $useObject->name->toString();

					// some have invalid leading \ in them, we need to remove first
					$fqcn = $fqcnPrefix . ltrim( $fqcn, '\\' );

					// technically not needed, since the namespace is included in the stubs
					// changing it anyway, to make things easier to follow/read, as the stubs are a massive file usually
					$fqcn = ltrim( preg_replace( '/^namespace\\\\/', addcslashes( $namespace . '\\', '\\' ), $fqcn, 1 ), '\\' );

					// class names are unique, so we use them as keys of the array
					if ( ! empty( $useObject->alias->name ) ) {
						$use_statements[ $useObject->alias->name ] = $fqcn;
					} else {
						$use_statements[ $useObject->name->getLast() ] = $fqcn;
					}
				}

				// we cannot skip children (UseItem), since it needs it to automatically adjust native type hints,... but we won't process it again for docblock
				continue;
			}

			if ( ! $node instanceof Node\Expr\FuncCall ) {
				continue;
			}

			$funcName = $node->name;

			if ( ! ( $funcName instanceof Node\Name ) ) {
				continue;
			}

			$funcNameStr = $funcName->toString();

			// similar code used in psalm-plugin-wordpress
			$hook_index = 0;
			$hook_type = false;
			if ( in_array( $funcNameStr, $event_functions, true ) ) {
				$hook_type = 'cron-action';
				// the 3rd arg (index key 2) is the hook name
				$hook_index = 2;
			} elseif ( in_array( $funcNameStr, $single_event_functions, true ) ) {
				$hook_type = 'cron-action';
				$hook_index = 1;

				if ( in_array( $funcNameStr, $single_event_functions_no_timestamp, true ) ) {
					$hook_index = 0;
				}
			} elseif ( ! in_array( $funcNameStr, $funcs, true ) ) {
				continue;
			}

			$docblock = $node->getDocComment();

			if ( $docblock && str_starts_with( $docblock->getText(), '/** This action is documented in' ) ) {
				continue;
			}

			if ( $docblock && str_starts_with( $docblock->getText(), '/** This filter is documented in' ) ) {
				continue;
			}

			$normalized_hook_name = get_formatted_hook_name( $node->args[ $hook_index ]->value );

			if ( in_array( $normalized_hook_name, $ignore_hooks, true ) ) {
				continue;
			}

			$printer = new Standard();
			$hook_name = $printer->prettyPrintExpr( $node->args[ $hook_index ]->value );
			$hook_name = preg_replace( '/^([\'"])(.*)\1$/', '$2', $hook_name, 1 );

			$dbt = '/** */';
			if ( ! ( $docblock instanceof Doc ) ) {
				if ( $funcNameStr !== 'do_action' || count( $node->args ) > 1 ) {
					fwrite(
						STDERR,
						sprintf(
							"Hook '%s' in file '%s' is missing a docblock.\n",
							$hook_name,
							$filename,
						),
					);
				}
			} elseif ( $docblock->getText() === '' ) {
				fwrite(
					STDERR,
					sprintf(
						"Hook '%s' in file '%s' has an empty docblock.\n",
						$hook_name,
						$filename,
					),
				);
			} else {
				$dbt = $docblock->getText();
			}

			$doc = [
				'description'           => '',
				'long_description'      => '',
				'tags'                  => [],
				'long_description_html' => '',
			];

			if ( is_null( $context ) ) {
				$context = new Context( $namespace, $use_statements );
			}

			$dbf = DocBlockFactory::createInstance();
			$db = $dbf->create( $dbt, $context );

			// prevent leftover "/*" for docblocks like "/* */"
			$summary = preg_replace( '#^/\*#', '', trim( $db->getSummary() ), 1 );
			$tags = [];

			$documented_params_count = 0;
			foreach ( $db->getTags() as $tag ) {
				$content = '';

				if ( ! method_exists( $tag, 'getVersion' ) && method_exists( $tag, 'getDescription' ) ) {
					$content = (string) $tag->getDescription();
					$content = preg_replace( '#\n\s+#', ' ', $content );
				}

				$tag_data = [
					'name' => $tag instanceof Var_ || $tag instanceof Return_ ? 'param' : $tag->getName(),
					'content' => fix_newlines( $content ),
				];

				if ( $tag instanceof Return_ ) {
					fwrite(
						STDERR,
						sprintf(
							"Hook '%s' in file '%s' contains a @return tag, which is not supported.\n",
							$hook_name,
							$filename,
						),
					);
				}

				if ( $tag instanceof InvalidTag && $tag->getName() === 'since' ) {
					$tag_data['content'] = (string) $tag;
					if ( $tag_data['content'] !== '' && str_contains( $tag_data['content'], ' ' ) ) {
						$tag_data['description'] = ltrim( strstr( $tag_data['content'], ' ' ) );
						$tag_data['content'] = strtok( $tag_data['content'], ' ' );
					}
				} elseif ( $tag instanceof Since ) {
					// Version string.
					$version = $tag->getVersion();

					if ( ! empty( $version ) ) {
						$tag_data['content'] = $version;
					}

					// Description string.
					$description = preg_replace( '/[\n\r]+/', ' ', strval( $tag->getDescription() ) );

					if ( ! empty( $description ) ) {
						$markdown = Parsedown::instance();
						$html = $markdown->text( $description );
						$html = preg_replace( '/^<p>(.*)<\/p>$/', '$1', $html );
						$tag_data['description'] = $html;
					}
				} elseif ( $tag instanceof Deprecated ) {
					$tag_data['content'] = (string) $tag;
					if ( $tag_data['content'] !== '' && str_contains( $tag_data['content'], ' ' ) ) {
						$tag_data['description'] = ltrim( strstr( $tag_data['content'], ' ' ) );
						$tag_data['content'] = strtok( $tag_data['content'], ' ' );
					}
				} elseif (
					$tag instanceof Param ||
					$tag instanceof Var_ ||
					(
						$documented_params_count === 0 &&
						$tag instanceof Return_
					)
				) {
					++$documented_params_count;

					$tag_data['types'] = [];
					if ( $tag->getType() instanceof Compound ) {
						foreach ( $tag->getType()->getIterator() as $type ) {
							$tag_data['types'][] = (string) $type;
						}
					} else {
						$tag_data['types'][] = (string) $tag->getType();
					}

					$tag_data['variable'] = $tag instanceof Return_ || $tag->getVariableName() === '' ? '' : '$' . $tag->getVariableName();


					$markdown = Parsedown::instance();
					$html = $markdown->text( $tag_data['content'] );
					$html = preg_replace( '/^<p>(.*)<\/p>$/', '$1', $html );
					$tag_data['content'] = $html;
				} elseif ( $tag instanceof Link ) {
					$link = $tag->getLink();
					$tag_data['content'] = sprintf(
						'<a href="%s">%s</a>',
						$link,
						$link,
					);
					$tag_data['link'] = $link;
				} elseif ( $tag instanceof Generic ) {
					//
				} elseif ( $tag instanceof See ) {
					$tag_data['refers'] = ltrim( (string) $tag->getReference(), '\\' );
					$markdown = Parsedown::instance();
					$html = $markdown->text( $tag_data['content'] );
					$html = preg_replace( '/^<p>(.*)<\/p>$/', '$1', $html );
					$tag_data['content'] = $html;
				} elseif ( $tag instanceof InvalidTag && $tag->getName() === 'see' ) {
					//
				} elseif ( $tag instanceof Throws ) {
					//
				} elseif ( $tag instanceof Return_ ) {
					// don't add it either
					continue;
				} elseif (
					$tag instanceof InvalidTag &&
					$tag->getException() &&
					get_class( $tag->getException() ) === 'InvalidArgumentException' &&
					str_contains( $tag->getException()->getMessage(), 'Could not find type' )
				) {
					fwrite(
						STDERR,
						sprintf(
							"%s for @%s for hook '%s' in file '%s'.\n",
							$message,
							$tag->getName(),
							$hook_name,
							$filename,
						),
					);

					continue 2;
				} elseif ( $tag instanceof InvalidTag && $tag->getException() ) {
					throw new Exception(
						sprintf(
							'"%s" for @%s for hook "%s" in file "%s".',
							$tag->getException()->getMessage(),
							$tag->getName(),
							$hook_name,
							$filename,
						),
						$tag->getException()->getCode(),
						$tag->getException(),
					);
				} else {
					throw new Exception(
						sprintf(
							'Unknown tag type "%s" (@%s) for hook "%s" in file "%s".',
							get_class( $tag ),
							$tag->getName(),
							$hook_name,
							$filename,
						),
					);
				}

				$tags[] = $tag_data;
			}

			$markdown = Parsedown::instance();
			$html = $markdown->text( (string) $db->getDescription() );
			$html = str_replace( "\n", ' ', $html );
			$long = fix_newlines( (string) $db->getDescription() );
			$long = str_replace(
				'  - ',
				"\n  - ",
				$long,
			);
			$long = preg_replace_callback(
				'# ([1-9])\. #',
				static function ( array $matches ): string {
					return "\n {$matches[1]}. ";
				},
				$long,
			);
			$doc = [
				'description'           => str_replace( "\n", ' ', $summary ),
				'long_description'      => $long,
				'tags'                  => $tags,
				'long_description_html' => $html,
			];
			$out = [];

			$out['name'] = $normalized_hook_name;

			$aliases = parse_aliases( $html );

			if ( $aliases ) {
				$out['aliases'] = $aliases;
			}

			$out['file'] = str_replace( "{$root}/", '', $filename );

			switch ( $funcNameStr ) {
				case 'do_action':
				default:
					$out['type'] = 'action';
					break;
				case 'apply_filters':
					$out['type'] = 'filter';
					break;
				case 'do_action_ref_array':
					$out['type'] = 'action_reference';
					break;
				case 'apply_filters_ref_array':
					$out['type'] = 'filter_reference';
					break;
				case 'do_action_deprecated':
					$out['type'] = 'action_deprecated';
					break;
				case 'apply_filters_deprecated':
					$out['type'] = 'filter_deprecated';
					break;
			}

			$out['doc'] = $doc;

			// we cannot count( $node->args ) - 1, since this is wrong for do_action_ref_array or cron actions in all cases except where the array literally has 1 element
			if ( $documented_params_count > 0 ) {
				$out['args'] = $documented_params_count;

				// only possible for cases where the args are not passed as a single array
				$expect_params = count( $node->args ) - $hook_index - 1;
				if (
					in_array( $funcNameStr, [ 'do_action', 'apply_filters' ] ) &&
					$documented_params_count !== count( $node->args ) - $hook_index - 1
				) {
					fwrite(
						STDERR,
						sprintf(
							"Hook '%s' in file '%s' has %d documented params, but %d params found.\n",
							$hook_name,
							$filename,
							$documented_params_count,
							$expect_params,
						),
					);
				}
			} elseif ( in_array( $funcNameStr, [ 'do_action', 'apply_filters' ] ) ) {
				$out['args'] = count( $node->args ) - 1;
			} elseif (
				isset( $node->args[ $hook_index + 1 ] )
				&& $node->args[ $hook_index + 1 ] instanceof PhpParser\Node\Arg &&
				$node->args[ $hook_index + 1 ]->value instanceof PhpParser\Node\Expr\Array_
			) {
				$out['args'] = count( $node->args[ $hook_index + 1 ]->value->items );

				// we could possibly static analysis infer the types here like in psalm-plugin-wordpress, however it's usually worse, since we only check 1 file and have less type info
				// better to infer it directly in psalm if needed at all
			} elseif ( ! isset( $node->args[ $hook_index + 1 ] ) ) {
				$out['args'] = 0;
			} else {
				// if we want to be absolutely safe, it should be 0, however in 99% of cases it does not make sense for these to not have any argument
				$out['args'] = 1;
			}

			// only if we didn't already report an error for empty/missing docblock
			if ( $dbt !== '/** */' && $out['args'] !== 0 && $documented_params_count === 0 ) {
				fwrite(
					STDERR,
					sprintf(
						"Hook '%s' in file '%s' is missing @param for all arguments.\n",
						$hook_name,
						$filename,
					),
				);
			}

			// ignore fully dynamic, generic hooks too, since there's no useful info to be gained from them
			// only down here, since we still want to get errors reported for it
			if ( $normalized_hook_name === '{$var}' ) {
				continue;
			}

			$output[] = $out;
		}
	}

	usort( $output, function ( array $a, array $b ): int {
		if ( $a['name'] === $b['name'] ) {
			return strlen( $a['file'] ) - strlen( $b['file'] );
		}

		return strcmp( $a['name'], $b['name'] );
	} );

	return $output;
}

/**
 * @return array<int, string>
 */
function parse_aliases( string $html ): array {
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
	return in_array( $hook['type'], [ 'action', 'action_reference', 'action_deprecated' ], true );
} ) );

$actions = [
	'$schema' => 'https://raw.githubusercontent.com/wp-hooks/generator/1.0.1/schema.json',
	'hooks' => $actions,
];

$result = file_put_contents( $target_dir . '/actions.json', json_encode( $actions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

// Filters
$filters = array_values( array_filter( $output, function( array $hook ) : bool {
	return in_array( $hook['type'], [ 'filter', 'filter_reference', 'filter_deprecated' ], true );
} ) );

$filters = [
	'$schema' => 'https://raw.githubusercontent.com/wp-hooks/generator/1.0.1/schema.json',
	'hooks' => $filters,
];

$result = file_put_contents( $target_dir . '/filters.json', json_encode( $filters, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

echo "Done\n";

/**
 * recreate the original hooks format used by wp-parser-lib
 * see psalm-plugin-wordpress Plugin::getDynamicHookName())
 */
function get_formatted_hook_name( object $arg, bool $keep_var_name = false ): string {
	// variable or 'foo' . $bar variable hook name
	// "foo_{$my_var}_bar" is the wp-hooks-generator style, we need to mimic
	if ( $arg instanceof PhpParser\Node\Expr\Variable ) {
		if ( $keep_var_name === false ) {
			// avoid false positive errors caused by just a variable, since it's too generic
			// this is filtered out later on
			return '{$var}';
		}

		return '{$' . $arg->name . '}';
	}

	if ( $arg instanceof PhpParser\Node\Scalar\Encapsed || $arg instanceof PhpParser\Node\Scalar\InterpolatedString ) {
		$hook_name = '';
		foreach ( $arg->parts as $part ) {
			$hook_name .= get_formatted_hook_name( $part, true );
		}

		return $hook_name;
	}

	if ( $arg instanceof PhpParser\Node\Expr\BinaryOp\Concat ) {
		$hook_name = get_formatted_hook_name( $arg->left, true );
		$hook_name .= get_formatted_hook_name( $arg->right, true );

		return $hook_name;
	}

	if ( $arg instanceof PhpParser\Node\Scalar\String_ || $arg instanceof PhpParser\Node\Scalar\EncapsedStringPart || $arg instanceof PhpParser\Node\InterpolatedStringPart || $arg instanceof PhpParser\Node\Scalar\LNumber || $arg instanceof PhpParser\Node\Scalar\Int_ ) {
		return $arg->value;
	}

	if ( $arg instanceof PhpParser\Node\Scalar\MagicConst\Function_ ) {
		// __FUNCTION__
		return '{$var}';
	}

	if ( $arg instanceof PhpParser\Node\Expr\StaticPropertyFetch ) {
		// e.g. self::$foo
		if ( $arg->class instanceof PhpParser\Node\Name ) {
			$class_name = $arg->class->name;
		} else {
			$class_name = trim( get_formatted_hook_name( $arg->class, true ), '{}' );
		}

		return '{' . $class_name . '::$' . $arg->name->toString() . '}';
	}

	if ( $arg instanceof PhpParser\Node\Expr\StaticCall || $arg instanceof PhpParser\Node\Expr\ClassConstFetch ) {
		if ( ! $arg->name instanceof PhpParser\Node\Identifier ) {
			throw new UnexpectedValueException( 'Unsupported dynamic hook name with name type ' . get_class( $arg->name ) . ' on line ' . $arg->getStartLine(), 0 );
		}

		// hook name with Foo::bar()
		if ( $arg->class instanceof PhpParser\Node\Name ) {
			$class_name = $arg->class->name;
		} else {
			$class_name = trim( get_formatted_hook_name( $arg->class, true ), '{}' );
		}

		$append_method_call = $arg instanceof PhpParser\Node\Expr\StaticCall ? '()' : '';

		return '{' . $class_name . '::' . $arg->name->toString() . $append_method_call . '}';
	}

	if ( $arg instanceof PhpParser\Node\Expr\PropertyFetch || $arg instanceof PhpParser\Node\Expr\MethodCall ) {
		if ( ! $arg->name instanceof PhpParser\Node\Identifier ) {
			throw new UnexpectedValueException( 'Unsupported dynamic hook name with name type ' . get_class( $arg->name ) . ' on line ' . $arg->getStartLine(), 0 );
		}

		// need to check recursively
		$temp = get_formatted_hook_name( $arg->var, true );

		$append_method_call = $arg instanceof PhpParser\Node\Expr\MethodCall ? '()' : '';

		return rtrim( $temp, '}' ) . '->' . $arg->name->toString() . $append_method_call . '}';
	}

	if ( $arg instanceof PhpParser\Node\Expr\FuncCall ) {
		// mostly relevant for add_action - can just assume any variable name without using the function name, since it's useless (e.g. basename, dirname,... are common ones)
		return '{$var}';
	}

	if ( $arg instanceof PhpParser\Node\Expr\ArrayDimFetch ) {
		$key_hook_name = get_formatted_hook_name( $arg->dim, true );

		// need to check recursively
		$temp = get_formatted_hook_name( $arg->var, true );

		if ( $key_hook_name[0] === '{' ) {
			$key = trim( $key_hook_name, '{}' );
		} elseif ( is_numeric( $key_hook_name ) ) {
			$key = $key_hook_name;
		} else {
			$key = "'" . $key_hook_name . "'";
		}

		return rtrim( $temp, '}' ) . '[' . $key . ']}';
	}

	if ( $arg instanceof PhpParser\Node\Expr\ConstFetch ) {
		return '{$var}';
	}

	// other types not supported yet
	// add handling if encountered
	throw new UnexpectedValueException( 'Unsupported dynamic hook name with type ' . get_class( $arg ) . ' on line ' . $arg->getStartLine(), 0 );
}
