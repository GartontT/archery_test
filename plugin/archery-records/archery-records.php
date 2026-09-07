<?php
/**
 * Plugin Name: Archery Ireland Records
 * Description: Renders the Irish Records tables from the records database, with an expandable "+" showing previous record holders.
 * Version:     0.1.0
 * Requires PHP: 7.0
 * License:     GPL-2.0-or-later
 *
 * ---------------------------------------------------------------------------
 * WHERE TO START
 * ---------------------------------------------------------------------------
 * The only file you need to change to connect this to the real records database
 * is includes/data-source.php. Everything else works off the data it returns.
 * See DATA-CONTRACT.md for exactly what that file has to hand back.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ARCHERY_RECORDS_VERSION', '0.1.0' );
define( 'ARCHERY_RECORDS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ARCHERY_RECORDS_URL', plugin_dir_url( __FILE__ ) );

require_once ARCHERY_RECORDS_DIR . 'includes/data-source.php';
require_once ARCHERY_RECORDS_DIR . 'includes/normalise.php';
require_once ARCHERY_RECORDS_DIR . 'includes/cache.php';
require_once ARCHERY_RECORDS_DIR . 'includes/rounds.php';
require_once ARCHERY_RECORDS_DIR . 'includes/records.php';
require_once ARCHERY_RECORDS_DIR . 'includes/render.php';

/**
 * Register the front-end assets. They are only actually loaded on pages that
 * use the shortcode, so the rest of the site is unaffected.
 */
function archery_records_register_assets() {
	wp_register_style(
		'archery-records',
		ARCHERY_RECORDS_URL . 'assets/archery-records.css',
		array(),
		ARCHERY_RECORDS_VERSION
	);

	wp_register_script(
		'archery-records',
		ARCHERY_RECORDS_URL . 'assets/archery-records.js',
		array(),
		ARCHERY_RECORDS_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'archery_records_register_assets' );

/**
 * The shortcode. One per page:
 *
 *   [archery_records page="target-indoor-individual"]
 *
 * Attributes:
 *   page           Required. One of the keys returned by archery_records_pages().
 *   heading_level  Optional, default 3. The heading tag used for round titles.
 *   archived       Optional, default "yes". Set to "no" to leave out the
 *                  "no longer shot for" tables at the bottom of a page.
 *   history        Optional, default "yes". Set to "no" to render current
 *                  holders only, with no "+" buttons anywhere on the page.
 */
function archery_records_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'page'          => '',
			'heading_level' => '3',
			'archived'      => 'yes',
			'history'       => 'yes',
		),
		$atts,
		'archery_records'
	);

	$page_key = sanitize_key( str_replace( '_', '-', $atts['page'] ) );
	if ( '' === $page_key ) {
		return archery_records_admin_only_notice(
			__( 'The archery_records shortcode needs a page attribute, for example [archery_records page="target-indoor-individual"].', 'archery-records' )
		);
	}

	$pages = archery_records_pages();
	if ( ! isset( $pages[ $page_key ] ) ) {
		return archery_records_admin_only_notice(
			sprintf(
				/* translators: 1: the page key that was asked for, 2: the list of keys that do exist */
				__( 'No records page called "%1$s". The pages are: %2$s', 'archery-records' ),
				$page_key,
				implode( ', ', array_keys( $pages ) )
			)
		);
	}

	wp_enqueue_style( 'archery-records' );
	wp_enqueue_script( 'archery-records' );

	$heading_level = (int) $atts['heading_level'];
	if ( $heading_level < 2 || $heading_level > 6 ) {
		$heading_level = 3;
	}

	$options = array(
		'heading_level'  => $heading_level,
		'show_archived'  => archery_records_is_yes( $atts['archived'] ),
		'show_history'   => archery_records_is_yes( $atts['history'] ),
	);

	return archery_records_render_page( $page_key, $options );
}
add_shortcode( 'archery_records', 'archery_records_shortcode' );

/**
 * Treat the usual spellings of "yes" as true.
 *
 * @param string $value Raw shortcode attribute.
 * @return bool
 */
function archery_records_is_yes( $value ) {
	return in_array( strtolower( trim( (string) $value ) ), array( 'yes', 'true', '1', 'on' ), true );
}

/**
 * A message that only logged-in editors see. Visitors get nothing at all, so a
 * misconfigured shortcode never shows an error to the public.
 *
 * @param string $message Plain text.
 * @return string HTML, or an empty string for visitors.
 */
function archery_records_admin_only_notice( $message ) {
	if ( ! current_user_can( 'edit_pages' ) ) {
		return '';
	}

	return '<p class="archery-records-notice"><strong>' .
		esc_html__( 'Records plugin:', 'archery-records' ) . '</strong> ' .
		esc_html( $message ) . '</p>';
}

/**
 * Clear the cached copy of the records data whenever a page is saved, so an
 * editor checking their work never sees a stale table.
 */
function archery_records_flush_on_save() {
	archery_records_clear_cache();
}
add_action( 'save_post_page', 'archery_records_flush_on_save' );
