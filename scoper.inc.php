<?php
/**
 * PHP-Scoper config: prefixes the bundled scan engine (PHP_CodeSniffer, PHPCSUtils,
 * PHPCompatibility*) under Airworthy\Vendor so it cannot clash with other
 * plugins or tools that ship the same libraries.
 *
 * Run by bin/build.sh; see there for the full build.
 */

use Isolated\Symfony\Component\Finder\Finder;

return array(
	'prefix'  => 'Airworthy\\Vendor',

	'finders' => array(
		Finder::create()
			->files()
			->ignoreVCS( true )
			->in( getenv( 'AIRWORTHY_VENDOR_DIR' ) )
			->exclude( array( 'dealerdirect', 'woocommerce', 'bin' ) ) // Action Scheduler is bundled unscoped; see bin/build.sh.
			->notPath( '#/(Tests?|tests?|docs?)/#' )
			->name( array( '*.php', '*.xml', 'LICENSE*', 'licence*', 'license*', 'COPYING*', 'composer.json', 'installed.json' ) )
			->notName( array( 'phpunit.xml*', 'phpcs.xml*', '.phpcs.xml*' ) ),
	),

	// PHP_CodeSniffer defines the T_* token constants globally (polyfilling newer PHP tokens),
	// and its own run-mode constants. They must stay global: sniffs compare against them and
	// PHP's tokenizer returns the real T_* values.
	'exclude-constants' => array(
		'/^T_/',
		'PHP_CODESNIFFER_VERBOSITY',
		'PHP_CODESNIFFER_CBF',
		'PHP_CODESNIFFER_IN_TESTS',
	),

	// PHP-Scoper renames classes but not class names held in plain strings. PHP_CodeSniffer's own
	// autoloader matches 'PHP_CodeSniffer\' by string and fixed length, so point it at the prefixed
	// namespace, and do the same for the namespace each standard registers for autoloading.
	// (Reports, Generators and PEAR sniffs have similar strings but are never used.)
	// Search strings below are the scoped source text exactly, written as single-quoted PHP
	// strings (so '\\\\' is two backslash characters). bin/build.sh verifies the result.
	'patchers' => array(
		static function ( string $file_path, string $prefix, string $contents ): string {
			// The T_* token constants are global and can't be prefixed. If another plugin already
			// loaded PHP_CodeSniffer, they exist: skip them rather than warn (a fatal error in PHP 9).
			// Values are 'PHPCS_T_<NAME>' strings, so an existing copy holds the same values.
			if ( preg_match( '#squizlabs/php_codesniffer/src/Util/Tokens\.php$#', $file_path ) ) {
				$patched = preg_replace( "/^define\\('(T_[A-Z0-9_]+)', /m", "defined('\$1') || define('\$1', ", $contents, -1, $count );
				if ( $count < 50 ) {
					throw new RuntimeException( "PHP_CodeSniffer Tokens patch matched only {$count} constants; PHPCS changed, review scoper.inc.php." );
				}
				return $patched;
			}
			if ( ! preg_match( '#squizlabs/php_codesniffer/autoload\.php$#', $file_path ) ) {
				return $contents;
			}
			// How a namespace string looks inside a single-quoted PHP literal.
			$lit     = static function ( $s ) {
				return str_replace( '\\', '\\\\', $s );
			};
			$pfx     = $prefix . '\\';
			$ns      = $pfx . 'PHP_CodeSniffer\\';
			$ns_test = $ns . 'Tests\\';
			$map     = array(
				// Class-name checks in Autoload::load().
				'substr($className, 0, 16) === \'PHP_CodeSniffer\\\\\''
					=> 'substr($className, 0, ' . strlen( $ns ) . ') === \'' . $lit( $ns ) . '\'',
				'substr($className, 0, 22) === \'PHP_CodeSniffer\\Tests\\\\\''
					=> 'substr($className, 0, ' . strlen( $ns_test ) . ') === \'' . $lit( $ns_test ) . '\'',
				'str_replace(\'\\\\\', $ds, $className), 22)'
					=> 'str_replace(\'\\\\\', $ds, $className), ' . strlen( $ns_test ) . ')',
				'str_replace(\'\\\\\', $ds, $className), 16)'
					=> 'str_replace(\'\\\\\', $ds, $className), ' . strlen( $ns ) . ')',
				// Standards register a search path under their unprefixed namespace (from Config,
				// Runner and Ruleset); prefix it in the one method they all call.
				'self::$searchPaths[$path] = rtrim(trim((string) $nsPrefix), \'\\\\\');'
					=> '$nsPrefix = rtrim(trim((string) $nsPrefix), \'\\\\\'); '
					. 'self::$searchPaths[$path] = ($nsPrefix === \'\' || strpos($nsPrefix, \'' . $lit( $pfx ) . '\') === 0) ? $nsPrefix : \'' . $lit( $pfx ) . '\' . $nsPrefix;',
			);
			$patched = str_replace( array_keys( $map ), array_values( $map ), $contents, $count );
			if ( count( $map ) !== $count ) {
				throw new RuntimeException( "PHP_CodeSniffer autoload patch matched {$count}/" . count( $map ) . ' spots; PHPCS changed, review scoper.inc.php.' );
			}
			return $patched;
		},
	),

	'expose-global-constants' => false,
	'expose-global-classes'   => false,
	'expose-global-functions' => false,
);
