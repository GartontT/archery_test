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

		$score = ( isset( $row['score'] ) && is_numeric( $row['score'] ) ) ? (int) $row['score'] : 0;

		$archers = array();
		if ( isset( $row['archers'] ) && is_array( $row['archers'] ) ) {
			foreach ( $row['archers'] as $archer ) {
				$archer = archery_records_tidy_text( $archer );
				if ( '' !== $archer ) {
					$archers[] = $archer;
				}
			}
		} elseif ( isset( $row['archer'] ) ) {
			// Tolerate a single "archer" string, which is the likely shape of an
			// individual-only table in the source database.
			$archer = archery_records_tidy_text( $row['archer'] );
			if ( '' !== $archer ) {
				$archers[] = $archer;
			}
		}

		// A row with no score and no archer is not a mistake: it is how the database
		// says "this category exists but nobody holds the record". Those rows are what
		// the pages print as "no current record", so they are kept. A row with a score
		// but no archer, on the other hand, is malformed.
		$vacant = ( 0 === $score && empty( $archers ) );

		if ( ! $vacant && empty( $archers ) ) {
			$problems[] = sprintf( 'Row %d ("%s") has a score of %d but names no archer; skipped.', $index, $id, $score );
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
			'score'          => $score,
			'archers'        => $archers,
			'date'           => archery_records_tidy_text( isset( $row['date'] ) ? $row['date'] : '' ),
			'club'           => archery_records_tidy_text( isset( $row['club'] ) ? $row['club'] : '' ),
			'venue'          => archery_records_tidy_text( isset( $row['venue'] ) ? $row['venue'] : '' ),
			'vacant'         => $vacant,
		);
	}

	return array(
		'submissions' => $clean,
		'problems'    => $problems,
	);
}

/**
 * Collapse whitespace, strip non-breaking spaces, and repair mis-encoded text.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function archery_records_tidy_text( $value ) {
	$value = archery_records_repair_encoding( (string) $value );
	$value = str_replace( array( "\xc2\xa0", "\xe2\x80\x8b" ), ' ', $value );
	$value = preg_replace( '/\s+/u', ' ', $value );

	return trim( $value );
}

/**
 * Undo double-encoded UTF-8.
 *
 * Some of the names in the records database are stored as UTF-8 bytes inside a column
 * declared as latin1, so reading them back as UTF-8 yields mojibake: Roisin Mooney's
 * name arrives as "RÃ³isÃ­n Mooney". Reversing the extra encoding step recovers it.
 *
 * The repair is only attempted on strings that show the tell-tale sequences, and only
 * accepted if the result is itself valid UTF-8, so text that was fine is left alone.
 *
 * If the real fix is ever applied at the database end, this becomes a no-op rather than
 * a problem - which is why it is safe to leave in place.
 *
 * @param string $value Possibly mis-encoded text.
 * @return string
 */
function archery_records_repair_encoding( $value ) {
	if ( '' === $value ) {
		return $value;
	}

	// "Ã" and "Â" are what a double-encoded accented character always begins with.
	if ( false === strpos( $value, "\xc3\x83" ) && false === strpos( $value, "\xc3\x82" ) ) {
		return $value;
	}

	if ( ! function_exists( 'mb_convert_encoding' ) || ! function_exists( 'mb_check_encoding' ) ) {
		return $value;
	}

	$repaired = @mb_convert_encoding( $value, 'ISO-8859-1', 'UTF-8' );

	if ( is_string( $repaired ) && '' !== $repaired && mb_check_encoding( $repaired, 'UTF-8' ) ) {
		return $repaired;
	}

	return $value;
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
 * Build the same key from its parts.
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
