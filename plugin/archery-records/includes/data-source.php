<?php
/**
 * ###########################################################################
 * ###                                                                     ###
 * ###                    >>>  REPLACE THIS FILE  <<<                      ###
 * ###                                                                     ###
 * ###   This is the ONLY file that knows where the records data comes     ###
 * ###   from. Everything else in the plugin works off what this file      ###
 * ###   returns and neither knows nor cares whether that came from        ###
 * ###   MySQL, a CSV on disk, an Excel export, or a web service.          ###
 * ###                                                                     ###
 * ###   Right now it returns SAMPLE DATA bundled with the plugin, so      ###
 * ###   that the tables can be built and looked at before anyone has      ###
 * ###   access to the real database.                                      ###
 * ###                                                                     ###
 * ###   To connect the real database, implement exactly one function:     ###
 * ###                                                                     ###
 * ###       archery_records_fetch_submissions()                           ###
 * ###                                                                     ###
 * ###   returning an array of submission rows in the shape documented     ###
 * ###   in DATA-CONTRACT.md (read that first - it is short).              ###
 * ###                                                                     ###
 * ###   There are three worked examples in examples/ showing how that     ###
 * ###   function looks for a MySQL table, for a CSV or Excel export on    ###
 * ###   disk, and for a JSON web service. Copy whichever one matches      ###
 * ###   and adjust the field names.                                       ###
 * ###                                                                     ###
 * ###########################################################################
 *
 * Two rules for whatever replaces this:
 *
 *   1. Return EVERY score the database holds for a record classification, not
 *      just the current best. The plugin works out which of them were records
 *      at the time by walking them in date order. That is deliberate: the site
 *      is then correct by construction and does not depend on anybody
 *      remembering to flag rows in the database.
 *
 *   2. Throw an exception if the data cannot be read. Do NOT return an empty
 *      array on failure - an empty array means "there are genuinely no
 *      records", and the plugin will believe you and wipe the tables. An
 *      exception makes the plugin keep serving the last good copy instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'archery_records_fetch_submissions' ) ) {

	/**
	 * Fetch every recorded score from the records database.
	 *
	 * @throws RuntimeException If the data source cannot be read.
	 * @return array List of submission rows. See DATA-CONTRACT.md.
	 */
	function archery_records_fetch_submissions() {

		// --- SAMPLE DATA. Delete everything below when connecting the real source. ---

		$path = ARCHERY_RECORDS_DIR . 'data/fixtures.json';

		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'Sample data file is missing: ' . $path );
		}

		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			throw new RuntimeException( 'Could not read the sample data file: ' . $path );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['submissions'] ) || ! is_array( $decoded['submissions'] ) ) {
			throw new RuntimeException( 'Sample data file is not valid JSON, or has no "submissions" key.' );
		}

		return $decoded['submissions'];
	}
}
