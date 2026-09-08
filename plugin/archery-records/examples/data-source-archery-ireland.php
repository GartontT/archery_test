<?php
/**
 * THE LIVE DATA SOURCE for archery.ie.
 *
 * Copy this file over includes/data-source.php to switch the plugin from the bundled
 * sample data to the real records database. Nothing else changes.
 *
 * Before you do, add four lines to wp-config.php, above the "stop editing" comment:
 *
 *   define( 'ARCHERY_RECORDS_DB_HOST', 'mysql1996int.cp.blacknight.com' );
 *   define( 'ARCHERY_RECORDS_DB_NAME', 'db1249072_registration' );
 *   define( 'ARCHERY_RECORDS_DB_USER', '...' );
 *   define( 'ARCHERY_RECORDS_DB_PASS', '...' );
 *
 * Use a MySQL user with SELECT permission and nothing else. The plugin never writes,
 * and a read-only user means a mistake here can never damage the records. Do not reuse
 * u1249072_admin: it can drop tables.
 *
 * Note that this is NOT the WordPress database. WordPress is on mysql4543int; the
 * records are on mysql1996int, a different server on the same hosting account. That is
 * why $wpdb cannot be used and a separate connection is needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bow codes as stored in Records.Bow.
 */
function archery_records_bow_labels() {
	return array(
		'R' => 'Recurve',
		'C' => 'Compound',
		'B' => 'Barebow',
		'I' => 'Instinctive',
		'L' => 'Longbow',
		'T' => 'Traditional',
	);
}

/**
 * Class and gender codes combine into the single label the website shows.
 *
 * Confirmed against the published pages in September 2026. If Archery Ireland renames
 * an age group, this is the only place that needs changing.
 */
function archery_records_class_labels() {
	return array(
		'|M'  => 'Gents',      '|W'  => 'Ladies',      '|X'  => 'Mixed',
		'M|M' => '50+ Men',    'M|W' => '50+ Women',   'M|X' => '50+ Mixed',
		'J|M' => 'U21 Gents',  'J|W' => 'U21 Ladies',  'J|X' => 'U21 Mixed',
		'C|M' => 'U18 Gents',  'C|W' => 'U18 Ladies',  'C|X' => 'U18 Mixed',
		'Y|M' => 'U15 Gents',  'Y|W' => 'U15 Ladies',  'Y|X' => 'U15 Mixed',
	);
}

/**
 * Settle the two spellings of a peg colour on one.
 *
 * The database holds both "Red Peg" and "RED" for the same peg. Left alone that splits a
 * single record in two: Darrel Wilson's 390 from 2016 and his 394 from 2023 are the same
 * 24 targets marked record, but they would group separately and render as two rows
 * rather than one with the history behind it.
 *
 * Worth fixing at source - see suggestions.md - after which this becomes a no-op.
 *
 * @param string $value Peg as stored.
 * @return string
 */
function archery_records_normalise_peg( $value ) {
	$names = array(
		'red'    => 'Red Peg',
		'blue'   => 'Blue Peg',
		'white'  => 'White Peg',
		'yellow' => 'Yellow Peg',
	);

	$key = strtolower( trim( preg_replace( '/\s*peg\s*$/i', '', trim( (string) $value ) ) ) );

	return isset( $names[ $key ] ) ? $names[ $key ] : trim( (string) $value );
}

/**
 * Which of the six records pages a row belongs on.
 *
 * @param string $type      Records.Type: Target, Field or 3D.
 * @param string $location  Records.Location: Indoor or Outdoor.
 * @param string $round_code Records.RoundCode.
 * @return string Page key.
 */
function archery_records_page_for( $type, $location, $round_code ) {
	$is_team = ( false !== stripos( $round_code, 'team' ) );

	if ( '3D' === $type ) {
		return '3d-field';
	}
	if ( 'Field' === $type ) {
		return 'records-field';
	}
	if ( 'Indoor' === $location ) {
		return $is_team ? 'target-indoor-team' : 'target-indoor-individual';
	}

	return $is_team ? 'target-outdoor-team' : 'target-outdoor-individual';
}

/**
 * Turn a round code into the slug used in config/rounds.json.
 *
 * Must match tools/load_real_data.py, which generated that file.
 *
 * @param string $value Round code.
 * @return string
 */
function archery_records_slugify( $value ) {
	$value = strtolower( $value );
	$value = str_replace( array( '–', '—', '’' ), array( '-', '-', '' ), $value );
	$value = preg_replace( '/[^a-z0-9]+/', '-', $value );

	return trim( preg_replace( '/-+/', '-', $value ), '-' );
}

/**
 * Read every recorded score from the records database.
 *
 * @throws RuntimeException If the database cannot be read.
 * @return array Submission rows. See DATA-CONTRACT.md.
 */
function archery_records_fetch_submissions() {
	foreach ( array( 'ARCHERY_RECORDS_DB_HOST', 'ARCHERY_RECORDS_DB_NAME', 'ARCHERY_RECORDS_DB_USER', 'ARCHERY_RECORDS_DB_PASS' ) as $constant ) {
		if ( ! defined( $constant ) ) {
			throw new RuntimeException( 'Missing ' . $constant . ' in wp-config.php.' );
		}
	}

	// Report connection failures as exceptions rather than warnings, so the plugin's
	// fallback to the last good copy actually gets a chance to run.
	$previous = mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );

	try {
		$db = new mysqli(
			ARCHERY_RECORDS_DB_HOST,
			ARCHERY_RECORDS_DB_USER,
			ARCHERY_RECORDS_DB_PASS,
			ARCHERY_RECORDS_DB_NAME
		);
	} catch ( Exception $e ) {
		mysqli_report( $previous );
		throw new RuntimeException( 'Could not connect to the records database: ' . $e->getMessage() );
	}

	// The stored text is UTF-8 bytes in latin1 columns, so names with a fada arrive
	// double-encoded. The plugin repairs that on the way in - see
	// archery_records_repair_encoding(). If the columns are ever converted properly,
	// the repair becomes a no-op and nothing here needs changing.
	$db->set_charset( 'utf8mb4' );

	$sql = 'SELECT RecordCode, RoundCode, Peg, Bow, Class, Gender, Score,
	               Archer, TeamArcher_2, TeamArcher_3, Date, Club,
	               Archived, Location, Type
	          FROM Records';

	$result = $db->query( $sql );

	if ( false === $result ) {
		$error = $db->error;
		$db->close();
		mysqli_report( $previous );
		throw new RuntimeException( 'The records query failed: ' . $error );
	}

	$bows    = archery_records_bow_labels();
	$classes = archery_records_class_labels();
	$rows    = array();

	while ( $row = $result->fetch_assoc() ) {
		$archived = ( '1' === (string) $row['Archived'] );
		$page     = archery_records_page_for( $row['Type'], $row['Location'], $row['RoundCode'] );

		// Archived is a property of the row, not the round: the Field page carries the
		// same round twice, once live and once under "Archived Instinctive Records".
		$round_key = $page . '/' . archery_records_slugify( trim( $row['RoundCode'] ) )
			. ( $archived ? '-archived' : '' );

		$code  = trim( (string) $row['Class'] ) . '|' . trim( (string) $row['Gender'] );
		$label = isset( $classes[ $code ] ) ? $classes[ $code ] : trim( str_replace( '|', ' ', $code ) );

		$classification = array();
		if ( '' !== trim( (string) $row['Peg'] ) ) {
			$classification['peg'] = archery_records_normalise_peg( $row['Peg'] );
		}
		$classification['class'] = $label;

		$archers = array();
		foreach ( array( 'Archer', 'TeamArcher_2', 'TeamArcher_3' ) as $field ) {
			$name = trim( (string) $row[ $field ] );
			if ( '' !== $name ) {
				$archers[] = $name;
			}
		}

		// The zero date is how this table records "no date", not a date in year zero.
		$date = ( 0 === strpos( (string) $row['Date'], '0000' ) ) ? '' : (string) $row['Date'];

		$rows[] = array(
			'id'             => (string) $row['RecordCode'],
			'round'          => $round_key,
			'classification' => $classification,
			'bow'            => isset( $bows[ $row['Bow'] ] ) ? $bows[ $row['Bow'] ] : (string) $row['Bow'],
			'score'          => (int) $row['Score'],
			'archers'        => $archers,
			'date'           => $date,
			'club'           => trim( (string) $row['Club'] ),
			'venue'          => '',
		);
	}

	$result->free();
	$db->close();
	mysqli_report( $previous );

	if ( empty( $rows ) ) {
		throw new RuntimeException( 'The Records table returned no rows.' );
	}

	return $rows;
}
