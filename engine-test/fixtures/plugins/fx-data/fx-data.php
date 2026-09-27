<?php
/**
 * Plugin Name: FX Data
 * Description: Broken code only inside generated data files (Composer classmap, .l10n.php translations).
 * Expect: Ready for every target 8.0-8.5, with 2 data files ignored (never scanned).
 */
function fx_data_ok() {
	return str_contains( 'abc', 'b' );
}
