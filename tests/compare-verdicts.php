<?php
/**
 * Compares `wp airworthy scan --format=json` output with tests/expected-verdicts.json.
 *
 * Usage: php compare-verdicts.php <expected.json> <target> <scan output.json>
 * Exit code 1 on any difference.
 */

list( , $expected_file, $target, $output_file ) = $argv;
$expected = json_decode( (string) file_get_contents( $expected_file ), true );
$raw      = (string) file_get_contents( $output_file );
// WP-CLI may print notices before the JSON: take the JSON array only.
$start = strpos( $raw, '[' );
$rows  = false === $start ? null : json_decode( substr( $raw, $start ), true );
if ( ! is_array( $rows ) ) {
	echo "FAIL PHP $target: could not read scan output:\n$raw\n";
	exit( 1 );
}
$got = array();
foreach ( $rows as $row ) {
	$got[ $row['slug'] ] = $row;
}

$pass = 0;
$fail = array();
foreach ( $expected['verdicts'] as $slug => $by_target ) {
	$want = isset( $by_target[ $target ] ) ? $by_target[ $target ] : $by_target['*'];
	$have = isset( $got[ $slug ] ) ? $got[ $slug ]['verdict'] : '(missing)';
	if ( $want === $have ) {
		++$pass;
	} else {
		$fail[] = "$slug: expected $want, got $have";
	}
	if ( isset( $expected['wporg'][ $slug ] ) ) {
		$want_w = $expected['wporg'][ $slug ];
		$want_w = is_array( $want_w ) ? ( isset( $want_w[ $target ] ) ? $want_w[ $target ] : $want_w['*'] ) : $want_w;
		$have_w = isset( $got[ $slug ] ) ? (string) $got[ $slug ]['wporg'] : '(missing)';
		if ( $want_w === $have_w ) {
			++$pass;
		} else {
			$fail[] = "$slug WordPress.org: expected \"$want_w\", got \"$have_w\"";
		}
	}
}
printf( "%s PHP %s: %d/%d\n", $fail ? 'FAIL' : 'PASS', $target, $pass, $pass + count( $fail ) );
foreach ( $fail as $line ) {
	echo "     $line\n";
}
exit( $fail ? 1 : 0 );
