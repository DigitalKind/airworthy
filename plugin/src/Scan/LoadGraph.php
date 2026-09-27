<?php
/**
 * Cross-file analysis: can a whole file's code run on the target PHP version?
 *
 * @package Airworthy
 */

namespace Airworthy\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "is this whole file dead on the target?" for one component (plugin or theme), so a
 * finding can be guarded even when the guard is in another file. Two ways a file's code can
 * only run under a condition:
 *
 * 1. Declaration-only files. A file that only declares classes, interfaces or traits (plus the
 *    usual ABSPATH exit check) runs nothing when loaded; its code runs when one of its classes
 *    is used. If every use (`new C`, `C::method()`) anywhere in the component is itself in dead
 *    code, so is the file. Example: All-in-One WP Migration's MySQL class is only created after
 *    `if ( PHP_MAJOR_VERSION >= 7 ) { return new …Mysqli; }`.
 * 2. Conditionally included files. A file that is only ever include/require'd from dead code.
 *
 * Deliberately conservative, so it can never hide a real bug:
 * - a class that is extended, implemented, imported with `use`, named in a string, or never
 *   used in the component (other code may use it) does not count;
 * - dynamic creation (`new $class`, `$class::`) plus a string that could build the class name
 *   disqualifies it;
 * - the include rule only applies when every include/require in the component could be
 *   resolved to a file, so no unseen dynamic include can load it too;
 * - chains are followed at most 3 files deep.
 *
 * Built lazily, and only for components that still have a plain error to explain.
 */
final class LoadGraph {

	const MAX_DEPTH = 3;

	/**
	 * Component root (folder).
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Relative paths of the component's files.
	 *
	 * @var string[]
	 */
	private $files;

	/**
	 * Target PHP version.
	 *
	 * @var string
	 */
	private $target;

	/**
	 * Names the component declares itself (for classifiers of referencing files).
	 *
	 * @var array
	 */
	private $own_functions;

	/**
	 * Per-file facts, built on first use.
	 *
	 * @var array|null
	 */
	private $index;

	/**
	 * Classifiers of referencing files, by relative path.
	 *
	 * @var array<string,Classifier>
	 */
	private $classifiers = array();

	/**
	 * Memoised answers: "file|subjects|removed" => bool.
	 *
	 * @var array<string,bool>
	 */
	private $memo = array();

	/**
	 * Graphs already built in this process, by component root and target.
	 *
	 * @var array<string,self>
	 */
	private static $instances = array();

	/**
	 * The graph for a component, shared within one process.
	 *
	 * @param string   $root          Component folder.
	 * @param string[] $files         Relative paths.
	 * @param string   $target        Target PHP version.
	 * @param array    $own_functions Names the component declares (FileLister::prepass()).
	 * @return self
	 */
	public static function for_component( $root, array $files, $target, array $own_functions = array() ) {
		$key = $root . '|' . $target;
		if ( ! isset( self::$instances[ $key ] ) ) {
			// One component at a time: a WP-CLI scan runs every component in one process.
			self::$instances = array( $key => new self( $root, $files, $target, $own_functions ) );
		}
		return self::$instances[ $key ];
	}

	/**
	 * Constructor.
	 *
	 * @param string   $root          Component folder.
	 * @param string[] $files         Relative paths.
	 * @param string   $target        Target PHP version.
	 * @param array    $own_functions Names the component declares (FileLister::prepass()).
	 */
	private function __construct( $root, array $files, $target, array $own_functions ) {
		$this->root          = rtrim( $root, '/\\' );
		$this->files         = $files;
		$this->target        = $target;
		$this->own_functions = $own_functions;
	}

	/**
	 * Whether none of a file's code can run on the target, for a finding with these subjects.
	 *
	 * @param string   $relative File path relative to the component.
	 * @param string[] $subjects Names the finding is about (see Classifier).
	 * @param bool     $removed  Whether the flagged thing is removed on the target.
	 * @param int      $depth    Recursion depth.
	 * @return bool
	 */
	public function file_is_dead( $relative, array $subjects, $removed, $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH || is_file( $this->root ) ) {
			return false; // Single-file plugins: the file is the plugin.
		}
		$key = $relative . '|' . implode( ',', $subjects ) . '|' . ( $removed ? 1 : 0 );
		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}
		$this->memo[ $key ] = false; // Cycles count as "can run".
		$this->build();
		if ( $this->index['incomplete'] ) {
			return false; // A file couldn't be read, so uses and includes may be missing.
		}
		$facts = isset( $this->index['files'][ $relative ] ) ? $this->index['files'][ $relative ] : null;
		if ( ! $facts ) {
			return false;
		}

		$dead = false;
		if ( $facts['decl_only'] && $facts['classes'] ) {
			$dead = $this->classes_are_dead( $relative, $facts['classes'], $subjects, $removed, $depth );
		}
		if ( ! $dead ) {
			$dead = $this->includes_are_dead( $relative, $subjects, $removed, $depth );
		}
		$this->memo[ $key ] = $dead;
		return $dead;
	}

	/**
	 * File that declares a class or function in this component (unique names only).
	 *
	 * @param string $kind "class" or "function".
	 * @param string $name Lowercase unqualified name.
	 * @return string|null Relative path.
	 */
	public function file_declaring( $kind, $name ) {
		$this->build();
		return isset( $this->index['declares'][ $kind ][ $name ] ) ? $this->index['declares'][ $kind ][ $name ] : null;
	}

	/**
	 * Value, on the target, of a parameterless helper `return <condition>;` in a component file.
	 *
	 * @param string      $relative File declaring it.
	 * @param string      $name     Function or method name.
	 * @param string|null $class_name Lowercase class name, or null for a function.
	 * @param array       $subjects   Finding subjects.
	 * @param bool        $removed    Removed on the target.
	 * @param int         $depth      Helper depth.
	 * @return bool|null
	 */
	public function helper_value( $relative, $name, $class_name, array $subjects, $removed, $depth ) {
		if ( ! $this->readable( $relative ) ) {
			return null;
		}
		$code = (string) @file_get_contents( $this->root . '/' . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$cls  = new Classifier( $code, $relative, $this->target, $this->own_functions, $this, $depth );
		return $cls->predicate_value( $name, $class_name, $subjects, $removed );
	}

	/**
	 * Rule 1: every use of every class declared in the file is in dead code.
	 *
	 * @param string   $relative Declaring file.
	 * @param string[] $classes  Declared names (lowercase).
	 * @param string[] $subjects Finding subjects.
	 * @param bool     $removed  Removed on the target.
	 * @param int      $depth    Recursion depth.
	 * @return bool
	 */
	private function classes_are_dead( $relative, array $classes, array $subjects, $removed, $depth ) {
		foreach ( $classes as $class ) {
			if ( isset( $this->index['blocked'][ $class ] ) ) {
				return false; // Extended, imported, named in a string, or maybe built dynamically.
			}
			$uses = isset( $this->index['uses'][ $class ] ) ? $this->index['uses'][ $class ] : array();
			$uses = array_filter(
				$uses,
				static function ( $site ) use ( $relative ) {
					return $site[0] !== $relative; // Uses inside the class's own file only run once it's used.
				}
			);
			if ( ! $uses ) {
				return false; // Never used here: other code might use it.
			}
			foreach ( $uses as $use ) {
				if ( ! $this->line_is_dead( $use[0], $use[1], $subjects, $removed, $depth ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Rule 2: the file is only ever included from dead code.
	 *
	 * @param string   $relative File.
	 * @param string[] $subjects Finding subjects.
	 * @param bool     $removed  Removed on the target.
	 * @param int      $depth    Recursion depth.
	 * @return bool
	 */
	private function includes_are_dead( $relative, array $subjects, $removed, $depth ) {
		if ( $this->index['unresolved_includes'] ) {
			return false; // Some include could load any file.
		}
		$includes = isset( $this->index['includes'][ $relative ] ) ? $this->index['includes'][ $relative ] : array();
		if ( ! $includes ) {
			return false; // Loaded by WordPress itself (main file, templates) or by other code.
		}
		foreach ( $includes as $inc ) {
			if ( ! $this->line_is_dead( $inc[0], $inc[1], $subjects, $removed, $depth ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a line in a component file is dead code on the target: inside a dead branch of
	 * its own file, or in a file that is itself dead.
	 *
	 * @param string   $relative File.
	 * @param int      $line     Line.
	 * @param string[] $subjects Finding subjects.
	 * @param bool     $removed  Removed on the target.
	 * @param int      $depth    Recursion depth.
	 * @return bool
	 */
	private function line_is_dead( $relative, $line, array $subjects, $removed, $depth ) {
		if ( ! isset( $this->classifiers[ $relative ] ) ) {
			if ( ! $this->readable( $relative ) ) {
				return false;
			}
			$code                           = (string) @file_get_contents( $this->root . '/' . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			$this->classifiers[ $relative ] = new Classifier( $code, $relative, $this->target, $this->own_functions, $this );
		}
		if ( $this->classifiers[ $relative ]->dead_at( $line, $subjects, $removed ) ) {
			return true;
		}
		return $this->file_is_dead( $relative, $subjects, $removed, $depth + 1 );
	}

	/**
	 * Whether a component file can be read and tokenised in the free memory now.
	 *
	 * @param string $relative File.
	 * @return bool
	 */
	private function readable( $relative ) {
		$size = @filesize( $this->root . '/' . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing files are simply not readable.
		return false !== $size && Budget::tokenizable( (int) $size );
	}

	/**
	 * Reads and indexes every file of the component (once).
	 */
	private function build() {
		if ( null !== $this->index ) {
			return;
		}
		$this->index = array(
			'declares'            => array(
				'class'    => array(),
				'function' => array(),
			), // name => relative.
			'files'               => array(), // Per file: whether it only declares classes, and which.
			'uses'                => array(), // Per class: where it is created or called statically.
			'blocked'             => array(), // Classes the rule can't follow.
			'includes'            => array(), // target relative => [[relative, line], …].
			'unresolved_includes' => false,
			'incomplete'          => false, // A file was unreadable or too big to read now.
		);
		$strings     = array(); // Lowercase string literals, for dynamic-creation checks.
		$dynamic     = false;   // Any `new $x` / `$x::` in the component.
		$defines     = array(); // Constant => resolved path value.
		$raw_incs    = array(); // [relative, line, token slice] to resolve after defines are known.
		$declared    = array(); // All declared class names (lowercase) => relative.

		foreach ( $this->files as $relative ) {
			$code = $this->readable( $relative ) ? @file_get_contents( $this->root . '/' . $relative ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			if ( false === $code ) {
				$this->index['incomplete'] = true;
				continue;
			}
			try {
				$tokens = token_get_all( $code );
			} catch ( \Throwable $e ) {
				continue;
			}
			$sig = array();
			foreach ( $tokens as $t ) {
				if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML ), true ) ) {
					continue;
				}
				$sig[] = $t;
			}
			$this->index_file( $relative, $sig, $strings, $dynamic, $defines, $raw_incs, $declared );
		}

		foreach ( $declared as $name => $file ) {
			$this->index['declares']['class'][ $name ] = $file;
		}

		// Resolve includes now that constants are known.
		foreach ( $raw_incs as $inc ) {
			$path = $this->resolve_path( $inc[2], $inc[0], $defines );
			if ( null === $path ) {
				$this->index['unresolved_includes'] = true;
				continue;
			}
			$target = $this->relative_of( $path );
			if ( null !== $target ) {
				$this->index['includes'][ $target ][] = array( $inc[0], $inc[1] );
			}
			// Includes pointing outside the component (e.g. wp-admin files) don't load its files.
		}

		// Dynamic creation plus a string that is the class name or a prefix of it (≥ 6 chars).
		if ( $dynamic ) {
			foreach ( array_keys( $declared ) as $class ) {
				foreach ( $strings as $s => $_ ) {
					if ( strlen( $s ) >= 6 && 0 === strpos( $class, $s ) ) {
						$this->index['blocked'][ $class ] = true;
						break;
					}
				}
			}
		}
		foreach ( $strings as $s => $_ ) {
			if ( isset( $declared[ $s ] ) ) {
				$this->index['blocked'][ $s ] = true; // Named in a string, e.g. for class_exists() or a callback.
			}
		}
	}

	/**
	 * Indexes one file's significant tokens.
	 *
	 * @param string $relative  File.
	 * @param array  $sig       Tokens without whitespace/comments.
	 * @param array  $strings   String literals (by reference).
	 * @param bool   $dynamic   Dynamic creation seen (by reference).
	 * @param array  $defines   Constants (by reference).
	 * @param array  $raw_incs  Includes to resolve (by reference).
	 * @param array  $declared  Declared classes (by reference).
	 */
	private function index_file( $relative, array $sig, array &$strings, &$dynamic, array &$defines, array &$raw_incs, array &$declared ) {
		$n          = count( $sig );
		$depth      = 0;
		$class_ends = array();
		$pending    = false;
		$decl_only  = true;
		$classes    = array();
		$in_guard   = 0; // Inside the top-level `if ( ! defined( 'ABSPATH' ) ) { exit; }` idiom.

		for ( $i = 0; $i < $n; $i++ ) {
			$t    = $sig[ $i ];
			$type = is_array( $t ) ? $t[0] : null;
			$text = is_array( $t ) ? $t[1] : $t;
			$line = is_array( $t ) ? $t[2] : $this->line_before( $sig, $i );
			$next = isset( $sig[ $i + 1 ] ) ? $sig[ $i + 1 ] : null;
			$prev = $i > 0 ? $sig[ $i - 1 ] : null;

			if ( T_CONSTANT_ENCAPSED_STRING === $type ) {
				$value = strtolower( ltrim( substr( $text, 1, -1 ), '\\' ) );
				if ( strlen( $value ) >= 6 && strlen( $value ) <= 200 ) {
					$strings[ $value ] = true;
				}
			}

			// Class-like declarations.
			$is_class_kw = in_array( $type, array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) || ( defined( 'T_ENUM' ) && T_ENUM === $type ); // phpcs:ignore PHPCompatibility.Constants.NewConstants.t_enumFound -- Guarded by defined().
			if ( $is_class_kw && ! ( is_array( $prev ) && T_DOUBLE_COLON === $prev[0] ) && ! ( is_array( $prev ) && T_NEW === $prev[0] ) ) {
				$pending = true;
				if ( is_array( $next ) && T_STRING === $next[0] && ! $class_ends && 0 === $depth - $in_guard ) {
					$name              = strtolower( $next[1] );
					$classes[]         = $name;
					$declared[ $name ] = $relative;
				}
			}

			if ( '{' === $text || T_CURLY_OPEN === $type || T_DOLLAR_OPEN_CURLY_BRACES === $type ) {
				++$depth;
				if ( $pending ) {
					$class_ends[] = $depth;
					$pending      = false;
				}
				continue;
			}
			if ( '}' === $text ) {
				if ( $class_ends && end( $class_ends ) === $depth ) {
					array_pop( $class_ends );
				}
				if ( $in_guard && $depth === $in_guard ) {
					$in_guard = 0;
				}
				--$depth;
				continue;
			}

			// Functions outside classes (for helper calls such as `if ( is_modern_php() )`).
			if ( T_FUNCTION === $type && ! $class_ends && is_array( $next ) && T_STRING === $next[0] ) {
				$this->index['declares']['function'][ strtolower( $next[1] ) ] = $relative;
			}

			// Anything at top level other than declarations makes the file run code when loaded.
			if ( $decl_only && ! $class_ends && ! $pending && 0 === $depth ) {
				$decl_only = $this->is_declaration_token( $sig, $i, $in_guard );
			}

			// Uses that run a class's code: new C, C::member (not C::class).
			if ( T_NEW === $type ) {
				$name = self::read_name( $sig, $i + 1 );
				if ( '' !== $name ) {
					$this->index['uses'][ self::short_name( $name ) ][] = array( $relative, $line );
				} elseif ( ( is_array( $next ) && T_VARIABLE === $next[0] ) || '(' === $next ) {
					$dynamic = true;
				}
			}
			if ( is_array( $next ) && T_DOUBLE_COLON === $next[0] ) {
				if ( T_VARIABLE === $type ) {
					$dynamic = true;
				} elseif ( T_STRING === $type || ( defined( 'T_NAME_QUALIFIED' ) && in_array( $type, array( T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) ) { // phpcs:ignore PHPCompatibility.Constants.NewConstants -- Guarded by defined().
					$after = isset( $sig[ $i + 2 ] ) ? $sig[ $i + 2 ] : null;
					$name  = self::short_name( $text );
					if ( ! ( is_array( $after ) && T_CLASS === $after[0] ) && ! in_array( $name, array( 'self', 'static', 'parent' ), true ) ) {
						$this->index['uses'][ $name ][] = array( $relative, $line );
					}
				}
			}
			// extends / implements / use (imports and traits): the class is used in ways we don't follow.
			if ( in_array( $type, array( T_EXTENDS, T_IMPLEMENTS, T_USE ), true ) ) {
				for ( $j = $i + 1; $j < $n; $j++ ) {
					if ( '{' === $sig[ $j ] || ';' === $sig[ $j ] ) {
						break;
					}
					if ( is_array( $sig[ $j ] ) && ( T_STRING === $sig[ $j ][0] || ( defined( 'T_NAME_QUALIFIED' ) && in_array( $sig[ $j ][0], array( T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) ) ) { // phpcs:ignore PHPCompatibility.Constants.NewConstants -- Guarded by defined().
						$this->index['blocked'][ self::short_name( $sig[ $j ][1] ) ] = true;
					}
				}
			}

			// define( 'NAME', <path expression> ).
			if ( T_STRING === $type && 'define' === strtolower( $text ) && '(' === $next && isset( $sig[ $i + 2 ] ) && is_array( $sig[ $i + 2 ] ) && T_CONSTANT_ENCAPSED_STRING === $sig[ $i + 2 ][0] && ',' === ( isset( $sig[ $i + 3 ] ) ? $sig[ $i + 3 ] : '' ) ) {
				$end = $this->expression_end( $sig, $i + 4 );
				$defines[ substr( $sig[ $i + 2 ][1], 1, -1 ) ] = array( array_slice( $sig, $i + 4, $end - ( $i + 4 ) ), $relative );
			}

			// include / require.
			if ( in_array( $type, array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
				$end        = $this->expression_end( $sig, $i + 1 );
				$raw_incs[] = array( $relative, $line, array_slice( $sig, $i + 1, $end - ( $i + 1 ) ) );
			}
		}

		$this->index['files'][ $relative ] = array(
			'decl_only' => $decl_only,
			'classes'   => $classes,
		);
	}

	/**
	 * Whether the top-level token at $i is part of a declaration-only file: namespace, use,
	 * declare, class modifiers, or the ABSPATH exit check (`defined( 'ABSPATH' ) || exit;` or
	 * `if ( ! defined( 'ABSPATH' ) ) { exit; }`).
	 *
	 * @param array $sig      Tokens.
	 * @param int   $i        Index.
	 * @param int   $in_guard Depth of an ABSPATH guard block being skipped (by reference).
	 * @return bool
	 */
	private function is_declaration_token( array $sig, &$i, &$in_guard ) {
		$t    = $sig[ $i ];
		$type = is_array( $t ) ? $t[0] : null;
		if ( in_array( $type, array( T_NAMESPACE, T_USE, T_DECLARE, T_ABSTRACT, T_FINAL, T_CLASS, T_INTERFACE, T_TRAIT ), true ) || ( defined( 'T_READONLY' ) && T_READONLY === $type ) || ( defined( 'T_ATTRIBUTE' ) && T_ATTRIBUTE === $type ) ) { // phpcs:ignore PHPCompatibility.Constants.NewConstants -- Guarded by defined().
			// Skip to the end of the statement (namespace/use/declare) — class bodies are handled by the caller.
			if ( in_array( $type, array( T_NAMESPACE, T_USE, T_DECLARE ), true ) ) {
				for ( $j = $i + 1; isset( $sig[ $j ] ) && ';' !== $sig[ $j ] && '{' !== $sig[ $j ]; $j++ ) {
					$i = $j;
				}
			}
			return true;
		}
		// The ABSPATH / WPINC exit check, in either form: skip it.
		$text = '';
		for ( $j = $i; isset( $sig[ $j ] ) && $j < $i + 16; $j++ ) {
			$text .= is_array( $sig[ $j ] ) ? $sig[ $j ][1] : $sig[ $j ];
		}
		if ( preg_match( "/^defined\\(['\"](ABSPATH|WPINC)['\"]\\)(\\|\\||or)(exit|die)/i", $text, $m ) ) {
			for ( $j = $i; isset( $sig[ $j ] ) && ';' !== $sig[ $j ]; $j++ ) {
				$i = $j;
			}
			++$i;
			return true;
		}
		if ( preg_match( "/^if\\(!defined\\(['\"](ABSPATH|WPINC)['\"]\\)\\)(\\{)?(exit|die)/i", $text, $m ) ) {
			// Skip to the end of the guard: its block or statement.
			for ( $j = $i; isset( $sig[ $j ] ); $j++ ) {
				if ( '{' === $sig[ $j ] ) {
					$in_guard = 1; // The caller increments depth at this '{' and resets at its '}'.
					$i        = $j - 1;
					return true;
				}
				if ( ';' === $sig[ $j ] ) {
					$i = $j;
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Resolves an include/define path expression to an absolute path, or null.
	 * Understands string literals, __DIR__, __FILE__, dirname( X [, levels] ),
	 * plugin_dir_path( X ), trailingslashit( X ), DIRECTORY_SEPARATOR, constants defined in the
	 * component, and concatenation with '.'.
	 *
	 * @param array  $tokens   Expression tokens.
	 * @param string $relative File the expression is in.
	 * @param array  $defines  Constants from the component.
	 * @param int    $depth    Recursion depth (for constants).
	 * @return string|null
	 */
	private function resolve_path( array $tokens, $relative, array $defines, $depth = 0 ) {
		if ( $depth > 5 ) {
			return null;
		}
		// Drop wrapping parentheses: require( … ).
		while ( $tokens && '(' === $tokens[0] && ')' === end( $tokens ) ) {
			$tokens = array_slice( $tokens, 1, -1 );
		}
		$file  = $this->root . '/' . $relative;
		$out   = '';
		$count = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$t    = $tokens[ $i ];
			$type = is_array( $t ) ? $t[0] : null;
			$text = is_array( $t ) ? $t[1] : $t;
			if ( '.' === $text ) {
				continue;
			}
			if ( T_CONSTANT_ENCAPSED_STRING === $type ) {
				$out .= stripcslashes( substr( $text, 1, -1 ) );
			} elseif ( T_DIR === $type ) {
				$out .= dirname( $file );
			} elseif ( T_FILE === $type ) {
				$out .= $file;
			} elseif ( T_STRING === $type && 'DIRECTORY_SEPARATOR' === $text ) {
				$out .= '/';
			} elseif ( T_STRING === $type && in_array( strtolower( $text ), array( 'dirname', 'plugin_dir_path', 'trailingslashit', 'untrailingslashit', 'wp_normalize_path' ), true ) && isset( $tokens[ $i + 1 ] ) && '(' === $tokens[ $i + 1 ] ) {
				// Find the matching ')' and split off a levels argument.
				$level = 0;
				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( '(' === $tokens[ $j ] ) {
						++$level;
					} elseif ( ')' === $tokens[ $j ] && 0 === --$level ) {
						break;
					}
				}
				if ( $j >= $count ) {
					return null;
				}
				$inner  = array_slice( $tokens, $i + 2, $j - $i - 2 );
				$levels = 1;
				$comma  = array_search( ',', $inner, true );
				if ( false !== $comma ) {
					$levels = (int) ( is_array( $inner[ $comma + 1 ] ) ? $inner[ $comma + 1 ][1] : 1 );
					$inner  = array_slice( $inner, 0, $comma );
				}
				$value = $this->resolve_path( $inner, $relative, $defines, $depth + 1 );
				if ( null === $value ) {
					return null;
				}
				$fn = strtolower( $text );
				if ( 'dirname' === $fn ) {
					$value = dirname( $value, max( 1, $levels ) );
				} elseif ( 'plugin_dir_path' === $fn ) {
					$value = dirname( $value ) . '/';
				} elseif ( 'trailingslashit' === $fn ) {
					$value = rtrim( $value, '/\\' ) . '/';
				} elseif ( 'untrailingslashit' === $fn ) {
					$value = rtrim( $value, '/\\' );
				}
				$out .= $value;
				$i    = $j;
			} elseif ( T_STRING === $type && isset( $defines[ $text ] ) ) {
				$value = $this->resolve_path( $defines[ $text ][0], $defines[ $text ][1], $defines, $depth + 1 );
				if ( null === $value ) {
					return null;
				}
				$out .= $value;
			} else {
				return null; // Variables, function calls, unknown constants: can't resolve.
			}
		}
		return '' === $out ? null : $out;
	}

	/**
	 * A resolved absolute path as a path relative to the component, or null if outside it.
	 *
	 * @param string $path Absolute path.
	 * @return string|null
	 */
	private function relative_of( $path ) {
		$normal = self::normalise( $path );
		$root   = self::normalise( $this->root ) . '/';
		return 0 === strpos( $normal, $root ) ? substr( $normal, strlen( $root ) ) : null;
	}

	/**
	 * Resolves "." and ".." segments and duplicate slashes; keeps a leading "/" if present.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function normalise( $path ) {
		$path  = str_replace( '\\', '/', $path );
		$parts = array();
		foreach ( explode( '/', $path ) as $part ) {
			if ( '..' === $part ) {
				array_pop( $parts );
			} elseif ( '.' !== $part && '' !== $part ) {
				$parts[] = $part;
			}
		}
		return ( '/' === substr( $path, 0, 1 ) ? '/' : '' ) . implode( '/', $parts );
	}

	/**
	 * Index just past an expression (at the ';' or a top-level ')' / ',' that ends it).
	 *
	 * @param array $sig   Tokens.
	 * @param int   $start Start index.
	 * @return int
	 */
	private function expression_end( array $sig, $start ) {
		$level = 0;
		$n     = count( $sig );
		for ( $j = $start; $j < $n; $j++ ) {
			$t = $sig[ $j ];
			if ( '(' === $t || '[' === $t ) {
				++$level;
			} elseif ( ')' === $t || ']' === $t ) {
				if ( 0 === $level ) {
					return $j;
				}
				--$level;
			} elseif ( ( ';' === $t || ',' === $t ) && 0 === $level ) {
				return $j;
			}
		}
		return $n;
	}

	/**
	 * Line of the nearest preceding token that carries one.
	 *
	 * @param array $sig Tokens.
	 * @param int   $i   Index.
	 * @return int
	 */
	private function line_before( array $sig, $i ) {
		for ( $j = $i; $j >= 0; $j-- ) {
			if ( is_array( $sig[ $j ] ) ) {
				return (int) $sig[ $j ][2];
			}
		}
		return 1;
	}

	/**
	 * A class name starting at $k: one T_STRING / T_NAME_* token (PHP 8), or T_NS_SEPARATOR and
	 * T_STRING tokens (PHP 7: "\\Foo\\Bar" is four tokens). Empty when there is none there.
	 *
	 * @param array $sig Tokens.
	 * @param int   $k   Index.
	 * @return string
	 */
	private static function read_name( array $sig, $k ) {
		$name  = '';
		$kinds = array( T_STRING, T_NS_SEPARATOR );
		if ( defined( 'T_NAME_QUALIFIED' ) ) {
			$kinds[] = T_NAME_QUALIFIED; // phpcs:ignore PHPCompatibility.Constants.NewConstants -- Guarded by defined().
			$kinds[] = T_NAME_FULLY_QUALIFIED; // phpcs:ignore PHPCompatibility.Constants.NewConstants -- Guarded by defined().
		}
		while ( isset( $sig[ $k ] ) && is_array( $sig[ $k ] ) && in_array( $sig[ $k ][0], $kinds, true ) ) {
			$name .= $sig[ $k ][1];
			++$k;
		}
		return trim( $name, '\\' ) === '' ? '' : $name;
	}

	/**
	 * Unqualified lowercase class name ("\Foo\Bar" => "bar").
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function short_name( $name ) {
		$pos = strrpos( $name, '\\' );
		return strtolower( false === $pos ? $name : substr( $name, $pos + 1 ) );
	}
}
