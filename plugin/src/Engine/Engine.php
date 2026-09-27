<?php
/**
 * The bundled, scoped PHP_CodeSniffer + PHPCompatibilityWP engine.
 *
 * @package Airworthy
 */

namespace Airworthy\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Scans one file at a time against one exact target PHP version.
 *
 * Loaded only inside background jobs (never on normal page loads). Rules from the Step 1 test:
 * rulesets are registered in code (never CodeSniffer.conf), authors' phpcs:ignore comments are
 * ignored so they can't hide issues, and PHPCSUtils' static caches are cleared after every file
 * (without that, memory grew to 1.2 GB over 22,000 files).
 */
final class Engine {

	/** Namespace prefix PHP-Scoper gives the bundled engine (see scoper.inc.php). */
	const NS = 'Airworthy\\Vendor\\';

	/**
	 * PHP_CodeSniffer runner, holding the loaded ruleset and config.
	 *
	 * @var object
	 */
	private $runner;

	/**
	 * Engines already booted in this process, by target version.
	 *
	 * @var array<string,self>
	 */
	private static $instances = array();

	/**
	 * The engine for a target version, booted once per process. Booting loads the whole
	 * ruleset, and PHP_CodeSniffer keeps some of that in static state, so reusing it keeps
	 * memory flat when one process runs several batches (WP-CLI, or Action Scheduler).
	 *
	 * @param string $target Exact PHP version, e.g. "8.4".
	 * @return self
	 */
	public static function for_target( $target ) {
		if ( ! isset( self::$instances[ $target ] ) ) {
			self::$instances[ $target ] = new self( $target );
		}
		return self::$instances[ $target ];
	}

	/**
	 * Whether the bundled engine is present (it is only in built copies of the plugin).
	 *
	 * @return bool
	 */
	public static function is_available() {
		return is_readable( self::vendor_dir() . 'squizlabs/php_codesniffer/autoload.php' );
	}

	/**
	 * Path to the scoped vendor folder.
	 *
	 * @return string
	 */
	private static function vendor_dir() {
		return AIRWORTHY_DIR . 'vendor/';
	}

	/**
	 * Boots PHP_CodeSniffer with the PHPCompatibilityWP ruleset for one target version.
	 *
	 * @param string $target Exact PHP version, e.g. "8.4".
	 * @throws \RuntimeException When the engine is missing or fails to start.
	 */
	private function __construct( $target ) {
		if ( ! self::is_available() ) {
			throw new \RuntimeException( 'The scan engine is missing from this copy of the plugin.' );
		}
		$vendor = self::vendor_dir();
		require_once $vendor . 'autoload.php';
		require_once $vendor . 'squizlabs/php_codesniffer/autoload.php';

		if ( ! defined( 'PHP_CODESNIFFER_CBF' ) ) {
			define( 'PHP_CODESNIFFER_CBF', false );
		}
		if ( ! defined( 'PHP_CODESNIFFER_VERBOSITY' ) ) {
			define( 'PHP_CODESNIFFER_VERBOSITY', 0 );
		}
		class_exists( self::NS . 'PHP_CodeSniffer\\Util\\Tokens' ); // Defines the T_* token constants.

		$config_class = self::NS . 'PHP_CodeSniffer\\Config';
		$runner_class = self::NS . 'PHP_CodeSniffer\\Runner';

		$config = new $config_class(
			array(
				'--standard=PHPCompatibilityWP',
				'--runtime-set',
				'testVersion',
				$target,
				'--extensions=php',
				'--ignore-annotations',
				'-q',
			)
		);
		// Temporary (in-memory) setting: never writes a config file to disk.
		$config->setConfigData( 'installed_paths', implode( ',', array_filter( (array) glob( $vendor . 'phpcompatibility/*' ), 'is_dir' ) ), true );

		$this->runner         = new $runner_class();
		$this->runner->config = $config;
		$this->runner->init();
	}

	/**
	 * Interfaces passed to the RemovedSerializable rule for the current component.
	 *
	 * @var string[]|null
	 */
	private $serializable;

	/**
	 * Tells the RemovedSerializable rule which of this component's interfaces extend
	 * Serializable, so it can spot classes that implement Serializable indirectly.
	 *
	 * @param string[] $interfaces Interface names.
	 */
	public function set_serializable_interfaces( array $interfaces ) {
		sort( $interfaces );
		if ( $interfaces === $this->serializable ) {
			return;
		}
		$sniff = self::NS . 'PHPCompatibility\\Sniffs\\Interfaces\\RemovedSerializableSniff';
		if ( isset( $this->runner->ruleset->sniffs[ $sniff ] ) ) {
			$this->runner->ruleset->setSniffProperty(
				$sniff,
				'serializableInterfaces',
				array(
					'scope' => 'sniff',
					'value' => $interfaces,
				)
			);
		}
		$this->serializable = $interfaces;
	}

	/**
	 * Scans one file. The file is only read as text, never run.
	 *
	 * @param string $path Absolute path.
	 * @return array<int,array{line:int,severity:string,rule:string,message:string}>
	 */
	public function scan_file( $path ) {
		$file_class = self::NS . 'PHP_CodeSniffer\\Files\\LocalFile';
		$file       = new $file_class( $path, $this->runner->ruleset, $this->runner->config );
		$issues     = array();

		try {
			$file->process();
			foreach ( array(
				'error'   => $file->getErrors(),
				'warning' => $file->getWarnings(),
			) as $severity => $by_line ) {
				foreach ( $by_line as $line => $columns ) {
					foreach ( $columns as $messages ) {
						foreach ( $messages as $m ) {
							$issues[] = array(
								'line'     => (int) $line,
								'severity' => $severity,
								'rule'     => (string) $m['source'],
								'message'  => (string) $m['message'],
							);
						}
					}
				}
			}
		} finally {
			$file->cleanUp();
			unset( $file );
			self::clear_caches();
		}

		return $issues;
	}

	/**
	 * Frees PHPCSUtils' static per-file caches, which otherwise grow for the life of the process.
	 */
	private static function clear_caches() {
		foreach ( array( 'PHPCSUtils\\Internal\\Cache', 'PHPCSUtils\\Internal\\NoFileCache' ) as $class ) {
			$class = self::NS . $class;
			if ( class_exists( $class, false ) ) {
				$class::clear();
			}
		}
	}
}
