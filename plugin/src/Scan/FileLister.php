<?php
/**
 * Lists the PHP files in a plugin or theme.
 *
 * @package Airworthy
 */

namespace Airworthy\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Walks a component folder and returns its .php files as sorted paths relative to that folder.
 *
 * Symbolic links are never followed, so a scan can't leave the plugins and themes folders.
 * The vendor folder is included, since that code runs too. Generated data files (Symfony
 * charset tables, Composer and Jetpack classmaps, WordPress .l10n.php translations) are left
 * out: they hold no logic and are the heaviest files to scan (a 363 KB charset table needs
 * 134 MB). So are test suites inside bundled libraries, which never load on a live site.
 */
final class FileLister {

	/** Folders never worth descending into. */
	const SKIP_DIRS = array( '.git', '.svn', 'node_modules' );

	/**
	 * Test suites inside bundled libraries: never loaded on a live site. Only under library
	 * folders, so a plugin's own code is always scanned.
	 */
	const LIBRARY_TESTS = '#(^|/)(vendor|vendor_prefixed|vendor-prefixed|vendor-patched|third-party|jetpack_vendor)/(.+/)?(tests?|Tests?)/#';

	/** Generated data files, matched against the path relative to the component. */
	const DATA_FILES = '#(^|/)(polyfill-[^/]+/Resources/(charset|unidata)/|composer/(autoload_classmap|autoload_static|installed|jetpack_autoload_classmap|jetpack_autoload_filemap)\.php$)|\.l10n\.php$#';

	/**
	 * Lists a component's PHP files.
	 *
	 * @param string $root   Absolute path of the component folder, or of a single-file plugin.
	 * @return array{files:string[],ignored:int} Relative paths, and how many data and library-test files were left out.
	 */
	public static function list_files( $root ) {
		$root = rtrim( $root, '/\\' );

		if ( is_file( $root ) ) {
			return array(
				'files'   => array( basename( $root ) ),
				'ignored' => 0,
			);
		}
		if ( ! is_dir( $root ) || is_link( $root ) ) {
			return array(
				'files'   => array(),
				'ignored' => 0,
			);
		}

		$files   = array();
		$ignored = 0;
		$prefix  = strlen( $root ) + 1;

		$dirs = new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO );
		// Skip unwanted folders and every symbolic link; RecursiveDirectoryIterator does not
		// descend into linked folders by default, and linked files are dropped below.
		$filter = new \RecursiveCallbackFilterIterator(
			$dirs,
			static function ( \SplFileInfo $item ) {
				if ( $item->isLink() ) {
					return false;
				}
				return ! ( $item->isDir() && in_array( $item->getFilename(), self::SKIP_DIRS, true ) );
			}
		);
		$items  = new \RecursiveIteratorIterator( $filter, \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD );

		foreach ( $items as $item ) {
			if ( 'php' !== strtolower( $item->getExtension() ) || ! $item->isFile() ) {
				continue;
			}
			$relative = str_replace( '\\', '/', substr( $item->getPathname(), $prefix ) );
			if ( preg_match( self::DATA_FILES, $relative ) || preg_match( self::LIBRARY_TESTS, $relative ) ) {
				++$ignored;
				continue;
			}
			$files[] = $relative;
		}

		sort( $files, SORT_STRING );

		return array(
			'files'   => $files,
			'ignored' => $ignored,
		);
	}

	/**
	 * One read of every file before a component's first batch, for facts that span files:
	 * - serializable: interfaces extending Serializable (see serializable_interfaces());
	 * - functions / constants / classes: what the component declares itself in the global
	 *   namespace (lowercase), including polyfills such as `if ( ! function_exists(
	 *   'str_contains' ) ) { function str_contains() … }` or `defined( 'T_ENUM' ) ||
	 *   define( 'T_ENUM', … )`. A "new in PHP x" or "removed in PHP x" finding about a name the
	 *   component provides itself is not a PHP problem (see Classifier::OWN_CODE).
	 *
	 * Files too big to tokenise in the free memory (see Budget::tokenizable()) are left out of
	 * the declarations: a crash here would happen before any file is marked in flight, so the
	 * scan could never get past it. Their declarations are then simply not known, which can
	 * only make results stricter.
	 *
	 * @param string   $root  Component folder (or single file).
	 * @param string[] $files Relative paths from list_files().
	 * @return array{serializable:string[],functions:string[],constants:string[],classes:string[]}
	 */
	public static function prepass( $root, array $files ) {
		$own = array(
			'functions' => array(),
			'constants' => array(),
			'classes'   => array(),
		);
		foreach ( $files as $relative ) {
			$path = is_file( $root ) ? $root : rtrim( $root, '/\\' ) . '/' . $relative;
			$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable files are reported by the scan.
			if ( false === $size || ! Budget::tokenizable( (int) $size ) ) {
				continue;
			}
			$code = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file; unreadable files are reported by the scan.
			if ( false === $code || ! preg_match( '/\\b(function|define|const|class|interface|trait)\\b/i', $code ) ) {
				continue;
			}
			foreach ( self::declarations( $code ) as $kind => $names ) {
				foreach ( $names as $name ) {
					$own[ $kind ][ $name ] = true;
				}
			}
		}
		return array(
			'serializable' => self::serializable_interfaces( $root, $files ),
			'functions'    => array_keys( $own['functions'] ),
			'constants'    => array_keys( $own['constants'] ),
			'classes'      => array_keys( $own['classes'] ),
		);
	}

	/**
	 * Global-namespace declarations in one file (lowercase): functions outside any class-like
	 * body (including ones wrapped in if-blocks), constants from define() anywhere (define()
	 * is always global) and top-level `const`, and class/interface/trait names. Declarations
	 * in a namespaced file (other than define()) are skipped: they don't provide global names.
	 *
	 * @param string $code PHP source.
	 * @return array{functions:string[],constants:string[],classes:string[]}
	 */
	private static function declarations( $code ) {
		$out = array(
			'functions' => array(),
			'constants' => array(),
			'classes'   => array(),
		);
		try {
			$tokens = token_get_all( $code );
		} catch ( \Throwable $e ) {
			return $out;
		}
		$sig = array();
		foreach ( $tokens as $t ) {
			if ( ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$sig[] = $t;
			}
		}
		$namespaced = false;
		$depth      = 0;
		$class_ends = array();
		$pending    = false;
		$count      = count( $sig );
		for ( $i = 0; $i < $count; $i++ ) {
			$t    = $sig[ $i ];
			$type = is_array( $t ) ? $t[0] : null;
			$text = is_array( $t ) ? $t[1] : $t;
			$next = isset( $sig[ $i + 1 ] ) ? $sig[ $i + 1 ] : null;
			$prev = $i > 0 ? $sig[ $i - 1 ] : null;

			if ( T_NAMESPACE === $type && is_array( $next ) && T_NS_SEPARATOR !== $next[0] ) {
				$namespaced = true; // A namespace declaration, not a namespace-relative call.
			}
			// phpcs:ignore PHPCompatibility.Constants.NewConstants.t_enumFound -- Guarded by defined().
			$class_kw = in_array( $type, array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) || ( defined( 'T_ENUM' ) && T_ENUM === $type );
			if ( $class_kw && ! ( is_array( $prev ) && in_array( $prev[0], array( T_DOUBLE_COLON, T_NEW ), true ) ) ) {
				$pending = true;
				if ( ! $namespaced && ! $class_ends && is_array( $next ) && T_STRING === $next[0] ) {
					$out['classes'][] = strtolower( $next[1] );
				}
			} elseif ( '{' === $text || T_CURLY_OPEN === $type || T_DOLLAR_OPEN_CURLY_BRACES === $type ) {
				++$depth;
				if ( $pending ) {
					$class_ends[] = $depth;
					$pending      = false;
				}
			} elseif ( '}' === $text ) {
				if ( $class_ends && end( $class_ends ) === $depth ) {
					array_pop( $class_ends );
				}
				--$depth;
			} elseif ( T_FUNCTION === $type && ! $class_ends && ! $namespaced ) {
				$k = $i + 1;
				if ( isset( $sig[ $k ] ) && '&' === $sig[ $k ] ) {
					++$k;
				}
				if ( isset( $sig[ $k ] ) && is_array( $sig[ $k ] ) && T_STRING === $sig[ $k ][0] ) {
					$out['functions'][] = strtolower( $sig[ $k ][1] ); // Not closures: those have '(' here.
				}
			} elseif ( T_STRING === $type && 'define' === strtolower( $text ) && '(' === $next && ! ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) ) {
				if ( isset( $sig[ $i + 2 ] ) && is_array( $sig[ $i + 2 ] ) && T_CONSTANT_ENCAPSED_STRING === $sig[ $i + 2 ][0] ) {
					$out['constants'][] = strtolower( ltrim( substr( $sig[ $i + 2 ][1], 1, -1 ), '\\' ) );
				}
			} elseif ( T_CONST === $type && ! $class_ends && ! $namespaced && 0 === $depth && is_array( $next ) && T_STRING === $next[0] ) {
				$out['constants'][] = strtolower( $next[1] );
			}
		}
		return $out;
	}

	/**
	 * Names of interfaces in a component that extend Serializable (directly or through another
	 * such interface). PHPCompatibility's RemovedSerializable rule needs them to spot classes
	 * that implement Serializable indirectly; it can't learn them across separate batches.
	 *
	 * @param string   $root  Component folder (or single file).
	 * @param string[] $files Relative paths from list_files().
	 * @return string[]
	 */
	public static function serializable_interfaces( $root, array $files ) {
		$extends = array(); // interface => names it extends.
		foreach ( $files as $relative ) {
			$path = is_file( $root ) ? $root : rtrim( $root, '/\\' ) . '/' . $relative;
			$code = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file; unreadable files are reported by the scan.
			if ( false === $code || false === stripos( $code, 'interface' ) ) {
				continue;
			}
			if ( preg_match_all( '/\binterface\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+([^{]+)\{/i', $code, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $match ) {
					$parents = array();
					foreach ( explode( ',', $match[2] ) as $parent ) {
						$parents[] = strtolower( ltrim( substr( trim( $parent ), (int) strrpos( '\\' . trim( $parent ), '\\' ) ), '\\' ) );
					}
					$extends[ $match[1] ] = $parents;
				}
			}
		}

		// Follow chains: A extends Serializable, B extends A, ... (PHP names are case-insensitive).
		$found   = array(); // lowercase name => name as written.
		$changed = true;
		while ( $changed ) {
			$changed = false;
			foreach ( $extends as $name => $parents ) {
				$key = strtolower( $name );
				if ( isset( $found[ $key ] ) ) {
					continue;
				}
				foreach ( $parents as $parent ) {
					if ( 'serializable' === $parent || isset( $found[ $parent ] ) ) {
						$found[ $key ] = $name;
						$changed       = true;
						break;
					}
				}
			}
		}
		return array_values( $found );
	}
}
