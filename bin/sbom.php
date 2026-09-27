<?php
/**
 * Writes a CycloneDX 1.5 software bill of materials for the release build.
 *
 * Usage: php bin/sbom.php <plugin version> <composer.lock> <output file>
 *
 * Lists Airworthy itself and every library shipped in the zip, with exact versions, licences,
 * package URLs and the commit each was built from. Composer's own build helpers (not shipped)
 * are left out. Run by bin/build.sh; the output is deterministic for a given lock file.
 *
 * @package Airworthy
 */

if ( 4 !== $argc ) {
	fwrite( STDERR, "Usage: php bin/sbom.php <version> <composer.lock> <output>\n" );
	exit( 1 );
}
list( , $version, $lock_file, $out ) = $argv;

$not_shipped = array( 'dealerdirect/phpcodesniffer-composer-installer' );
$lock        = json_decode( (string) file_get_contents( $lock_file ), true );
if ( ! is_array( $lock ) || empty( $lock['packages'] ) ) {
	fwrite( STDERR, "Cannot read $lock_file\n" );
	exit( 1 );
}

$licences = function ( array $ids ) {
	return array_map(
		function ( $id ) {
			return array( 'license' => array( 'id' => $id ) );
		},
		$ids
	);
};

$components = array();
$deps       = array();
foreach ( $lock['packages'] as $p ) {
	if ( in_array( $p['name'], $not_shipped, true ) ) {
		continue;
	}
	list( $group, $name ) = explode( '/', $p['name'], 2 );
	$ver                  = ltrim( $p['version'], 'v' );
	$ref                  = 'pkg:composer/' . $p['name'] . '@' . $ver;
	$component            = array(
		'type'     => 'library',
		'bom-ref'  => $ref,
		'group'    => $group,
		'name'     => $name,
		'version'  => $ver,
		'licenses' => $licences( isset( $p['license'] ) ? $p['license'] : array() ),
		'purl'     => $ref,
	);
	if ( ! empty( $p['description'] ) ) {
		$component['description'] = $p['description'];
	}
	if ( ! empty( $p['source']['url'] ) ) {
		$component['externalReferences'] = array(
			array(
				'type'    => 'vcs',
				'url'     => $p['source']['url'],
				'comment' => 'Built from commit ' . $p['source']['reference'],
			),
		);
	}
	if ( 'woocommerce/action-scheduler' !== $p['name'] ) {
		$component['properties'] = array(
			array(
				'name'  => 'airworthy:modification',
				'value' => 'Namespace-prefixed into Airworthy\\Vendor by PHP-Scoper (bin/build.sh).',
			),
		);
	}
	$components[] = $component;
	$deps[]       = $ref;
}

$self = 'pkg:wordpress-plugin/airworthy@' . $version;
$bom  = array(
	'bomFormat'    => 'CycloneDX',
	'specVersion'  => '1.5',
	'version'      => 1,
	'metadata'     => array(
		'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z', filemtime( $lock_file ) ),
		'component' => array(
			'type'               => 'application',
			'bom-ref'            => $self,
			'name'               => 'airworthy',
			'version'            => $version,
			'description'        => 'Airworthy – PHP Compatibility & Upgrade Checker (WordPress plugin)',
			'licenses'           => $licences( array( 'GPL-2.0-or-later' ) ),
			'supplier'           => array(
				'name'    => 'digitalkind.ie',
				'url'     => array( 'https://digitalkind.ie' ),
				'contact' => array( array( 'email' => 'security@airworthywp.com' ) ),
			),
			'externalReferences' => array(
				array(
					'type' => 'website',
					'url'  => 'https://airworthywp.com',
				),
			),
		),
	),
	'components'   => $components,
	'dependencies' => array(
		array(
			'ref'       => $self,
			'dependsOn' => $deps,
		),
	),
);

file_put_contents( $out, json_encode( $bom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
echo 'SBOM: ' . count( $components ) . " components\n";
