<?php
/**
 * Cached read-through to the data source, with a fallback to the last good copy.
 *
 * The behaviour is:
 *
 *   - A page load uses the cached copy if it is less than 15 minutes old.
 *   - Otherwise it reads the data source, tidies the result, caches it, and
 *     also keeps a permanent "last known good" copy.
 *   - If the data source fails, the last known good copy is served instead and
 *     the failure goes to the error log. The site never shows an empty table
 *     because a database was briefly unreachable.
 *
 * If we later decide to move to a scheduled sync instead of reading on demand,
 * this is the only file that changes: point archery_records_get_data() at the
 * stored copy and have a cron job do the refresh.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ARCHERY_RECORDS_TRANSIENT = 'archery_records_data';
const ARCHERY_RECORDS_LAST_GOOD = 'archery_records_last_good';

/**
 * How long a cached copy stays fresh, in seconds.
 *
 * Filter 'archery_records_cache_seconds' to change it. Set it to 0 to disable
 * caching entirely while debugging.
 *
 * @return int
 */
function archery_records_cache_seconds() {
	return (int) apply_filters( 'archery_records_cache_seconds', 15 * MINUTE_IN_SECONDS );
}

/**
 * Get the records data, from cache where possible.
 *
 * @return array {
 *     @type array  $submissions Normalised submission rows.
 *     @type array  $problems    Notes about rows that were dropped.
 *     @type string $status      One of 'fresh', 'cached', 'stale', 'unavailable'.
 * }
 */
function archery_records_get_data() {
	$ttl = archery_records_cache_seconds();

	if ( $ttl > 0 ) {
		$cached = get_transient( ARCHERY_RECORDS_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['submissions'] ) ) {
			$cached['status'] = 'cached';
			return $cached;
		}
	}

	try {
		$raw = archery_records_fetch_submissions();
	} catch ( Exception $e ) {
		return archery_records_fall_back( $e->getMessage() );
	} catch ( Throwable $e ) {
		// PHP 7 fatal errors inside the data source, e.g. a missing extension.
		return archery_records_fall_back( $e->getMessage() );
	}

	$result = archery_records_normalise( $raw );

	if ( empty( $result['submissions'] ) ) {
		// The source answered but gave us nothing usable. Treat that the same as
		// a failure rather than publishing a site full of empty tables.
		return archery_records_fall_back( 'The data source returned no usable rows.' );
	}

	$result['status']     = 'fresh';
	$result['fetched_at'] = time();

	if ( $ttl > 0 ) {
		set_transient( ARCHERY_RECORDS_TRANSIENT, $result, $ttl );
	}
	update_option( ARCHERY_RECORDS_LAST_GOOD, $result, false );

	return $result;
}

/**
 * Serve the last known good copy after a failure.
 *
 * @param string $reason Why the live read failed.
 * @return array Same shape as archery_records_get_data().
 */
function archery_records_fall_back( $reason ) {
	error_log( 'Archery Records: could not read the records data source. ' . $reason );

	$last_good = get_option( ARCHERY_RECORDS_LAST_GOOD );

	if ( is_array( $last_good ) && ! empty( $last_good['submissions'] ) ) {
		$last_good['status'] = 'stale';
		$last_good['reason'] = $reason;
		return $last_good;
	}

	return array(
		'submissions' => array(),
		'problems'    => array( $reason ),
		'status'      => 'unavailable',
		'reason'      => $reason,
	);
}

/**
 * Drop the cached copy so the next page load reads the source again.
 *
 * The last known good copy is deliberately left alone - it is the safety net.
 */
function archery_records_clear_cache() {
	delete_transient( ARCHERY_RECORDS_TRANSIENT );
}
