<?php
/**
 * Plain-English explanations for findings.
 *
 * @package Airworthy
 */

namespace Airworthy\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a finding (rule, message, severity, context) into what it means, what happens on the
 * PHP version checked, how to fix it, and, for the common cases, a short before/after example.
 *
 * Hand-written entries cover the rules seen most in real plugins (the top-30 runs, see
 * docs/ACCURACY.md); everything else gets a family explanation built from the message (the
 * function, extension, constant or setting it names, and PHPCompatibility's suggested
 * replacement). Code examples are never translated.
 */
final class Explain {

	/**
	 * The explanation for one finding.
	 *
	 * @param string $rule     Rule code, e.g. "PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved".
	 * @param string $message  Finding message.
	 * @param string $severity error | warning | notice | scan.
	 * @param string $context  plain | guarded | suppressed | existing.
	 * @param string $target   PHP version checked, e.g. "8.4".
	 * @return array{what:string,effect:string,fix:string,before:string,after:string}|null
	 */
	public static function issue( $rule, $message, $severity, $context, $target ) {
		if ( ! in_array( $severity, array( 'error', 'warning' ), true ) ) {
			return null; // Scan problems and notices explain themselves.
		}
		$entry = self::entry( (string) $rule, (string) $message );
		if ( ! $entry ) {
			$entry = array(
				'what' => '',
				'fix'  => __( 'Update the plugin or theme: newer versions often fix this. If there is no update, send this finding to its developer.', 'airworthy' ),
			);
		}
		$guarded = 'guarded' === $context; // Code that never runs: nothing to fix.
		return array(
			'what'   => $entry['what'],
			'effect' => self::effect( (string) $rule, (string) $message, $severity, $context, $target ),
			'fix'    => $guarded ? '' : $entry['fix'],
			'before' => ! $guarded && isset( $entry['before'] ) ? $entry['before'] : '',
			'after'  => ! $guarded && isset( $entry['after'] ) ? $entry['after'] : '',
		);
	}

	/**
	 * One line on what happens, from the severity and Airworthy's classification.
	 *
	 * @param string $rule     Rule code.
	 * @param string $message  Message.
	 * @param string $severity error | warning.
	 * @param string $context  plain | guarded | suppressed | existing.
	 * @param string $target   PHP version checked.
	 * @return string
	 */
	private static function effect( $rule, $message, $severity, $context, $target ) {
		switch ( $context ) {
			case 'guarded':
				/* translators: %s: PHP version. */
				return sprintf( __( 'Nothing to do: Airworthy found this code can never run on PHP %s, so it can\'t break anything.', 'airworthy' ), $target );
			case 'suppressed':
				/* translators: %s: PHP version. */
				return sprintf( __( 'The plugin\'s authors marked this line as intentional. Usually safe, but look here first if the plugin misbehaves on PHP %s.', 'airworthy' ), $target );
			case 'existing':
				return __( 'This already applies on the PHP version your site runs now, so it isn\'t caused by the upgrade: that code either never runs today or is already failing.', 'airworthy' );
		}
		if ( 'error' === $severity ) {
			if ( preg_match( '/\.New[A-Z]/', $rule ) || false !== stripos( $message, 'not present in PHP version' ) || false !== stripos( $message, 'not supported in PHP' ) ) {
				/* translators: %s: PHP version. */
				return sprintf( __( 'This code needs a newer PHP version than %1$s. On %1$s it stops with a fatal error if it runs, unless the plugin only runs it on newer PHP.', 'airworthy' ), $target );
			}
			/* translators: %s: PHP version. */
			return sprintf( __( 'On PHP %s this stops with a fatal error when the code runs: the page, form or task it belongs to fails.', 'airworthy' ), $target );
		}
		if ( false !== stripos( $message, 'deprecated' ) ) {
			/* translators: %s: PHP version. */
			return sprintf( __( 'Still works on PHP %s, but PHP logs a deprecation notice each time it runs, and a later PHP version will stop it working.', 'airworthy' ), $target );
		}
		/* translators: %s: PHP version. */
		return sprintf( __( 'Not an error on PHP %s: worth a look, because the code may behave differently or this may be a false lead.', 'airworthy' ), $target );
	}

	/**
	 * The hand-written entry, else the family entry, for a rule.
	 *
	 * @param string $rule    Rule code.
	 * @param string $message Message.
	 * @return array{what:string,fix:string,before?:string,after?:string}|null
	 */
	private static function entry( $rule, $message ) {
		$parts = explode( '.', $rule );
		$sniff = isset( $parts[2] ) ? $parts[1] . '.' . $parts[2] : '';
		$code  = isset( $parts[3] ) ? $parts[3] : '';
		$alt   = preg_match( '/; Use (.+?) instead/', $message, $m ) ? rtrim( $m[1], '.' ) : '';

		// Functions: PHPCompatibility's removed/deprecated functions and our own PHP 8.6 list.
		if ( 'FunctionUse.RemovedFunctions' === $sniff || 'PHP86.DeprecatedFunctions' === $sniff ) {
			$name = preg_match( '/Function ([\w\\\\]+)\(\)/', $message, $m ) ? $m[1] : '';
			return self::function_entry( strtolower( $name ), $name, $message, $alt );
		}

		switch ( $sniff ) {
			case 'Extensions.RemovedExtensions':
				$ext = preg_match( "/Extension '([^']+)'/", $message, $m ) ? $m[1] : '';
				if ( 'mysql_' === $ext ) {
					return array(
						'what'   => __( 'Uses PHP\'s old mysql_* database functions, which PHP 7.0 removed.', 'airworthy' ),
						'fix'    => __( 'Use WordPress\'s database layer ($wpdb), or mysqli.', 'airworthy' ),
						'before' => '$rows = mysql_query( "SELECT * FROM $table" );',
						'after'  => '$rows = $wpdb->get_results( "SELECT * FROM $table" );',
					);
				}
				if ( 'mcrypt' === $ext ) {
					return array(
						'what'   => __( 'Uses the mcrypt encryption extension, which PHP 7.2 removed.', 'airworthy' ),
						'fix'    => __( 'Use openssl_encrypt() and openssl_decrypt(), or sodium.', 'airworthy' ),
						'before' => '$out = mcrypt_encrypt( MCRYPT_RIJNDAEL_128, $key, $data, MCRYPT_MODE_CBC, $iv );',
						'after'  => '$out = openssl_encrypt( $data, \'aes-128-cbc\', $key, OPENSSL_RAW_DATA, $iv );',
					);
				}
				return array(
					/* translators: %s: PHP extension name. */
					'what' => sprintf( __( 'Uses the %s extension, which newer PHP versions no longer include.', 'airworthy' ), $ext ),
					/* translators: %s: replacement suggested by the checker. */
					'fix'  => '' !== $alt ? sprintf( __( 'Use %s instead.', 'airworthy' ), $alt ) : __( 'Replace it with code that doesn\'t need the extension.', 'airworthy' ),
				);

			case 'Constants.RemovedConstants':
			case 'PHP86.DeprecatedConstants':
				$const = preg_match( '/constant "([^"]+)"/', $message, $m ) ? $m[1] : '';
				return array(
					/* translators: %s: constant name. */
					'what' => sprintf( __( 'Uses the constant %s, which newer PHP versions deprecate or remove.', 'airworthy' ), $const ),
					/* translators: %s: replacement suggested by the checker. */
					'fix'  => '' !== $alt ? sprintf( __( 'Use %s instead.', 'airworthy' ), $alt ) : __( 'Remove it, or use the newer way PHP offers for the same thing.', 'airworthy' ),
				);

			case 'IniDirectives.RemovedIniDirectives':
				$ini = preg_match( "/INI directive '([^']+)'/", $message, $m ) ? $m[1] : '';
				return array(
					/* translators: %s: PHP setting name. */
					'what'   => sprintf( __( 'Reads or changes the PHP setting %s, which newer PHP versions removed.', 'airworthy' ), $ini ),
					'fix'    => __( 'Remove the ini_set() or ini_get() call for it: the setting no longer does anything.', 'airworthy' ),
					'before' => "ini_set( '" . $ini . "', 1 );",
					'after'  => '// Removed: PHP no longer has this setting.',
				);

			case 'Variables.RemovedPredefinedGlobalVariables':
				if ( false !== strpos( $message, 'HTTP_RAW_POST_DATA' ) ) {
					return array(
						'what'   => __( 'Reads $HTTP_RAW_POST_DATA, which PHP 7.0 removed.', 'airworthy' ),
						'fix'    => __( 'Read the raw request body from php://input.', 'airworthy' ),
						'before' => '$body = $HTTP_RAW_POST_DATA;',
						'after'  => '$body = file_get_contents( \'php://input\' );',
					);
				}
				if ( false !== strpos( $message, 'php_errormsg' ) ) {
					return array(
						'what'   => __( 'Reads $php_errormsg, which PHP 8.0 removed.', 'airworthy' ),
						'fix'    => __( 'Use error_get_last() instead.', 'airworthy' ),
						'before' => '$error = $php_errormsg;',
						'after'  => '$last  = error_get_last();' . "\n" . '$error = $last ? $last[\'message\'] : \'\';',
					);
				}
				return array(
					'what' => __( 'Reads a global variable PHP no longer sets.', 'airworthy' ),
					/* translators: %s: replacement suggested by the checker. */
					'fix'  => '' !== $alt ? sprintf( __( 'Use %s instead.', 'airworthy' ), $alt ) : __( 'Get the value another way.', 'airworthy' ),
				);

			case 'FunctionDeclarations.RemovedImplicitlyNullableParam':
				return array(
					'what'   => __( 'A parameter has a type and a default of null, but its type doesn\'t say it can be null. PHP 8.4 wants that written out.', 'airworthy' ),
					'fix'    => __( 'Put a ? in front of the type.', 'airworthy' ),
					'before' => 'function save( WP_Post $post = null ) {',
					'after'  => 'function save( ?WP_Post $post = null ) {',
				);

			case 'FunctionDeclarations.RemovedOptionalBeforeRequiredParam':
				return array(
					'what'   => __( 'A function has an optional parameter before a required one, so the default can never be used.', 'airworthy' ),
					'fix'    => __( 'Remove the default from the earlier parameter, or move the optional parameters to the end.', 'airworthy' ),
					'before' => 'function add_item( $name = \'\', $price ) {',
					'after'  => 'function add_item( $name, $price ) {',
				);

			case 'FunctionDeclarations.RemovedCallingDestructAfterConstructorExit':
				return array(
					'what' => __( 'A constructor calls exit() or die(). Since PHP 8.0 the object\'s destructor no longer runs after that.', 'airworthy' ),
					'fix'  => __( 'Usually nothing to do. If clean-up happens in __destruct(), do it before exiting.', 'airworthy' ),
				);

			case 'FunctionDeclarations.NewParamTypeDeclarations':
				return array(
					'what'   => __( 'Uses "integer", "boolean" or "double" as a type. PHP reads that as a class name, not as int, bool or float.', 'airworthy' ),
					'fix'    => __( 'Use the short type name.', 'airworthy' ),
					'before' => 'function set_count( integer $count ) {',
					'after'  => 'function set_count( int $count ) {',
				);

			case 'FunctionDeclarations.NewClosure':
				if ( 'ThisFoundInStatic' === $code ) {
					return array(
						'what'   => __( 'A static closure uses $this, which a static closure never has. PHP throws an Error when it runs, on every version.', 'airworthy' ),
						'fix'    => __( 'Remove "static" from the closure, or don\'t use $this inside it.', 'airworthy' ),
						'before' => "'permission_callback' => static function () { return \$this->can_edit(); },",
						'after'  => "'permission_callback' => function () { return \$this->can_edit(); },",
					);
				}
				return array(
					'what' => __( 'A closure uses $this where there may be no object to point to.', 'airworthy' ),
					'fix'  => __( 'Check the closure is created inside a class method, or pass the object in with use ().', 'airworthy' ),
				);

			case 'ParameterValues.RemovedImplodeFlexibleParamOrder':
				return array(
					'what'   => __( 'Calls implode() with its two arguments the wrong way round. PHP 7.4 warned about it; PHP 8.0 stops with a fatal error.', 'airworthy' ),
					'fix'    => __( 'Put the separator first.', 'airworthy' ),
					'before' => '$list = implode( $names, \', \' );',
					'after'  => '$list = implode( \', \', $names );',
				);

			case 'ParameterValues.RemovedTriggerErrorLevel':
				return array(
					'what'   => __( 'Calls trigger_error() with E_USER_ERROR, which PHP 8.4 deprecated.', 'airworthy' ),
					'fix'    => __( 'Throw an exception instead, or stop with wp_die() in WordPress code.', 'airworthy' ),
					'before' => 'trigger_error( \'Missing settings\', E_USER_ERROR );',
					'after'  => 'throw new RuntimeException( \'Missing settings\' );',
				);

			case 'ParameterValues.RemovedGetClassNoArgs':
				return array(
					'what'   => __( 'Calls get_class() with no argument inside a class, which PHP 8.3 deprecated.', 'airworthy' ),
					'fix'    => __( 'Use self::class (or static::class for the actual class).', 'airworthy' ),
					'before' => '$name = get_class();',
					'after'  => '$name = self::class;',
				);

			case 'ParameterValues.RemovedProprietaryCSVEscaping':
				return array(
					'what'   => __( 'Calls fgetcsv(), fputcsv() or str_getcsv() without the $escape argument. Its default is changing, so PHP 8.4 wants it passed.', 'airworthy' ),
					'fix'    => __( 'Pass the escape character: an empty string for standard CSV, or a backslash to keep today\'s behaviour.', 'airworthy' ),
					'before' => '$row = fgetcsv( $handle, 0, \',\' );',
					'after'  => '$row = fgetcsv( $handle, 0, \',\', \'"\', \'\' );',
				);

			case 'FunctionUse.ArgumentFunctionsReportCurrentValue':
				return array(
					'what' => __( 'Uses func_get_args(), debug_backtrace() or similar after changing a parameter. Since PHP 7.0 they show the changed value, not the original.', 'airworthy' ),
					'fix'  => __( 'Usually nothing to do. If the code needs the original value, read it before changing the parameter.', 'airworthy' ),
				);

			case 'FunctionNameRestrictions.ReservedFunctionNames':
				return array(
					'what' => __( 'A function or method name starts with two underscores (__), which PHP keeps for its own magic methods.', 'airworthy' ),
					'fix'  => __( 'Nothing breaks today. Renaming it avoids a clash with a future PHP version.', 'airworthy' ),
				);

			case 'FunctionNameRestrictions.RemovedPHP4StyleConstructors':
				return array(
					'what'   => __( 'A class uses a method with the same name as the class as its constructor. PHP 8.0 no longer calls it automatically.', 'airworthy' ),
					'fix'    => __( 'Rename the method to __construct().', 'airworthy' ),
					'before' => "class Gallery {\n\tfunction Gallery( \$id ) {",
					'after'  => "class Gallery {\n\tfunction __construct( \$id ) {",
				);

			case 'Keywords.ForbiddenNames':
				return array(
					'what' => __( 'Uses a word PHP reserves (such as resource, enum or match) as the name of a class, function or constant.', 'airworthy' ),
					'fix'  => __( 'Rename it to something that isn\'t a PHP keyword.', 'airworthy' ),
				);

			case 'Interfaces.RemovedSerializable':
				return array(
					'what'   => __( 'A class implements Serializable. Since PHP 8.1 it should also have __serialize() and __unserialize(); the old interface will go.', 'airworthy' ),
					'fix'    => __( 'Add __serialize() and __unserialize(). Once the plugin needs PHP 7.4 or newer, the Serializable methods can go.', 'airworthy' ),
					'before' => "class Cart implements Serializable {\n\tpublic function serialize() { return serialize( \$this->items ); }",
					'after'  => "class Cart implements Serializable {\n\tpublic function __serialize(): array { return array( 'items' => \$this->items ); }",
				);

			case 'Miscellaneous.RemovedAlternativePHPTags':
				return array(
					'what' => __( 'Found text that looks like an old <% %> PHP tag. It\'s usually a JavaScript template (Underscore or Backbone), which is fine.', 'airworthy' ),
					'fix'  => __( 'Nothing to do if it\'s inside a script template or a .js file.', 'airworthy' ),
				);

			case 'Variables.ForbiddenGlobalVariableVariable':
				return array(
					'what'   => __( 'Uses a "global" statement with a variable variable, which PHP 7.0 no longer supports in that form.', 'airworthy' ),
					'fix'    => __( 'Use $GLOBALS instead.', 'airworthy' ),
					'before' => 'global ${$name};',
					'after'  => '$value = $GLOBALS[ $name ];',
				);

			case 'Syntax.RemovedCurlyBraceArrayAccess':
				return array(
					'what'   => __( 'Reads a character or array item with curly braces, which PHP 8.0 removed.', 'airworthy' ),
					'fix'    => __( 'Use square brackets.', 'airworthy' ),
					'before' => '$first = $text{0};',
					'after'  => '$first = $text[0];',
				);

			case 'Operators.RemovedTernaryAssociativity':
				return array(
					'what'   => __( 'Chains two ?: operators without brackets, which PHP 8.0 no longer allows.', 'airworthy' ),
					'fix'    => __( 'Add brackets to show which part belongs together.', 'airworthy' ),
					'before' => '$size = $a ? \'small\' : $b ? \'medium\' : \'large\';',
					'after'  => '$size = $a ? \'small\' : ( $b ? \'medium\' : \'large\' );',
				);

			case 'TextStrings.RemovedDollarBraceStringEmbeds':
				return array(
					'what'   => __( 'Puts a variable in a string with ${…}, which PHP 8.2 deprecated.', 'airworthy' ),
					'fix'    => __( 'Use {$…} instead.', 'airworthy' ),
					'before' => '$text = "Hello ${name}";',
					'after'  => '$text = "Hello {$name}";',
				);

			case 'TypeCasts.RemovedTypeCasts':
				return array(
					'what'   => __( 'Uses the (real) or (unset) cast, which newer PHP versions removed.', 'airworthy' ),
					'fix'    => __( 'Use (float) instead of (real); replace (unset) with null.', 'airworthy' ),
					'before' => '$price = (real) $input;',
					'after'  => '$price = (float) $input;',
				);

			case 'ParameterValues.RemovedSplAutoloadRegisterThrowFalse':
				return array(
					'what'   => __( 'Passes false as the $throw argument of spl_autoload_register(). PHP 8.0 ignores it and always throws, so the argument is deprecated.', 'airworthy' ),
					'fix'    => __( 'Leave the argument out.', 'airworthy' ),
					'before' => 'spl_autoload_register( $loader, false );',
					'after'  => 'spl_autoload_register( $loader );',
				);

			case 'FunctionUse.RemovedFunctionParameters':
				return array(
					'what' => __( 'Passes an argument that newer PHP versions no longer accept for this function.', 'airworthy' ),
					'fix'  => __( 'Remove that argument from the call; the message above says which one.', 'airworthy' ),
				);

			// Our own PHP 8.6 checks.
			case 'PHP86.DeprecatedSyntax':
				return self::php86_entry( $code );
		}

		// Newer features used on an older target (code written for a newer PHP than checked).
		if ( preg_match( '/^[A-Za-z]+\.New[A-Z]/', $sniff ) ) {
			return array(
				'what' => __( 'Uses something PHP added in a newer version than the one you\'re checking.', 'airworthy' ),
				'fix'  => __( 'Check the plugin\'s "Requires PHP" on WordPress.org: it may need a newer PHP version. Often the code only runs on newer PHP, and is safe.', 'airworthy' ),
			);
		}
		return null;
	}

	/**
	 * Removed or deprecated functions: hand-written for the common ones, otherwise from the message.
	 *
	 * @param string $lc      Function name, lower case.
	 * @param string $name    Function name as written.
	 * @param string $message Message.
	 * @param string $alt     Suggested replacement, or ''.
	 * @return array{what:string,fix:string,before?:string,after?:string}
	 */
	private static function function_entry( $lc, $name, $message, $alt ) {
		$known = array(
			'each'                 => array(
				__( 'Loops with each(), which PHP 8.0 removed.', 'airworthy' ),
				__( 'Use a foreach loop.', 'airworthy' ),
				'while ( list( $key, $value ) = each( $items ) ) {',
				'foreach ( $items as $key => $value ) {',
			),
			'create_function'      => array(
				__( 'Builds a function from a string with create_function(), which PHP 8.0 removed.', 'airworthy' ),
				__( 'Write an anonymous function instead.', 'airworthy' ),
				'$double = create_function( \'$n\', \'return $n * 2;\' );',
				'$double = function ( $n ) { return $n * 2; };',
			),
			'get_magic_quotes_gpc' => array(
				__( 'Checks get_magic_quotes_gpc(), which PHP 8.0 removed (magic quotes went in PHP 5.4).', 'airworthy' ),
				__( 'Remove the check. In WordPress, use wp_unslash() on request data.', 'airworthy' ),
				'if ( get_magic_quotes_gpc() ) { $value = stripslashes( $value ); }',
				'$value = wp_unslash( $value );',
			),
			'money_format'         => array(
				__( 'Formats money with money_format(), which PHP 8.0 removed.', 'airworthy' ),
				__( 'Use NumberFormatter (intl), or number_format() with your own currency symbol.', 'airworthy' ),
				'$text = money_format( \'%i\', $amount );',
				'$text = ( new NumberFormatter( \'en_IE\', NumberFormatter::CURRENCY ) )->formatCurrency( $amount, \'EUR\' );',
			),
			'utf8_encode'          => array(
				__( 'Converts text with utf8_encode(), which PHP 8.2 deprecated.', 'airworthy' ),
				__( 'Use mb_convert_encoding().', 'airworthy' ),
				'$text = utf8_encode( $latin1 );',
				'$text = mb_convert_encoding( $latin1, \'UTF-8\', \'ISO-8859-1\' );',
			),
			'utf8_decode'          => array(
				__( 'Converts text with utf8_decode(), which PHP 8.2 deprecated.', 'airworthy' ),
				__( 'Use mb_convert_encoding().', 'airworthy' ),
				'$latin1 = utf8_decode( $text );',
				'$latin1 = mb_convert_encoding( $text, \'ISO-8859-1\', \'UTF-8\' );',
			),
			'strftime'             => array(
				__( 'Formats dates with strftime(), which PHP 8.1 deprecated.', 'airworthy' ),
				__( 'In WordPress, use wp_date() (it also translates month and day names).', 'airworthy' ),
				'$date = strftime( \'%d %B %Y\', $timestamp );',
				'$date = wp_date( \'d F Y\', $timestamp );',
			),
			'spl_object_hash'      => array(
				__( 'Uses spl_object_hash() to identify an object, which PHP 8.6 deprecates.', 'airworthy' ),
				__( 'Use spl_object_id().', 'airworthy' ),
				'$key = spl_object_hash( $object );',
				'$key = spl_object_id( $object );',
			),
			'is_integer'           => array(
				__( 'Checks a number with is_integer(), which PHP 8.6 deprecates.', 'airworthy' ),
				__( 'Use is_int(): it does the same.', 'airworthy' ),
				'if ( is_integer( $value ) ) {',
				'if ( is_int( $value ) ) {',
			),
			'is_long'              => array(
				__( 'Checks a number with is_long(), which PHP 8.6 deprecates.', 'airworthy' ),
				__( 'Use is_int(): it does the same.', 'airworthy' ),
				'if ( is_long( $value ) ) {',
				'if ( is_int( $value ) ) {',
			),
			'curl_close'           => array(
				__( 'Calls curl_close(), which PHP 8.5 deprecated: it has done nothing since PHP 8.0.', 'airworthy' ),
				__( 'Remove the call; the handle closes by itself.', 'airworthy' ),
				'curl_close( $ch );',
				'// Removed: nothing to close since PHP 8.0.',
			),
		);
		if ( isset( $known[ $lc ] ) ) {
			list( $what, $fix, $before, $after ) = $known[ $lc ];
			return compact( 'what', 'fix', 'before', 'after' );
		}
		if ( 0 === strpos( $lc, 'mysql_' ) ) {
			return array(
				/* translators: %s: function name. */
				'what'   => sprintf( __( 'Calls %s(), one of PHP\'s old mysql_* database functions, which PHP 7.0 removed.', 'airworthy' ), $name ),
				'fix'    => __( 'Use WordPress\'s database layer ($wpdb, and esc_sql() or $wpdb->prepare() for escaping), or mysqli.', 'airworthy' ),
				'before' => '$safe = mysql_real_escape_string( $input );',
				'after'  => '$sql = $wpdb->prepare( "SELECT * FROM $table WHERE name = %s", $input );',
			);
		}
		if ( 0 === strpos( $lc, 'mcrypt_' ) ) {
			return array(
				/* translators: %s: function name. */
				'what' => sprintf( __( 'Calls %s(), part of the mcrypt encryption extension, which PHP 7.2 removed.', 'airworthy' ), $name ),
				'fix'  => __( 'Use openssl_encrypt() and openssl_decrypt(), or sodium.', 'airworthy' ),
			);
		}
		$removed = false !== stripos( $message, 'removed' );
		return array(
			'what' => $removed ?
				/* translators: %s: function name. */
				sprintf( __( 'Calls %s(), which newer PHP versions removed.', 'airworthy' ), $name ) :
				/* translators: %s: function name. */
				sprintf( __( 'Calls %s(), which newer PHP versions deprecate.', 'airworthy' ), $name ),
			'fix'  => '' !== $alt ?
				/* translators: %s: replacement suggested by the checker. */
				sprintf( __( 'Use %s instead.', 'airworthy' ), $alt ) :
				__( 'Remove the call, or do the same thing another way: PHP has no direct replacement.', 'airworthy' ),
		);
	}

	/**
	 * Airworthy's own PHP 8.6 syntax checks.
	 *
	 * @param string $code Error code.
	 * @return array{what:string,fix:string,before?:string,after?:string}|null
	 */
	private static function php86_entry( $code ) {
		switch ( $code ) {
			case 'ReturnInFinally':
				return array(
					'what' => __( 'A "finally" block returns a value, which overrides any return or exception from the "try" block. PHP 8.6 deprecates it.', 'airworthy' ),
					'fix'  => __( 'Return after the try/finally instead, or move the return into the try block.', 'airworthy' ),
				);
			case 'ConstructorReturnValue':
				return array(
					'what'   => __( 'A constructor or destructor returns a value. PHP ignores it, and PHP 8.6 deprecates it.', 'airworthy' ),
					'fix'    => __( 'Remove the value (a plain "return;" is fine). If the result matters, move that work into a normal method.', 'airworthy' ),
					'before' => 'public function __construct( $id ) {' . "\n\t" . 'return $this->load( $id );',
					'after'  => 'public function __construct( $id ) {' . "\n\t" . '$this->load( $id );',
				);
			case 'ConstructorGenerator':
				return array(
					'what' => __( 'A constructor or destructor uses yield, which PHP 8.6 deprecates.', 'airworthy' ),
					'fix'  => __( 'Move the yield into a separate method.', 'airworthy' ),
				);
			case 'ClassConstantNamespace':
				return array(
					'what'   => __( 'A class has a constant named NAMESPACE. PHP 8.6 reserves the word for future syntax.', 'airworthy' ),
					'fix'    => __( 'Rename the constant, for example to REST_NAMESPACE.', 'airworthy' ),
					'before' => 'const NAMESPACE = \'my-plugin/v1\';',
					'after'  => 'const REST_NAMESPACE = \'my-plugin/v1\';',
				);
			case 'ReservedName':
				return array(
					'what' => __( 'Something is named "let", "is", "readonly" or "_", which PHP 8.6 reserves. Often it\'s a bundled library (such as Guzzle\'s Is class).', 'airworthy' ),
					'fix'  => __( 'If it\'s a bundled library, an update of the plugin will bring the fixed version. Otherwise rename it.', 'airworthy' ),
				);
			case 'IsAWithString':
				return array(
					'what'   => __( 'Calls is_a() or is_subclass_of() with a class name as text but without allowing strings. PHP 8.6 deprecates that.', 'airworthy' ),
					'fix'    => __( 'Pass true as the third argument.', 'airworthy' ),
					'before' => 'if ( is_a( $class_name, \'WC_Product\' ) ) {',
					'after'  => 'if ( is_a( $class_name, \'WC_Product\', true ) ) {',
				);
			case 'DebugInfoNullable':
				return array(
					'what'   => __( '__debugInfo() is declared as returning ?array. PHP 8.6 wants array.', 'airworthy' ),
					'fix'    => __( 'Return an empty array instead of null.', 'airworthy' ),
					'before' => 'public function __debugInfo(): ?array {',
					'after'  => 'public function __debugInfo(): array {',
				);
		}
		return null;
	}
}
