<?php
/**
 * Builds the HTML for a records page.
 *
 * The markup follows the same idea as the Archery Europe records pages: every
 * row, current holder and previous holders alike, is rendered into the table,
 * and the previous ones start out hidden. The "+" simply unhides them. There is
 * no second request and no client-side data, so the page works the same whether
 * or not the JavaScript loads.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render one records page.
 *
 * @param string $page_key Page key from config/layout.json.
 * @param array  $options  heading_level, show_archived, show_history.
 * @return string HTML.
 */
function archery_records_render_page( $page_key, $options ) {
	$layout = archery_records_get_layout();
	$page   = $layout['pages'][ $page_key ];
	$data   = archery_records_get_data();

	$progressions = archery_records_build_progressions( $data['submissions'] );
	$used_keys    = array();

	$html  = '<div class="archery-records-page" data-page="' . esc_attr( $page_key ) . '">';

	// With JavaScript off, the "+" cannot do anything, so show the full history
	// rather than hiding it behind a button that will never work.
	$html .= '<noscript><style>' .
		'.archery-records-page .archery-records-history{display:table-row !important}' .
		'.archery-records-page .archery-records-toggle{display:none}' .
		'</style></noscript>';

	$html .= archery_records_status_notice( $data );

	$rounds_rendered = 0;
	$section_shown   = '';

	foreach ( (array) $page['rounds'] as $round_key ) {
		if ( ! isset( $layout['rounds'][ $round_key ] ) ) {
			continue;
		}

		$round = $layout['rounds'][ $round_key ];

		if ( ! empty( $round['archived'] ) && empty( $options['show_archived'] ) ) {
			continue;
		}

		// The heading that introduces a block of retired rounds, shown once, and
		// only where it actually separates one part of the page from another.
		$section = isset( $round['section'] ) ? (string) $round['section'] : '';
		if ( '' !== $section && $section !== $section_shown && $rounds_rendered > 0 ) {
			$html         .= archery_records_heading( $section, $options['heading_level'], 'archery-records-section' );
			$section_shown = $section;
		}

		$html .= archery_records_render_round( $round_key, $round, $progressions, $options, $used_keys );
		$rounds_rendered++;
	}

	$html .= archery_records_unmapped_notice( $progressions, $used_keys, $layout );
	$html .= archery_records_problems_notice( $data );
	$html .= '</div>';

	return $html;
}

/**
 * Render the heading and table for a single round.
 *
 * @param string $round_key    Round key.
 * @param array  $round        Round definition.
 * @param array  $progressions All record progressions, keyed by record key.
 * @param array  $options      Render options.
 * @param array  $used_keys    Accumulator of record keys that have been rendered.
 * @return string HTML.
 */
function archery_records_render_round( $round_key, $round, $progressions, $options, &$used_keys ) {
	$show_history        = ! empty( $options['show_history'] );
	$columns             = archery_records_columns( $round, $show_history );
	$classification_keys = isset( $round['classification_keys'] ) ? (array) $round['classification_keys'] : array();
	$heading_id          = 'ar-' . substr( md5( $round_key ), 0, 10 );

	$html  = archery_records_heading( $round['heading'], $options['heading_level'], 'archery-records-heading', $heading_id );
	$html .= '<div class="archery-records-scroller">';
	$html .= '<table class="archery-records-table" aria-labelledby="' . esc_attr( $heading_id ) . '">';

	$html .= '<thead><tr>';
	foreach ( $columns as $label ) {
		if ( '' === $label ) {
			$html .= '<th scope="col" class="archery-records-toggle-col"><span class="archery-records-sr">' .
				esc_html__( 'Previous holders', 'archery-records' ) . '</span></th>';
		} else {
			$html .= '<th scope="col">' . esc_html( $label ) . '</th>';
		}
	}
	$html .= '</tr></thead><tbody>';

	$previous_row = null;
	$row_index    = 0;

	foreach ( (array) $round['grid'] as $grid_row ) {
		$classification = array();
		foreach ( $classification_keys as $key ) {
			$classification[ $key ] = isset( $grid_row[ $key ] ) ? $grid_row[ $key ] : '';
		}
		$bow = isset( $grid_row['bow'] ) ? $grid_row['bow'] : '';

		$key = archery_records_key_from_parts( $round_key, $classification, $bow );
		if ( isset( $used_keys[ $key ] ) ) {
			continue;
		}
		$used_keys[ $key ] = true;

		$progression = isset( $progressions[ $key ] ) ? $progressions[ $key ] : array();

		$this_row        = $classification;
		$this_row['bow'] = $bow;

		$html .= archery_records_render_record( $key, $round, $columns, $classification, $bow, $progression, $show_history, $previous_row, $row_index );

		$previous_row = $this_row;
		$row_index++;
	}

	// Anything in the data for this round that the layout file does not list -
	// a class or bow that has been added since the configuration was written.
	// These appear automatically rather than needing a config edit first.
	$prefix = $round_key . '|';
	foreach ( $progressions as $key => $progression ) {
		if ( isset( $used_keys[ $key ] ) || 0 !== strpos( $key, $prefix ) ) {
			continue;
		}
		$used_keys[ $key ] = true;

		$current        = $progression[0];
		$classification = array();
		foreach ( $classification_keys as $ckey ) {
			$classification[ $ckey ] = isset( $current['classification'][ $ckey ] ) ? $current['classification'][ $ckey ] : '';
		}

		$html .= archery_records_render_record( $key, $round, $columns, $classification, $current['bow'], $progression, $show_history, null, $row_index );
		$row_index++;
	}

	$html .= '</tbody></table></div>';

	return $html;
}

/**
 * Render the current-holder row for one record, plus its hidden history rows.
 *
 * @param string     $key            Record key.
 * @param array      $round          Round definition.
 * @param array      $columns        Column labels including the toggle column.
 * @param array      $classification Classification field => value.
 * @param string     $bow            Bow type.
 * @param array      $progression    Record-setting submissions, current first.
 * @param bool       $show_history   Whether to render the history at all.
 * @param array|null $previous       Leading values of the row above, for dimming repeated ones.
 * @param int        $row_index      Position of this record in its table, for striping.
 * @return string HTML.
 */
function archery_records_render_record( $key, $round, $columns, $classification, $bow, $progression, $show_history, $previous, $row_index = 0 ) {
	$row_id  = 'ar-' . substr( md5( $key ), 0, 12 );
	$history = $show_history ? array_slice( $progression, 1 ) : array();

	$stripe = ( 1 === $row_index % 2 ) ? ' archery-records-row--alt' : '';

	if ( empty( $progression ) ) {
		return archery_records_render_vacant_row( $round, $columns, $classification, $bow, $previous, $stripe );
	}

	$history_ids = array();
	for ( $i = 1; $i <= count( $history ); $i++ ) {
		$history_ids[] = $row_id . '-h' . $i;
	}

	$html  = '<tr class="archery-records-row' . $stripe . '">';
	$html .= archery_records_cells( $round, $columns, $classification, $bow, $progression[0], $previous, false );

	if ( in_array( '', $columns, true ) ) {
		$html .= '<td class="archery-records-toggle-col">';
		if ( ! empty( $history ) ) {
			$html .= '<button type="button" class="archery-records-toggle" aria-expanded="false" aria-controls="' .
				esc_attr( implode( ' ', $history_ids ) ) . '">' .
				'<span class="archery-records-toggle-icon" aria-hidden="true"></span>' .
				'<span class="archery-records-sr">' .
				esc_html(
					sprintf(
						/* translators: %d: number of previous record holders */
						_n( 'Show %d previous holder', 'Show %d previous holders', count( $history ), 'archery-records' ),
						count( $history )
					)
				) .
				'</span></button>';
		}
		$html .= '</td>';
	}

	$html .= '</tr>';

	foreach ( $history as $i => $entry ) {
		$html .= '<tr class="archery-records-history" id="' . esc_attr( $history_ids[ $i ] ) . '" hidden>';
		$html .= archery_records_cells( $round, $columns, $classification, $bow, $entry, null, true );
		if ( in_array( '', $columns, true ) ) {
			$html .= '<td class="archery-records-toggle-col"></td>';
		}
		$html .= '</tr>';
	}

	return $html;
}

/**
 * Render the data cells of one row, in the column order the round defines.
 *
 * @param array      $round          Round definition.
 * @param array      $columns        Column labels.
 * @param array      $classification Classification field => value.
 * @param string     $bow            Bow type.
 * @param array      $entry          The submission being shown.
 * @param array|null $previous       Leading values of the row above, or null.
 * @param bool       $is_history     Whether this is a previous holder.
 * @return string HTML.
 */
function archery_records_cells( $round, $columns, $classification, $bow, $entry, $previous, $is_history ) {
	$classification_keys = isset( $round['classification_keys'] ) ? (array) $round['classification_keys'] : array();
	$archer_index        = 0;
	$html                = '';

	foreach ( $columns as $label ) {
		$field = strtolower( $label );

		if ( '' === $label ) {
			continue; // The toggle column is added by the caller.
		}

		$classes = array();
		$value   = '';

		if ( in_array( $field, $classification_keys, true ) ) {
			$value = isset( $classification[ $field ] ) ? $classification[ $field ] : '';
			// Dim only a value that is genuinely the same as the row above, which is
			// what the hand-built tables conveyed by leaving the cell blank.
			if ( $is_history || ( is_array( $previous ) && isset( $previous[ $field ] ) && $previous[ $field ] === $value ) ) {
				$classes[] = 'archery-records-repeat';
			}
		} elseif ( 'bow' === $field ) {
			$value = $bow;
			if ( $is_history ) {
				$classes[] = 'archery-records-repeat';
			}
		} elseif ( 'score' === $field ) {
			$value     = (string) $entry['score'];
			$classes[] = 'archery-records-score';
		} elseif ( 'archer' === $field ) {
			$value = isset( $entry['archers'][ $archer_index ] ) ? $entry['archers'][ $archer_index ] : '';
			$archer_index++;
		} elseif ( 'date' === $field ) {
			$value = $entry['date'];
		} elseif ( 'club' === $field ) {
			$value = $entry['club'];
		} elseif ( 'venue' === $field || 'place' === $field ) {
			$value = $entry['venue'];
		}

		$class_attr = $classes ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : '';
		$html      .= '<td' . $class_attr . '>' . esc_html( $value ) . '</td>';
	}

	return $html;
}

/**
 * Render a row for a classification that nobody holds a record in.
 *
 * @param array      $round          Round definition.
 * @param array      $columns        Column labels.
 * @param array      $classification Classification field => value.
 * @param string     $bow            Bow type.
 * @param array|null $previous       Leading values of the row above, or null.
 * @param string     $stripe         Extra class for alternate-row shading.
 * @return string HTML.
 */
function archery_records_render_vacant_row( $round, $columns, $classification, $bow, $previous, $stripe = '' ) {
	$classification_keys = isset( $round['classification_keys'] ) ? (array) $round['classification_keys'] : array();

	$leading = 0;
	foreach ( $columns as $label ) {
		$field = strtolower( $label );
		if ( in_array( $field, $classification_keys, true ) || 'bow' === $field ) {
			$leading++;
		} else {
			break;
		}
	}

	$html = '<tr class="archery-records-row archery-records-vacant' . $stripe . '">';

	$index = 0;
	foreach ( $columns as $label ) {
		if ( $index >= $leading ) {
			break;
		}
		$field   = strtolower( $label );
		$value   = ( 'bow' === $field ) ? $bow : ( isset( $classification[ $field ] ) ? $classification[ $field ] : '' );
		$repeats = ( 'bow' !== $field ) && is_array( $previous ) && isset( $previous[ $field ] ) && $previous[ $field ] === $value;
		$classes = $repeats ? ' class="archery-records-repeat"' : '';
		$html   .= '<td' . $classes . '>' . esc_html( $value ) . '</td>';
		$index++;
	}

	$span  = max( 1, count( $columns ) - $leading );
	$html .= '<td class="archery-records-none" colspan="' . esc_attr( $span ) . '">' .
		esc_html__( 'no current record', 'archery-records' ) . '</td>';
	$html .= '</tr>';

	return $html;
}

/**
 * A heading element at the configured level.
 *
 * @param string $text  Heading text.
 * @param int    $level 2 to 6.
 * @param string $class CSS class.
 * @param string $id    Optional id.
 * @return string HTML.
 */
function archery_records_heading( $text, $level, $class, $id = '' ) {
	$tag      = 'h' . (int) $level;
	$id_attr  = ( '' !== $id ) ? ' id="' . esc_attr( $id ) . '"' : '';

	return '<' . $tag . ' class="' . esc_attr( $class ) . '"' . $id_attr . '>' . esc_html( $text ) . '</' . $tag . '>';
}

/**
 * Tell an editor - never a visitor - that the tables are not coming from live data.
 *
 * @param array $data Result of archery_records_get_data().
 * @return string HTML.
 */
function archery_records_status_notice( $data ) {
	if ( 'stale' === $data['status'] ) {
		return archery_records_admin_only_notice(
			__( 'The records database could not be read, so these tables are showing the last copy that was read successfully. The reason has been written to the error log.', 'archery-records' )
		);
	}

	if ( 'unavailable' === $data['status'] ) {
		return archery_records_admin_only_notice(
			__( 'The records database could not be read and there is no earlier copy to fall back on, so the tables below are empty. The reason has been written to the error log.', 'archery-records' )
		);
	}

	return '';
}

/**
 * Warn an editor about records whose round is not in the layout configuration.
 *
 * These would otherwise vanish silently, which is the failure mode most likely
 * to go unnoticed: a new round is added to the database and simply never appears.
 *
 * @param array $progressions All progressions.
 * @param array $used_keys    Keys already rendered anywhere on this page.
 * @param array $layout       Layout configuration.
 * @return string HTML.
 */
function archery_records_unmapped_notice( $progressions, $used_keys, $layout ) {
	$unmapped = array();

	foreach ( $progressions as $key => $progression ) {
		if ( isset( $used_keys[ $key ] ) ) {
			continue;
		}
		$round = $progression[0]['round'];
		if ( ! isset( $layout['rounds'][ $round ] ) ) {
			$unmapped[ $round ] = true;
		}
	}

	if ( empty( $unmapped ) ) {
		return '';
	}

	return archery_records_admin_only_notice(
		sprintf(
			/* translators: %s: comma-separated list of round keys */
			__( 'The records database contains rounds that config/layout.json does not know about, so they are not shown on any page: %s', 'archery-records' ),
			implode( ', ', array_keys( $unmapped ) )
		)
	);
}

/**
 * Warn an editor about rows that were dropped as malformed.
 *
 * @param array $data Result of archery_records_get_data().
 * @return string HTML.
 */
function archery_records_problems_notice( $data ) {
	if ( empty( $data['problems'] ) ) {
		return '';
	}

	$shown = array_slice( $data['problems'], 0, 5 );
	$more  = count( $data['problems'] ) - count( $shown );
	$text  = implode( ' ', $shown );

	if ( $more > 0 ) {
		$text .= sprintf(
			/* translators: %d: number of further problems */
			_n( ' (and %d further problem)', ' (and %d further problems)', $more, 'archery-records' ),
			$more
		);
	}

	return archery_records_admin_only_notice(
		sprintf(
			/* translators: %s: list of problems */
			__( 'Some rows from the records database could not be used: %s', 'archery-records' ),
			$text
		)
	);
}
