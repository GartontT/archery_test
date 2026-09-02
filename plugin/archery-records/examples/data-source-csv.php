<?php
/**
 * WORKED EXAMPLE - a CSV or Excel export sitting on the same server.
 *
 * This is the most likely shape if the records are kept in a spreadsheet that
 * gets exported, or in something like Access with a scheduled export.
 *
 * To use it: copy this file over includes/data-source.php and change
 * ARCHERY_RECORDS_CSV_PATH and the column names in $map to match the file.
 *
 * Note the path. Keep the file OUTSIDE the web root - somewhere like
 * /home/site/private/records.csv rather than /home/site/public_html/records.csv.
 * If it sits inside the web root then anybody who guesses the URL can download
 * the whole records database.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Where the export lives. Better still, define this in wp-config.php.
define( 'ARCHERY_RECORDS_CSV_PATH', '/home/archery/private/records.csv' );

/**
 * Map the round names used in the spreadsheet to the plugin's round keys.
 *
 * The full list of keys is in config/layout.json.
 */
function archery_records_round_map() {
	return array(
		'WA18 120'     => 'target-indoor-individual/wa-18-120-arrows',
		'WA18 60'      => 'target-indoor-individual/wa-18-60-arrows',
		'WA18 30'      => 'target-indoor-individual/wa-18-30-arrows',
		'WA25 60'      => 'target-indoor-individual/wa-25-60-arrows',
		'Field 48 Unm' => '3d-field/48-targets-unmarked',
		// ... and so on for every round in the spreadsheet.
	);
}

/**
 * Read every recorded score from the CSV export.
 *
 * @throws RuntimeException If the file cannot be read.
 * @return array
 */
function archery_records_fetch_submissions() {
	$path = ARCHERY_RECORDS_CSV_PATH;

	if ( ! is_readable( $path ) ) {
		throw new RuntimeException( 'Records export not found or not readable: ' . $path );
	}

	$handle = fopen( $path, 'r' );
	if ( false === $handle ) {
		throw new RuntimeException( 'Could not open the records export: ' . $path );
	}

	$header = fgetcsv( $handle );
	if ( ! $header ) {
		fclose( $handle );
		throw new RuntimeException( 'The records export has no header row.' );
	}

	// Column name in the spreadsheet => what we call it here.
	$map = array(
		'RecordID' => 'id',
		'Round'    => 'round',
		'Class'    => 'class',
		'Peg'      => 'peg',
		'Bow'      => 'bow',
		'Score'    => 'score',
		'Archer1'  => 'archer1',
		'Archer2'  => 'archer2',
		'Archer3'  => 'archer3',
		'Date'     => 'date',
		'Club'     => 'club',
	);

	$index = array();
	foreach ( $header as $position => $name ) {
		$name = trim( $name );
		if ( isset( $map[ $name ] ) ) {
			$index[ $map[ $name ] ] = $position;
		}
	}

	$get = function ( $row, $field ) use ( $index ) {
		return isset( $index[ $field ], $row[ $index[ $field ] ] ) ? trim( $row[ $index[ $field ] ] ) : '';
	};

	$round_map = archery_records_round_map();
	$rows      = array();

	while ( ( $row = fgetcsv( $handle ) ) !== false ) {
		$round_name = $get( $row, 'round' );
		if ( ! isset( $round_map[ $round_name ] ) ) {
			// Unknown round. Passing it straight through means it shows up in the
			// editor-only notice at the bottom of the page rather than vanishing.
			$round_key = $round_name;
		} else {
			$round_key = $round_map[ $round_name ];
		}

		$classification = array();
		foreach ( array( 'peg', 'class' ) as $field ) {
			$value = $get( $row, $field );
			if ( '' !== $value ) {
				$classification[ $field ] = $value;
			}
		}

		$archers = array();
		foreach ( array( 'archer1', 'archer2', 'archer3' ) as $field ) {
			$value = $get( $row, $field );
			if ( '' !== $value ) {
				$archers[] = $value;
			}
		}

		$rows[] = array(
			'id'             => $get( $row, 'id' ),
			'round'          => $round_key,
			'classification' => $classification,
			'bow'            => $get( $row, 'bow' ),
			'score'          => $get( $row, 'score' ),
			'archers'        => $archers,
			'date'           => $get( $row, 'date' ),
			'club'           => $get( $row, 'club' ),
		);
	}

	fclose( $handle );

	if ( empty( $rows ) ) {
		throw new RuntimeException( 'The records export contained no data rows.' );
	}

	return $rows;
}
