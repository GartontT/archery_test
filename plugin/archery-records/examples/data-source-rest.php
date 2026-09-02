<?php
/**
 * WORKED EXAMPLE - a JSON web service.
 *
 * Use this shape if the records are reachable over HTTP rather than directly:
 * a small PHP endpoint in front of the database, a Google Sheet published as
 * JSON, or an existing results system with an API.
 *
 * To use it: copy this file over includes/data-source.php and change the URL
 * and the field names.
 *
 * This is the only one of the three examples that talks to something off the
 * server, so it is the one where the timeout and the fallback matter. The
 * plugin already keeps the last good copy, so a slow or down service means the
 * site shows slightly old records rather than none.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ARCHERY_RECORDS_ENDPOINT', 'https://example.org/api/records' );

/**
 * Read every recorded score from the web service.
 *
 * @throws RuntimeException If the service cannot be reached or does not answer sensibly.
 * @return array
 */
function archery_records_fetch_submissions() {
	$response = wp_remote_get(
		ARCHERY_RECORDS_ENDPOINT,
		array(
			'timeout' => 10,
			'headers' => array(
				'Accept' => 'application/json',
				// If the endpoint needs a key, keep it in wp-config.php:
				// 'Authorization' => 'Bearer ' . ARCHERY_RECORDS_API_KEY,
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		throw new RuntimeException( 'Could not reach the records service: ' . $response->get_error_message() );
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== (int) $code ) {
		throw new RuntimeException( 'The records service answered with HTTP ' . $code . '.' );
	}

	$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $decoded ) ) {
		throw new RuntimeException( 'The records service did not return valid JSON.' );
	}

	// Adjust to wherever the list actually sits in the response.
	$items = isset( $decoded['records'] ) ? $decoded['records'] : $decoded;
	if ( ! is_array( $items ) || empty( $items ) ) {
		throw new RuntimeException( 'The records service returned no records.' );
	}

	$rows = array();

	foreach ( $items as $item ) {
		$classification = array();
		if ( ! empty( $item['peg'] ) ) {
			$classification['peg'] = $item['peg'];
		}
		if ( ! empty( $item['class'] ) ) {
			$classification['class'] = $item['class'];
		}

		$archers = array();
		if ( isset( $item['archers'] ) && is_array( $item['archers'] ) ) {
			$archers = $item['archers'];
		} elseif ( ! empty( $item['archer'] ) ) {
			$archers = array( $item['archer'] );
		}

		$rows[] = array(
			'id'             => isset( $item['id'] ) ? $item['id'] : '',
			'round'          => isset( $item['round'] ) ? $item['round'] : '',
			'classification' => $classification,
			'bow'            => isset( $item['bow'] ) ? $item['bow'] : '',
			'score'          => isset( $item['score'] ) ? $item['score'] : null,
			'archers'        => $archers,
			'date'           => isset( $item['date'] ) ? $item['date'] : '',
			'club'           => isset( $item['club'] ) ? $item['club'] : '',
		);
	}

	return $rows;
}
