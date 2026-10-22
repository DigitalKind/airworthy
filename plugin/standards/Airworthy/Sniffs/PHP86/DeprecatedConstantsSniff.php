<?php
/**
 * PHP 8.6: constants that are deprecated.
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
 * Uses of global constants PHP 8.6 deprecates. Class constants with the same name
 * (Foo::SORT_LOCALE_STRING), declarations and function calls don't count.
 */
final class DeprecatedConstantsSniff implements Sniff {

	/** Constant => replacement. */
	const CONSTANTS = array(
		'SORT_LOCALE_STRING' => 'Collator::sort(), Collator::asort() or Collator::sortWithSortKeys()',
	);

	/**
	 * Tokens to look at.
	 *
	 * @return array<int|string>
	 */
	public function register() {
		return array( \T_STRING, \T_NAME_FULLY_QUALIFIED );
	}

	/**
	 * Flags a use of one of the constants.
	 *
	 * @param File $phpcs_file File being scanned.
	 * @param int  $ptr        Token position.
	 * @return void
	 */
	public function process( File $phpcs_file, $ptr ) {
		$tokens = $phpcs_file->getTokens();
		$name   = ltrim( $tokens[ $ptr ]['content'], '\\' );
		if ( ! isset( self::CONSTANTS[ $name ] ) || ! ScannedCode::shouldRunOnOrAbove( DeprecatedFunctionsSniff::VERSION ) ) {
			return;
		}
		$next = $phpcs_file->findNext( Tokens::$emptyTokens, $ptr + 1, null, true );
		if ( false !== $next && in_array( $tokens[ $next ]['code'], array( \T_OPEN_PARENTHESIS, \T_DOUBLE_COLON, \T_EQUAL ), true ) ) {
			return; // A call, a class name or a declaration (const X = ...).
		}
		$prev   = $phpcs_file->findPrevious( Tokens::$emptyTokens, $ptr - 1, null, true );
		$ignore = array(
			\T_CONST        => true,
			\T_FUNCTION     => true,
			\T_NS_SEPARATOR => true,
		) + Collections::objectOperators();
		if ( false !== $prev && isset( $ignore[ $tokens[ $prev ]['code'] ] ) ) {
			return;
		}
		$phpcs_file->addWarning( 'The constant "%s" is deprecated since PHP 8.6; Use %s instead', $ptr, strtolower( $name ) . 'Deprecated', array( $name, self::CONSTANTS[ $name ] ) );
	}
}
