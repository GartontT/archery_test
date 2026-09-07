<?php
/**
 * The seven records pages, the rounds that appear on them, and display order.
 *
 * Almost all of this now comes from the records database itself. The only things kept
 * here are the page titles, which the database has no opinion about, and the order that
 * classes and bow types are listed in within a table, which is a presentation choice.
 *
 * config/rounds.json is generated from the database by tools/load_real_data.py and holds
 * one entry per round: which page it belongs on, its heading, whether it is a retired
 * round, and where it sits in the running order.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pages, in site order.
 *
 * @return array Page key => title.
 */
function archery_records_pages() {
	return apply_filters(
		'archery_records_pages',
		array(
			'target-indoor-individual'  => __( 'Target Indoor Individual', 'archery-records' ),
			'target-indoor-team'        => __( 'Target Indoor Team', 'archery-records' ),
			'target-outdoor-individual' => __( 'Target Outdoor Individual', 'archery-records' ),
			'target-outdoor-team'       => __( 'Target Outdoor Team', 'archery-records' ),
			'records-field'             => __( 'Field', 'archery-records' ),
			'3d-field'                  => __( '3D Field', 'archery-records' ),
		)
	);
}

/**
 * Load the round definitions.
 *
 * @return array Round key => definition.
 */
function archery_records_rounds() {
	static $rounds = null;

	if ( null !== $rounds ) {
		return $rounds;
	}

	$rounds = array();
	$path   = ARCHERY_RECORDS_DIR . 'config/rounds.json';

	if ( is_readable( $path ) ) {
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if ( is_array( $decoded ) ) {
			$rounds = $decoded;
		} else {
			error_log( 'Archery Records: config/rounds.json could not be parsed.' );
		}
	} else {
		error_log( 'Archery Records: config/rounds.json is missing or unreadable.' );
	}

	return apply_filters( 'archery_records_rounds', $rounds );
}

/**
 * The rounds on one page, in display order, live rounds first and retired ones after.
 *
 * @param string $page_key Page key.
 * @return array Round key => definition.
 */
function archery_records_rounds_for_page( $page_key ) {
	$rounds = array();

	foreach ( archery_records_rounds() as $key => $round ) {
		if ( isset( $round['page'] ) && $round['page'] === $page_key ) {
			$rounds[ $key ] = $round;
		}
	}

	uasort(
		$rounds,
		function ( $a, $b ) {
			// Retired rounds always sit at the bottom of the page.
			$archived_a = ! empty( $a['archived'] ) ? 1 : 0;
			$archived_b = ! empty( $b['archived'] ) ? 1 : 0;
			if ( $archived_a !== $archived_b ) {
				return $archived_a - $archived_b;
			}

			$order_a = isset( $a['order'] ) ? (int) $a['order'] : 9999;
			$order_b = isset( $b['order'] ) ? (int) $b['order'] : 9999;
			if ( $order_a !== $order_b ) {
				return $order_a - $order_b;
			}

			return strcmp( $a['heading'], $b['heading'] );
		}
	);

	return $rounds;
}

/**
 * Rank a class label for display order, matching how the current pages read.
 *
 * Seniors first, then 50+, then down through the age groups, men before women.
 * Anything unrecognised sorts to the end rather than disappearing.
 *
 * @param string $label Class label, e.g. "50+ Men".
 * @return int
 */
function archery_records_class_rank( $label ) {
	$order = array(
		'Gents'      => 10,
		'Ladies'     => 11,
		'Mixed'      => 12,
		'50+ Men'    => 20,
		'50+ Women'  => 21,
		'50+ Mixed'  => 22,
		'U21 Gents'  => 30,
		'U21 Ladies' => 31,
		'U21 Mixed'  => 32,
		'U18 Gents'  => 40,
		'U18 Ladies' => 41,
		'U18 Mixed'  => 42,
		'U15 Gents'  => 50,
		'U15 Ladies' => 51,
		'U15 Mixed'  => 52,
	);

	$order = apply_filters( 'archery_records_class_order', $order );

	return isset( $order[ $label ] ) ? $order[ $label ] : 900;
}

/**
 * Rank a bow type for display order.
 *
 * @param string $label Bow label, e.g. "Compound".
 * @return int
 */
function archery_records_bow_rank( $label ) {
	$order = array(
		'Compound'    => 10,
		'Recurve'     => 20,
		'Barebow'     => 30,
		'Traditional' => 40,
		'Instinctive' => 50,
		'Longbow'     => 60,
	);

	$order = apply_filters( 'archery_records_bow_order', $order );

	return isset( $order[ $label ] ) ? $order[ $label ] : 900;
}

/**
 * Rank a peg colour for display order, for the field and 3D tables.
 *
 * @param string $label Peg label, e.g. "Red Peg".
 * @return int
 */
function archery_records_peg_rank( $label ) {
	$order = array(
		'Red Peg'    => 10,
		'Blue Peg'   => 20,
		'White Peg'  => 30,
		'Yellow Peg' => 40,
	);

	$order = apply_filters( 'archery_records_peg_order', $order );

	return isset( $order[ $label ] ) ? $order[ $label ] : 900;
}
