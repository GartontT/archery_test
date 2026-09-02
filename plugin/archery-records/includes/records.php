<?php
/**
 * Works out the record progression for each classification.
 *
 * The records database holds every score that was submitted, including plenty
 * that were never records. So we cannot simply list what is in the table: we
 * have to walk each classification in date order and keep only the scores that
 * beat everything before them.
 *
 * A score that merely EQUALS the standing record does not take it. That is the
 * usual convention in the sport - the first archer to reach a score keeps it -
 * and it is the assumption to revisit first if the published tables ever look
 * wrong. Filter 'archery_records_ties_take_record' to change it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Group submissions into per-record progressions.
 *
 * @param array $submissions Normalised submission rows.
 * @return array Record key => list of record-setting submissions, current holder first.
 */
function archery_records_build_progressions( $submissions ) {
	$grouped = array();

	foreach ( $submissions as $submission ) {
		$key = archery_records_record_key( $submission );
		if ( ! isset( $grouped[ $key ] ) ) {
			$grouped[ $key ] = array();
		}
		$grouped[ $key ][] = $submission;
	}

	$ties_take_record = (bool) apply_filters( 'archery_records_ties_take_record', false );
	$progressions     = array();

	foreach ( $grouped as $key => $entries ) {
		usort( $entries, 'archery_records_compare_by_date_then_score' );

		$progression = array();
		$best        = null;

		foreach ( $entries as $entry ) {
			$beats_best = ( null === $best )
				|| ( $ties_take_record ? $entry['score'] >= $best : $entry['score'] > $best );

			if ( $beats_best ) {
				$progression[] = $entry;
				$best          = $entry['score'];
			}
		}

		// Display order is the reverse of the order they were set in: the current
		// holder at the top, then back through the previous holders.
		$progressions[ $key ] = array_reverse( $progression );
	}

	return $progressions;
}

/**
 * Sort comparator: oldest first, and where dates tie or are unknown, lowest score first.
 *
 * @param array $a Submission.
 * @param array $b Submission.
 * @return int
 */
function archery_records_compare_by_date_then_score( $a, $b ) {
	$da = archery_records_date_sort_key( $a['date'] );
	$db = archery_records_date_sort_key( $b['date'] );

	if ( $da !== $db ) {
		return ( $da < $db ) ? -1 : 1;
	}

	if ( $a['score'] !== $b['score'] ) {
		return ( $a['score'] < $b['score'] ) ? -1 : 1;
	}

	return strcmp( $a['id'], $b['id'] );
}

/**
 * Turn a date string into a sortable integer of the form YYYYMMDD.
 *
 * The dates on the current site are written as "25-Jan-15", which is ambiguous
 * about the century, so a two-digit year is read as 20xx when that would not be
 * in the future and 19xx otherwise. Records genuinely do run from the 1990s to
 * this year, so this matters. If the real database turns out to store proper
 * dates, this guesswork should be deleted rather than kept.
 *
 * Anything unrecognised sorts to the very beginning, which for a progression
 * means "before everything else we know about".
 *
 * @param string $date Raw date string.
 * @return int
 */
function archery_records_date_sort_key( $date ) {
	$date = trim( (string) $date );

	if ( '' === $date ) {
		return 0;
	}

	// 2015-01-25
	if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
		return (int) ( $m[1] . $m[2] . $m[3] );
	}

	// 25-Jan-15 or 25-Jan-2015
	if ( preg_match( '/^(\d{1,2})[-\/ ]([A-Za-z]{3,})[-\/ ](\d{2}|\d{4})$/', $date, $m ) ) {
		$month = archery_records_month_number( $m[2] );
		if ( $month ) {
			$year = (int) $m[3];
			if ( strlen( $m[3] ) === 2 ) {
				$year = archery_records_expand_two_digit_year( $year );
			}
			return (int) sprintf( '%04d%02d%02d', $year, $month, (int) $m[1] );
		}
	}

	// A bare year, as used in some of the older archived rows.
	if ( preg_match( '/^(\d{4})$/', $date, $m ) ) {
		return (int) ( $m[1] . '0000' );
	}

	return 0;
}

/**
 * Read a two-digit year as this century unless that would put it in the future.
 *
 * @param int $year Two-digit year.
 * @return int Four-digit year.
 */
function archery_records_expand_two_digit_year( $year ) {
	$pivot = (int) gmdate( 'y' );

	return ( $year <= $pivot ) ? 2000 + $year : 1900 + $year;
}

/**
 * Month number from an English month name or abbreviation.
 *
 * @param string $name Month name.
 * @return int Month number, or 0 if unrecognised.
 */
function archery_records_month_number( $name ) {
	$months = array( 'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec' );
	$key    = strtolower( substr( $name, 0, 3 ) );
	$index  = array_search( $key, $months, true );

	return ( false === $index ) ? 0 : $index + 1;
}
