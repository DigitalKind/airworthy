<?php
/**
 * PHP 8.6: language uses that are deprecated.
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
use Airworthy\Vendor\PHPCSUtils\Utils\FunctionDeclarations;
use Airworthy\Vendor\PHPCSUtils\Utils\ObjectDeclarations;
use Airworthy\Vendor\PHPCSUtils\Utils\PassedParameters;
use Airworthy\Vendor\PHPCSUtils\Utils\Scopes;

/**
 * The PHP 8.6 deprecations that can be read from code (php-src UPGRADING, PHP-8.6 branch):
 * - "return" inside a "finally" block;
 * - returning a value from __construct() / __destruct(), or making them generators;
 * (A third argument to define() is deprecated in 8.6 too, but PHPCompatibility already reports
 * it as removed since PHP 8.0.)
 * - "let" and "is" as the name of a class, interface, trait, enum, function or constant
 *   (also through define() and class_alias()), "readonly" as a function name, "_" as a
 *   constant name, and "namespace" as a class constant name;
 * - is_a() / is_subclass_of() with a class-name string and $allow_string false;
 * - a nullable return type on __debugInfo().
 * Deprecations that depend on values at run time (objects passed to array_walk(),
 * http_build_query() or stream filters, ReflectionProperty::setValue() with the wrong
 * object, and so on) can't be checked by reading code, and aren't.
 */
final class DeprecatedSyntaxSniff implements Sniff {

	/** Names PHP 8.6 reserves for classes, functions and constants (lower case). */
	const RESERVED = array( 'let', 'is' );

	/**
	 * Tokens to look at.
	 *
	 * @return array<int|string>
	 */
	public function register() {
		return array( \T_RETURN, \T_YIELD, \T_YIELD_FROM, \T_FUNCTION, \T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM, \T_CONST, \T_STRING );
	}

	/**
	 * Dispatches by token.
	 *
	 * @param File $phpcs_file File being scanned.
	 * @param int  $ptr        Token position.
	 * @return void
	 */
	public function process( File $phpcs_file, $ptr ) {
		if ( ! ScannedCode::shouldRunOnOrAbove( DeprecatedFunctionsSniff::VERSION ) ) {
			return;
		}
		$tokens = $phpcs_file->getTokens();
		switch ( $tokens[ $ptr ]['code'] ) {
			case \T_RETURN:
			case \T_YIELD:
			case \T_YIELD_FROM:
				$this->in_function_body( $phpcs_file, $ptr );
				return;
			case \T_FUNCTION:
				$this->function_declaration( $phpcs_file, $ptr );
				return;
			case \T_CONST:
				$this->constant_declaration( $phpcs_file, $ptr );
				return;
			case \T_STRING:
				$this->function_call( $phpcs_file, $ptr );
				return;
			default:
				$name = ObjectDeclarations::getName( $phpcs_file, $ptr );
				if ( null !== $name && in_array( strtolower( $name ), self::RESERVED, true ) ) {
					$this->reserved( $phpcs_file, $ptr, $name, strtolower( $tokens[ $ptr ]['content'] ) );
				}
		}
	}

	/**
	 * Return in finally; return values and yield in constructors and destructors.
	 *
	 * @param File $phpcs_file File.
	 * @param int  $ptr        T_RETURN, T_YIELD or T_YIELD_FROM.
	 */
	private function in_function_body( File $phpcs_file, $ptr ) {
		$tokens     = $phpcs_file->getTokens();
		$conditions = isset( $tokens[ $ptr ]['conditions'] ) ? array_reverse( $tokens[ $ptr ]['conditions'], true ) : array();
		$function   = null;
		$finally    = false;
		foreach ( $conditions as $owner => $code ) {
			if ( \T_FINALLY === $code && null === $function ) {
				$finally = true;
			}
			if ( \T_FUNCTION === $code || \T_CLOSURE === $code ) {
				$function = $owner;
				break;
			}
		}
		if ( \T_RETURN === $tokens[ $ptr ]['code'] && $finally ) {
			$phpcs_file->addWarning( 'Using "return" in a "finally" block is deprecated since PHP 8.6', $ptr, 'ReturnInFinally' );
		}
		if ( null === $function || \T_FUNCTION !== $tokens[ $function ]['code'] || ! Scopes::isOOMethod( $phpcs_file, $function ) ) {
			return;
		}
		$name = FunctionDeclarations::getName( $phpcs_file, $function );
		if ( null === $name || ! in_array( strtolower( $name ), array( '__construct', '__destruct' ), true ) ) {
			return;
		}
		if ( \T_RETURN === $tokens[ $ptr ]['code'] ) {
			$next = $phpcs_file->findNext( Tokens::$emptyTokens, $ptr + 1, null, true );
			if ( false !== $next && \T_SEMICOLON !== $tokens[ $next ]['code'] && \T_CLOSE_TAG !== $tokens[ $next ]['code'] ) {
				$phpcs_file->addWarning( 'Returning a value from %s() is deprecated since PHP 8.6', $ptr, 'ConstructorReturnValue', array( $name ) );
			}
			return;
		}
		$phpcs_file->addWarning( 'Making %s() a generator with yield is deprecated since PHP 8.6', $ptr, 'ConstructorGenerator', array( $name ) );
	}

	/**
	 * Reserved function names, and a nullable return type on __debugInfo().
	 *
	 * @param File $phpcs_file File.
	 * @param int  $ptr        T_FUNCTION.
	 */
	private function function_declaration( File $phpcs_file, $ptr ) {
		$name = FunctionDeclarations::getName( $phpcs_file, $ptr );
		if ( null === $name ) {
			return;
		}
		$lc = strtolower( $name );
		if ( Scopes::isOOMethod( $phpcs_file, $ptr ) ) {
			if ( '__debuginfo' !== $lc ) {
				return;
			}
			$props = FunctionDeclarations::getProperties( $phpcs_file, $ptr );
			$type  = strtolower( str_replace( array( ' ', '\\' ), '', (string) $props['return_type'] ) );
			if ( ( ! empty( $props['nullable_return_type'] ) && in_array( $type, array( 'array', '?array' ), true ) ) || in_array( $type, array( '?array', 'array|null', 'null|array' ), true ) ) {
				$phpcs_file->addWarning( 'A nullable return type for __debugInfo() is deprecated since PHP 8.6; Use array instead', $ptr, 'DebugInfoNullable' );
			}
			return;
		}
		if ( in_array( $lc, self::RESERVED, true ) || 'readonly' === $lc ) {
			$this->reserved( $phpcs_file, $ptr, $name, 'function' );
		}
	}

	/**
	 * "_", "let" and "is" as global constant names; "namespace" as a class constant name.
	 *
	 * @param File $phpcs_file File.
	 * @param int  $ptr        T_CONST.
	 */
	private function constant_declaration( File $phpcs_file, $ptr ) {
		$tokens = $phpcs_file->getTokens();
		$equal  = $phpcs_file->findNext( \T_EQUAL, $ptr + 1, null, false, null, true );
		if ( false === $equal ) {
			return;
		}
		$name_ptr = $phpcs_file->findPrevious( Tokens::$emptyTokens, $equal - 1, null, true );
		if ( false === $name_ptr || $name_ptr <= $ptr ) {
			return;
		}
		$name = $tokens[ $name_ptr ]['content'];
		if ( Scopes::isOOConstant( $phpcs_file, $ptr ) ) {
			if ( 'namespace' === strtolower( $name ) ) {
				$phpcs_file->addWarning( 'Using "namespace" as a class constant name is deprecated since PHP 8.6; Rename it', $ptr, 'ClassConstantNamespace' );
			}
			return;
		}
		if ( '_' === $name || in_array( strtolower( $name ), self::RESERVED, true ) ) {
			$this->reserved( $phpcs_file, $ptr, $name, 'constant' );
		}
	}

	/**
	 * Calls to define(), class_alias(), is_a() and is_subclass_of().
	 *
	 * @param File $phpcs_file File.
	 * @param int  $ptr        T_STRING.
	 */
	private function function_call( File $phpcs_file, $ptr ) {
		$tokens = $phpcs_file->getTokens();
		$lc     = strtolower( $tokens[ $ptr ]['content'] );
		if ( ! in_array( $lc, array( 'define', 'class_alias', 'is_a', 'is_subclass_of' ), true ) ) {
			return;
		}
		$next = $phpcs_file->findNext( Tokens::$emptyTokens, $ptr + 1, null, true );
		if ( false === $next || \T_OPEN_PARENTHESIS !== $tokens[ $next ]['code'] || isset( $tokens[ $next ]['parenthesis_owner'] ) ) {
			return;
		}
		$prev   = $phpcs_file->findPrevious( Tokens::$emptyTokens, $ptr - 1, null, true );
		$ignore = array(
			\T_NEW          => true,
			\T_FUNCTION     => true,
			\T_NS_SEPARATOR => true,
		) + Collections::objectOperators();
		if ( false !== $prev && isset( $ignore[ $tokens[ $prev ]['code'] ] ) ) {
			return;
		}

		if ( 'define' === $lc ) {
			$name = self::literal( PassedParameters::getParameter( $phpcs_file, $ptr, 1, 'constant_name' ) );
			if ( null !== $name && ( '_' === $name || in_array( strtolower( $name ), self::RESERVED, true ) ) ) {
				$this->reserved( $phpcs_file, $ptr, $name, 'constant' );
			}
			return;
		}
		if ( 'class_alias' === $lc ) {
			$alias = self::literal( PassedParameters::getParameter( $phpcs_file, $ptr, 2, 'alias' ) );
			if ( null !== $alias && in_array( strtolower( ltrim( $alias, '\\' ) ), self::RESERVED, true ) ) {
				$this->reserved( $phpcs_file, $ptr, $alias, 'class alias' );
			}
			return;
		}

		// is_a() defaults $allow_string to false; is_subclass_of() to true.
		$first = PassedParameters::getParameter( $phpcs_file, $ptr, 1, 'object_or_class' );
		$allow = PassedParameters::getParameter( $phpcs_file, $ptr, 3, 'allow_string' );
		$flag  = false === $allow ? ( 'is_a' === $lc ) : ( 'false' === strtolower( trim( $allow['clean'] ) ) );
		if ( ! $flag || false === $first ) {
			return;
		}
		$clean = trim( $first['clean'] );
		if ( null !== self::literal( $first ) || preg_match( '/::\s*class$/i', $clean ) ) {
			$phpcs_file->addWarning( 'Calling %s() with a class name string as the first argument is deprecated since PHP 8.6 unless $allow_string is true; Pass true as the third argument', $ptr, 'IsAWithString', array( $lc ) );
		}
	}

	/**
	 * A plain string literal's value, or null.
	 *
	 * @param array|false $param Parameter from PassedParameters.
	 * @return string|null
	 */
	private static function literal( $param ) {
		if ( false === $param || ! preg_match( '/^([\'"])(.*)\1$/s', trim( $param['clean'] ), $m ) ) {
			return null;
		}
		if ( '"' === $m[1] && false !== strpos( $m[2], '$' ) ) {
			return null; // Interpolated: not a fixed name.
		}
		return $m[2];
	}

	/**
	 * Reports a reserved name.
	 *
	 * @param File   $phpcs_file File.
	 * @param int    $ptr        Token position.
	 * @param string $name       The name.
	 * @param string $kind       What is named (class, function, constant…).
	 */
	private function reserved( File $phpcs_file, $ptr, $name, $kind ) {
		$phpcs_file->addWarning( 'Using "%s" as the name of a %s is deprecated since PHP 8.6; Rename it', $ptr, 'ReservedName', array( $name, $kind ) );
	}
}
