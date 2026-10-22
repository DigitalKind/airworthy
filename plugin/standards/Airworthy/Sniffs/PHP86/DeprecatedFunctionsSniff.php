<?php
/**
 * PHP 8.6: functions that are deprecated.
 *
 * @package Airworthy
 */

namespace Airworthy\Sniffs\PHP86;

defined( 'ABSPATH' ) || exit;

use Airworthy\Vendor\PHP_CodeSniffer\Files\File;
use Airworthy\Vendor\PHP_CodeSniffer\Sniffs\Sniff;
use Airworthy\Vendor\PHP_CodeSniffer\Util\Tokens;
use Airworthy\Vendor\PHPCompatibility\Helpers\ScannedCode;
use Airworthy\Vendor\PHPCSUtils\Tokens\Collections;

/**
 * Calls to functions PHP 8.6 deprecates (php-src UPGRADING, PHP-8.6 branch, section 4). The
 * bundled PHPCompatibility stops at PHP 8.5, so these are ours. Messages use PHPCompatibility's
 * wording ("Function x() is deprecated since PHP 8.6; Use y instead"), which the rest of
 * Airworthy reads the PHP version from.
 *
 * Only calls to the global PHP function count: methods ($o->is_long()), static calls,
 * declarations and namespaced functions with the same name don't.
 */
final class DeprecatedFunctionsSniff implements Sniff {

	/** The PHP version that deprecates them. */
	const VERSION = '8.6';

	/** Function (lower case) => replacement, or '' when there is none. */
	const FUNCTIONS = array(
		'spl_object_hash'          => 'spl_object_id()',
		'spl_classes'              => 'ReflectionExtension::getClassNames()',
		'is_double'                => 'is_float()',
		'is_long'                  => 'is_int()',
		'is_integer'               => 'is_int()',
		'doubleval'                => 'floatval()',
		'strcoll'                  => 'Collator::compare()',
		'metaphone'                => '',
		'mysqli_get_charset'       => '',
		'mysqli_stmt_init'         => 'mysqli_prepare()',
		// Mbregex (the Oniguruma library is no longer maintained).
		'mb_ereg'                  => 'preg_match()',
		'mb_eregi'                 => 'preg_match() with the i and u modifiers',
		'mb_ereg_match'            => 'preg_match()',
		'mb_ereg_replace'          => 'preg_replace()',
		'mb_eregi_replace'         => 'preg_replace() with the i and u modifiers',
		'mb_ereg_replace_callback' => 'preg_replace_callback()',
		'mb_ereg_search'           => 'preg_match()',
		'mb_ereg_search_getpos'    => 'preg_match() with PREG_OFFSET_CAPTURE',
		'mb_ereg_search_getregs'   => 'preg_match()',
		'mb_ereg_search_init'      => 'preg_match()',
		'mb_ereg_search_pos'       => 'preg_match() with PREG_OFFSET_CAPTURE',
		'mb_ereg_search_regs'      => 'preg_match()',
		'mb_ereg_search_setpos'    => 'preg_match() with an offset',
		'mb_regex_encoding'        => 'the u modifier of the preg_* functions',
		'mb_regex_set_options'     => 'preg_* pattern modifiers',
		'mb_split'                 => 'preg_split() with the u modifier',
	);

	/**
	 * Functions declared in a namespaced file, by file (lower-case name => true).
	 *
	 * @var array<string,array<string,bool>>
	 */
	private $declared = array();

	/**
	 * Tokens to look at.
	 *
	 * @return array<int|string>
	 */
	public function register() {
		return array( \T_STRING, \T_NAME_FULLY_QUALIFIED );
	}

	/**
	 * Flags a call to one of the functions.
	 *
	 * @param File $phpcs_file File being scanned.
	 * @param int  $ptr        Token position.
	 * @return void
	 */
	public function process( File $phpcs_file, $ptr ) {
		$tokens = $phpcs_file->getTokens();
		$name   = ltrim( $tokens[ $ptr ]['content'], '\\' );
		$lc     = strtolower( $name );
		if ( ! array_key_exists( $lc, self::FUNCTIONS ) || ! ScannedCode::shouldRunOnOrAbove( self::VERSION ) ) {
			return;
		}
		$next = $phpcs_file->findNext( Tokens::$emptyTokens, $ptr + 1, null, true );
		if ( false === $next || \T_OPEN_PARENTHESIS !== $tokens[ $next ]['code'] || isset( $tokens[ $next ]['parenthesis_owner'] ) ) {
			return; // Not a call (a declaration's parenthesis has an owner).
		}
		$prev   = $phpcs_file->findPrevious( Tokens::$emptyTokens, $ptr - 1, null, true );
		$ignore = array(
			\T_NEW      => true,
			\T_FUNCTION => true,
		) + Collections::objectOperators();
		if ( false !== $prev && isset( $ignore[ $tokens[ $prev ]['code'] ] ) ) {
			return; // A method, a static call or a declaration.
		}
		if ( \T_STRING === $tokens[ $ptr ]['code'] && false !== $prev && \T_NS_SEPARATOR === $tokens[ $prev ]['code'] ) {
			$before = $phpcs_file->findPrevious( Tokens::$emptyTokens, $prev - 1, null, true );
			if ( false !== $before && in_array( $tokens[ $before ]['code'], array( \T_STRING, \T_NAMESPACE ), true ) ) {
				return; // A namespaced function with the same name.
			}
		}
		if ( \T_STRING === $tokens[ $ptr ]['code'] && isset( $this->namespaced_functions( $phpcs_file )[ $lc ] ) ) {
			return; // Unqualified call in a namespace that declares its own function of that name.
		}
		$alternative = self::FUNCTIONS[ $lc ];
		$message     = '' === $alternative ? 'Function %s() is deprecated since PHP 8.6' : 'Function %s() is deprecated since PHP 8.6; Use %s instead';
		$phpcs_file->addWarning( $message, $ptr, $lc . 'Deprecated', array( $name, $alternative ) );
	}

	/**
	 * Non-method functions a namespaced file declares. An unqualified call there resolves to
	 * the namespace's own function first, so it isn't a call to PHP's.
	 *
	 * @param File $phpcs_file File being scanned.
	 * @return array<string,bool>
	 */
	private function namespaced_functions( File $phpcs_file ) {
		$key = $phpcs_file->getFilename();
		if ( isset( $this->declared[ $key ] ) ) {
			return $this->declared[ $key ];
		}
		$this->declared = array(); // One file at a time.
		$found          = array();
		$tokens         = $phpcs_file->getTokens();
		if ( false !== $phpcs_file->findNext( \T_NAMESPACE, 0 ) ) {
			for ( $i = $phpcs_file->findNext( \T_FUNCTION, 0 ); false !== $i; $i = $phpcs_file->findNext( \T_FUNCTION, $i + 1 ) ) {
				$name = $phpcs_file->findNext( Tokens::$emptyTokens, $i + 1, null, true );
				if ( false !== $name && \T_STRING === $tokens[ $name ]['code'] && ! \Airworthy\Vendor\PHPCSUtils\Utils\Scopes::isOOMethod( $phpcs_file, $i ) ) {
					$found[ strtolower( $tokens[ $name ]['content'] ) ] = true;
				}
			}
		}
		$this->declared[ $key ] = $found;
		return $found;
	}
}
