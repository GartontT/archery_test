<?php
/**
 * Turns whatever the data source hands back into a clean, predictable shape.
 *
 * This is the boundary guard. Anything malformed is dropped here with a note in
 * the error log, so that one bad row in the database cannot break a whole page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validate and tidy a list of raw submission rows.
 *
 * @param array $raw Rows as returned by archery_records_fetch_submissions().
 * @return array {
 *     @type array $submissions Clean rows.
 *     @type array $problems    Human-readable notes about anything dropped.
 * }
 */
function archery_records_normalise( $raw ) {
	$clean    = array();
	$problems = array();
	$seen_ids = array();

	if ( ! is_array( $raw ) ) {
		return array(
			'submissions' => array(),
			'problems'    => array( 'The data source did not return an array.' ),
		);
	}

	foreach ( $raw as $index => $row ) {
		if ( ! is_array( $row ) ) {
			$problems[] = sprintf( 'Row %d is not an array; skipped.', $index );
			continue;
		}

		$id    = isset( $row['id'] ) ? trim( (string) $row['id'] ) : '';
		$round = isset( $row['round'] ) ? trim( (string) $row['round'] ) : '';

		if ( '' === $round ) {
			$problems[] = sprintf( 'Row %d has no round; skipped.', $index );
			continue;
		}

		// A missing id is recoverable - derive a stable one - but a duplicate is
		// not, because history rows would collapse into each other.
		if ( '' === $id ) {
			$id = 'derived-' . md5( wp_json_encode( $row ) );
		}
		if ( isset( $seen_ids[ $id ] ) ) {
			$problems[] = sprintf( 'Row %d repeats the id "%s"; skipped.', $index, $id );
			continue;
		}
		$seen_ids[ $id ] = true;

		if ( ! isset( $row['score'] ) || ! is_numeric( $row['score'] ) ) {
			$problems[] = sprintf( 'Row %d ("%s") has no numeric score; skipped.', $index, $id );
			continue;
		}

		$archers = array();
		if ( isset( $row['archers'] ) && is_array( $row['archers'] ) ) {
			foreach ( $row['archers'] as $archer ) {
				$archer = trim( (string) $archer );
				if ( '' !== $archer ) {
					$archers[] = $archer;
				}
			}
		} elseif ( isset( $row['archer'] ) ) {
			// Tolerate a single "archer" string, which is the likely shape of an
			// individual-only table in the source database.
			$archer = trim( (string) $row['archer'] );
			if ( '' !== $archer ) {
				$archers[] = $archer;
			}
		}

		if ( empty( $archers ) ) {
			$problems[] = sprintf( 'Row %d ("%s") names no archer; skipped.', $index, $id );
			continue;
		}

		$classification = array();
		if ( isset( $row['classification'] ) && is_array( $row['classification'] ) ) {
			foreach ( $row['classification'] as $key => $value ) {
				$key = strtolower( trim( (string) $key ) );
				if ( '' !== $key ) {
					$classification[ $key ] = archery_records_tidy_text( $value );
				}
			}
		}

		$clean[] = array(
			'id'             => $id,
			'round'          => $round,
			'classification' => $classification,
			'bow'            => archery_records_tidy_text( isset( $row['bow'] ) ? $row['bow'] : '' ),
			'score'          => (int) $row['score'],
			'archers'        => array_map( 'archery_records_tidy_text', $archers ),
			'date'           => archery_records_tidy_text( isset( $row['date'] ) ? $row['date'] : '' ),
			'club'           => archery_records_tidy_text( isset( $row['club'] ) ? $row['club'] : '' ),
			'venue'          => archery_records_tidy_text( isset( $row['venue'] ) ? $row['venue'] : '' ),
		);
	}

	return array(
		'submissions' => $clean,
		'problems'    => $problems,
	);
}

/**
 * Collapse whitespace and strip the non-breaking spaces that litter hand-typed data.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function archery_records_tidy_text( $value ) {
	$value = (string) $value;
	$value = str_replace( array( "\xc2\xa0", "\xe2\x80\x8b" ), ' ', $value );
	$value = preg_replace( '/\s+/u', ' ', $value );

	return trim( $value );
}

/**
 * A stable key identifying one record - that is, one row of one table.
 *
 * The database's own id identifies a single SCORE, and is explicitly random, so
 * it cannot be used for this. What identifies a record is the combination of
 * round, classification and bow.
 *
 * @param array $submission A normalised submission row.
 * @return string
 */
function archery_records_record_key( $submission ) {
	return archery_records_key_from_parts(
		$submission['round'],
		$submission['classification'],
		$submission['bow']
	);
}

/**
 * Build the same key from its parts, for looking up a row defined in the layout
 * configuration rather than coming from a submission.
 *
 * @param string $round          Round key.
 * @param array  $classification Classification field => value.
 * @param string $bow            Bow type.
 * @return string
 */
function archery_records_key_from_parts( $round, $classification, $bow ) {
	$parts = array();
	foreach ( (array) $classification as $key => $value ) {
		$parts[ strtolower( trim( (string) $key ) ) ] = archery_records_tidy_text( $value );
	}
	ksort( $parts );

	return $round . '|' . implode( '|', $parts ) . '|' . archery_records_tidy_text( $bow );
}
