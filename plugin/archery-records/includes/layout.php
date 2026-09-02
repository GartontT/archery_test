<?php
/**
 * Which rounds appear on which page, and what rows each round's table should have.
 *
 * This is configuration rather than data, and it exists for one reason: the
 * records database only holds scores that were actually shot. It has no way of
 * saying "Gents Barebow on this round exists as a category but nobody has ever
 * set a record for it". The current site shows those as "no current record"
 * rows, so we keep a list of the rows each table is expected to have.
 *
 * The file itself, config/layout.json, was generated from the existing pages by
 * tools/extract_fixtures.py. Editing it by hand is fine and expected.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load the layout configuration.
 *
 * @return array {
 *     @type array $pages  Page key => page definition, in display order.
 *     @type array $rounds Round key => round definition.
 * }
 */
function archery_records_get_layout() {
	static $layout = null;

	if ( null !== $layout ) {
		return $layout;
	}

	$layout = array(
		'pages'  => array(),
		'rounds' => array(),
	);

	$path = ARCHERY_RECORDS_DIR . 'config/layout.json';

	if ( is_readable( $path ) ) {
		$decoded = json_decode( (string) file_get_contents( $path ), true );

		if ( is_array( $decoded ) && isset( $decoded['pages'], $decoded['rounds'] ) ) {
			// The file stores pages as an ordered list; index them by slug for lookup
			// while keeping that order.
			foreach ( $decoded['pages'] as $page ) {
				if ( isset( $page['slug'] ) ) {
					$layout['pages'][ $page['slug'] ] = $page;
				}
			}
			$layout['rounds'] = $decoded['rounds'];
		} else {
			error_log( 'Archery Records: config/layout.json could not be parsed.' );
		}
	} else {
		error_log( 'Archery Records: config/layout.json is missing or unreadable.' );
	}

	/**
	 * Adjust the layout without editing the shipped file.
	 *
	 * @param array $layout The decoded layout.
	 */
	$layout = apply_filters( 'archery_records_layout', $layout );

	return $layout;
}

/**
 * The column headers for a round, with the extra column that holds the "+" button.
 *
 * @param array $round        A round definition.
 * @param bool  $show_history Whether the history column is wanted at all.
 * @return array List of header labels. The history column is an empty string.
 */
function archery_records_columns( $round, $show_history ) {
	$columns = isset( $round['columns'] ) ? array_values( $round['columns'] ) : array();

	if ( $show_history ) {
		$columns[] = '';
	}

	return $columns;
}
