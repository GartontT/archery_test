<?php
/**
 * WORKED EXAMPLE - a MySQL database on the same server.
 *
 * Two cases are covered:
 *
 *   A. The records table lives in the SAME database as WordPress. Use $wpdb and
 *      you need no credentials at all. This is the easy case.
 *   B. The records live in a SEPARATE database. Open a second connection with
 *      credentials kept in wp-config.php.
 *
 * To use it: copy this file over includes/data-source.php, delete whichever of
 * the two cases does not apply, and change the table and column names.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Map the round names used in the database to the plugin's round keys.
 *
 * The full list of keys is in config/layout.json. If the database already
 * stores something close to those keys, this whole function can go.
 */
function archery_records_round_map() {
	return array(
		'WA18-120' => 'target-indoor-individual/wa-18-120-arrows',
		'WA18-60'  => 'target-indoor-individual/wa-18-60-arrows',
		// ... and so on.
	);
}

/**
 * Read every recorded score.
 *
 * @throws RuntimeException If the query fails.
 * @return array
 */
function archery_records_fetch_submissions() {

	// ---- Case A: same database as WordPress --------------------------------

	global $wpdb;

	// No values are interpolated into this query, so there is nothing to
	// prepare. If you ever add a WHERE clause with a variable in it, use
	// $wpdb->prepare() - never string concatenation.
	$results = $wpdb->get_results(
		"SELECT record_id, round_name, class, peg, bow, score,
		        archer_1, archer_2, archer_3, shot_on, club
		   FROM archery_records
		  ORDER BY shot_on ASC",
		ARRAY_A
	);

	if ( null === $results ) {
		throw new RuntimeException( 'Records query failed: ' . $wpdb->last_error );
	}

	// ---- Case B: a separate database ---------------------------------------
	//
	// Put these four constants in wp-config.php, not here:
	//
	//   define( 'ARCHERY_DB_HOST', 'localhost' );
	//   define( 'ARCHERY_DB_NAME', 'records' );
	//   define( 'ARCHERY_DB_USER', 'records_readonly' );
	//   define( 'ARCHERY_DB_PASS', '...' );
	//
	// Give that user SELECT and nothing else. The plugin never writes.
	//
	// $db = new wpdb( ARCHERY_DB_USER, ARCHERY_DB_PASS, ARCHERY_DB_NAME, ARCHERY_DB_HOST );
	// if ( ! empty( $db->error ) ) {
	//     throw new RuntimeException( 'Could not connect to the records database.' );
	// }
	// $results = $db->get_results( "SELECT ...", ARRAY_A );

	$round_map = archery_records_round_map();
	$rows      = array();

	foreach ( $results as $row ) {
		$classification = array();
		if ( ! empty( $row['peg'] ) ) {
			$classification['peg'] = $row['peg'];
		}
		if ( ! empty( $row['class'] ) ) {
			$classification['class'] = $row['class'];
		}

		$archers = array();
		foreach ( array( 'archer_1', 'archer_2', 'archer_3' ) as $field ) {
			if ( ! empty( $row[ $field ] ) ) {
				$archers[] = $row[ $field ];
			}
		}

		$round = $row['round_name'];

		$rows[] = array(
			'id'             => $row['record_id'],
			'round'          => isset( $round_map[ $round ] ) ? $round_map[ $round ] : $round,
			'classification' => $classification,
			'bow'            => $row['bow'],
			'score'          => $row['score'],
			'archers'        => $archers,
			// A real DATE column comes back as 2015-01-25, which is exactly what
			// the plugin wants. Do not reformat it here.
			'date'           => $row['shot_on'],
			'club'           => $row['club'],
		);
	}

	if ( empty( $rows ) ) {
		throw new RuntimeException( 'The records table returned no rows.' );
	}

	return $rows;
}
