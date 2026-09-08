<?php
/**
 * Builds the HTML for a records page.
 *
 * The page is a section per bow type. Each section has its own strip of class tabs
 * sitting directly on top of its table, and the table lists the rounds as its rows. So a
 * reader sees every distance and every round together for the class they care about,
 * rather than scanning down thirty-five separate tables looking for their own line in
 * each - and because every bow carries its own tabs, two of them can show different
 * classes at once: Gents Compound alongside Ladies Recurve.
 *
 * Every row comes from the records database, including the empty ones that read "no
 * current record" - the database holds a row for a category nobody has claimed.
 *
 * The "+" works the same way as before, and the same way Archery Europe's does: the
 * previous holders are already in the table as hidden rows, and the button unhides
 * them. No second request, no client-side data.
 *
 * With JavaScript off, every class shows one after another with a heading each, so the
 * page is still complete and readable.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render one records page.
 *
 * @param string $page_key Page key.
 * @param array  $options  heading_level, show_archived, show_history.
 * @return string HTML.
 */
function archery_records_render_page( $page_key, $options ) {
	$data         = archery_records_get_data();
	$progressions = archery_records_build_progressions( $data['submissions'] );
	$vacancies    = archery_records_collect_vacancies( $data['submissions'] );
	$rounds       = archery_records_rounds_for_page( $page_key );

	$entries = archery_records_collect_entries( $rounds, $progressions, $vacancies, $options );
	$bows    = archery_records_group_by_bow( $entries );

	$html = '<div class="archery-records-page" data-page="' . esc_attr( $page_key ) . '">';

	// With JavaScript off neither the tabs nor the "+" can do anything, so show every
	// class one after another and every previous holder, rather than hiding content
	// behind controls that will never respond.
	$html .= '<noscript><style>' .
		'.archery-records-page .archery-records-tabs{display:none}' .
		'.archery-records-page .archery-records-panel[hidden]{display:block !important}' .
		'.archery-records-page .archery-records-history{display:table-row !important}' .
		'.archery-records-page .archery-records-toggle{display:none}' .
		'</style></noscript>';

	$html .= archery_records_status_notice( $data );

	if ( empty( $bows ) ) {
		$html .= archery_records_admin_only_notice(
			sprintf(
				/* translators: %s: the page key */
				__( 'No records were found for the page "%s".', 'archery-records' ),
				$page_key
			)
		);
		$html .= archery_records_problems_notice( $data ) . '</div>';

		return $html;
	}

	$page_id = 'ar-' . substr( md5( $page_key ), 0, 8 );

	foreach ( $bows as $bow => $classes ) {
		$html .= archery_records_render_bow_section( $bow, $classes, $page_id, $options );
	}

	$html .= archery_records_problems_notice( $data );
	$html .= '</div>';

	return $html;
}

/**
 * Collect the categories that exist but hold no record.
 *
 * @param array $submissions Normalised submissions.
 * @return array Record key => the vacant row.
 */
function archery_records_collect_vacancies( $submissions ) {
	$vacancies = array();

	foreach ( $submissions as $submission ) {
		if ( ! empty( $submission['vacant'] ) ) {
			$vacancies[ archery_records_record_key( $submission ) ] = $submission;
		}
	}

	return $vacancies;
}

/**
 * Flatten a page into one entry per record: which round, class, bow and peg it is for,
 * and its progression (empty if nobody holds it).
 *
 * @param array $rounds       Rounds on this page, in order.
 * @param array $progressions Record progressions.
 * @param array $vacancies    Vacant categories.
 * @param array $options      Render options.
 * @return array List of entries.
 */
function archery_records_collect_entries( $rounds, $progressions, $vacancies, $options ) {
	$entries = array();
	$seen    = array();

	foreach ( $rounds as $round_key => $round ) {
		if ( ! empty( $round['archived'] ) && empty( $options['show_archived'] ) ) {
			continue;
		}

		$prefix = $round_key . '|';

		foreach ( $progressions as $key => $progression ) {
			if ( 0 !== strpos( $key, $prefix ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$entries[]    = archery_records_entry( $round_key, $round, $key, $progression[0], $progression );
		}

		foreach ( $vacancies as $key => $vacant ) {
			if ( 0 !== strpos( $key, $prefix ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$entries[]    = archery_records_entry( $round_key, $round, $key, $vacant, array() );
		}
	}

	return $entries;
}

/**
 * Build one entry.
 *
 * @param string $round_key   Round key.
 * @param array  $round       Round definition.
 * @param string $key         Record key.
 * @param array  $sample      Any submission for this record, for its classification.
 * @param array  $progression The progression, or an empty array.
 * @return array
 */
function archery_records_entry( $round_key, $round, $key, $sample, $progression ) {
	return array(
		'key'         => $key,
		'round_key'   => $round_key,
		'heading'     => $round['heading'],
		'order'       => isset( $round['order'] ) ? (int) $round['order'] : 9999,
		'archived'    => ! empty( $round['archived'] ),
		'class'       => isset( $sample['classification']['class'] ) ? $sample['classification']['class'] : '',
		'peg'         => isset( $sample['classification']['peg'] ) ? $sample['classification']['peg'] : '',
		'bow'         => $sample['bow'],
		'progression' => $progression,
	);
}

/**
 * Group entries by bow and then by class, dropping anything with no records at all.
 *
 * A class that holds no record for a bow is left out of that bow's tabs: a table of
 * nothing but "no current record" carries no information. Within a class that does hold
 * something, the unclaimed rounds stay visible, so it is still clear what is up for grabs.
 *
 * Bow comes first because each bow gets its own tab strip, so a reader can have Gents
 * Compound and Ladies Recurve open at the same time.
 *
 * @param array $entries Entries from archery_records_collect_entries().
 * @return array Bow => class => list of entries, all in display order.
 */
function archery_records_group_by_bow( $entries ) {
	$bows = array();

	foreach ( $entries as $entry ) {
		$bows[ $entry['bow'] ][ $entry['class'] ][] = $entry;
	}

	foreach ( $bows as $bow => $classes ) {
		foreach ( $classes as $class => $rows ) {
			$has_record = false;
			foreach ( $rows as $row ) {
				if ( ! empty( $row['progression'] ) ) {
					$has_record = true;
					break;
				}
			}

			if ( ! $has_record ) {
				unset( $bows[ $bow ][ $class ] );
				continue;
			}

			usort( $bows[ $bow ][ $class ], 'archery_records_compare_entries' );
		}

		if ( empty( $bows[ $bow ] ) ) {
			unset( $bows[ $bow ] );
			continue;
		}

		uksort(
			$bows[ $bow ],
			function ( $a, $b ) {
				$rank = archery_records_class_rank( $a ) - archery_records_class_rank( $b );
				return ( 0 !== $rank ) ? $rank : strcmp( $a, $b );
			}
		);
	}

	uksort(
		$bows,
		function ( $a, $b ) {
			$rank = archery_records_bow_rank( $a ) - archery_records_bow_rank( $b );
			return ( 0 !== $rank ) ? $rank : strcmp( $a, $b );
		}
	);

	return $bows;
}

/**
 * Order the rows of a table: live rounds before retired ones, then the page's round
 * order, then by peg.
 *
 * @param array $a Entry.
 * @param array $b Entry.
 * @return int
 */
function archery_records_compare_entries( $a, $b ) {
	$archived_a = $a['archived'] ? 1 : 0;
	$archived_b = $b['archived'] ? 1 : 0;
	if ( $archived_a !== $archived_b ) {
		return $archived_a - $archived_b;
	}

	if ( $a['order'] !== $b['order'] ) {
		return $a['order'] - $b['order'];
	}

	$peg = archery_records_peg_rank( $a['peg'] ) - archery_records_peg_rank( $b['peg'] );
	if ( 0 !== $peg ) {
		return $peg;
	}

	return strcmp( $a['heading'], $b['heading'] );
}

/**
 * One bow type: its heading, its own strip of class tabs, and a table per class.
 *
 * Each bow carries its own tabs so that two bows can be showing different classes at
 * once - Gents Compound alongside Ladies Recurve.
 *
 * @param string $bow     Bow label.
 * @param array  $classes Class => entries, in display order.
 * @param string $page_id Prefix for element ids.
 * @param array  $options Render options.
 * @return string HTML.
 */
function archery_records_render_bow_section( $bow, $classes, $page_id, $options ) {
	$bow_id = $page_id . '-' . archery_records_slug( $bow );

	$html = '<div class="archery-records-bow">';
	$html .= archery_records_heading( $bow, $options['heading_level'], 'archery-records-bow-heading' );

	$html .= '<div class="archery-records-tabs" role="tablist" aria-label="' .
		/* translators: %s: bow type */
		esc_attr( sprintf( __( '%s record class', 'archery-records' ), $bow ) ) . '">';

	$first = true;
	foreach ( $classes as $class => $entries ) {
		$slug  = archery_records_slug( $class );
		$html .= '<button type="button" role="tab" class="archery-records-tab"' .
			' id="' . esc_attr( $bow_id . '-tab-' . $slug ) . '"' .
			' aria-controls="' . esc_attr( $bow_id . '-panel-' . $slug ) . '"' .
			' aria-selected="' . ( $first ? 'true' : 'false' ) . '"' .
			' tabindex="' . ( $first ? '0' : '-1' ) . '">' .
			esc_html( $class ) . '</button>';
		$first = false;
	}

	$html .= '</div>';

	$first = true;
	foreach ( $classes as $class => $entries ) {
		$slug  = archery_records_slug( $class );
		$html .= '<section class="archery-records-panel" role="tabpanel"' .
			' id="' . esc_attr( $bow_id . '-panel-' . $slug ) . '"' .
			' aria-labelledby="' . esc_attr( $bow_id . '-tab-' . $slug ) . '"' .
			( $first ? '' : ' hidden' ) . '>';

		// Visible only when the tabs are not running, so the page still reads as a list
		// of classes if the JavaScript does not load.
		$html .= archery_records_heading( $class, $options['heading_level'] + 1, 'archery-records-class-heading' );
		$html .= archery_records_render_table( $entries, $options );
		$html .= '</section>';

		$first = false;
	}

	return $html . '</div>';
}

/**
 * One table: the rounds for a single bow and class, as rows.
 *
 * @param array $entries Entries for this bow and class, in order.
 * @param array $options Render options.
 * @return string HTML.
 */
function archery_records_render_table( $entries, $options ) {
	$has_peg = false;
	$archers = 1;
	$history = false;

	foreach ( $entries as $entry ) {
		if ( '' !== $entry['peg'] ) {
			$has_peg = true;
		}
		foreach ( $entry['progression'] as $row ) {
			$archers = max( $archers, count( $row['archers'] ) );
		}
		if ( count( $entry['progression'] ) > 1 ) {
			$history = true;
		}
	}

	$show_history = ! empty( $options['show_history'] ) && $history;
	$columns      = archery_records_columns_for( $has_peg, $archers, $show_history );

	$html  = '<div class="archery-records-scroller">';
	$html .= '<table class="archery-records-table">';

	$html .= '<thead><tr>';
	foreach ( $columns as $column ) {
		if ( 'toggle' === $column ) {
			$html .= '<th scope="col" class="archery-records-toggle-col"><span class="archery-records-sr">' .
				esc_html__( 'Previous holders', 'archery-records' ) . '</span></th>';
		} else {
			$html .= '<th scope="col">' . esc_html( archery_records_column_label( $column ) ) . '</th>';
		}
	}
	$html .= '</tr></thead><tbody>';

	$previous_round = null;
	$archived_seen  = false;
	$index          = 0;

	foreach ( $entries as $entry ) {
		if ( $entry['archived'] && ! $archived_seen ) {
			$html .= '<tr class="archery-records-divider"><td colspan="' . esc_attr( count( $columns ) ) . '">' .
				esc_html__( 'No longer shot for', 'archery-records' ) . '</td></tr>';
			$archived_seen  = true;
			$previous_round = null;
		}

		$html .= archery_records_render_entry( $entry, $columns, $show_history, $previous_round, $index );

		$previous_round = $entry['heading'];
		$index++;
	}

	$html .= '</tbody></table></div>';

	return $html;
}

/**
 * The columns a table of this shape needs.
 *
 * @param bool $has_peg      Whether the rounds use pegs.
 * @param int  $archers      How many archer columns are needed.
 * @param bool $show_history Whether to add the toggle column.
 * @return array List of column keys; 'archer' may appear more than once.
 */
function archery_records_columns_for( $has_peg, $archers, $show_history ) {
	$columns = array( 'round' );

	if ( $has_peg ) {
		$columns[] = 'peg';
	}
	$columns[] = 'score';

	for ( $i = 0; $i < max( 1, (int) $archers ); $i++ ) {
		$columns[] = 'archer';
	}

	$columns[] = 'date';
	$columns[] = 'club';

	if ( $show_history ) {
		$columns[] = 'toggle';
	}

	return $columns;
}

/**
 * The visible header for a column.
 *
 * @param string $column Column key.
 * @return string
 */
function archery_records_column_label( $column ) {
	switch ( $column ) {
		case 'round':
			return __( 'Round', 'archery-records' );
		case 'peg':
			return __( 'Peg', 'archery-records' );
		case 'score':
			return __( 'Score', 'archery-records' );
		case 'archer':
			return __( 'Archer', 'archery-records' );
		case 'date':
			return __( 'Date', 'archery-records' );
		case 'club':
			return __( 'Club', 'archery-records' );
	}

	return '';
}

/**
 * Render one round's row, then its hidden history rows.
 *
 * @param array       $entry          Entry.
 * @param array       $columns        Column keys.
 * @param bool        $show_history   Whether history is shown on this table.
 * @param string|null $previous_round Heading of the row above, for dimming repeats.
 * @param int         $index          Position in the table, for striping.
 * @return string HTML.
 */
function archery_records_render_entry( $entry, $columns, $show_history, $previous_round, $index ) {
	$stripe = ( 1 === $index % 2 ) ? ' archery-records-row--alt' : '';

	if ( empty( $entry['progression'] ) ) {
		return archery_records_render_vacant_row( $entry, $columns, $previous_round, $stripe );
	}

	$row_id  = 'ar-' . substr( md5( $entry['key'] ), 0, 12 );
	$history = $show_history ? array_slice( $entry['progression'], 1 ) : array();

	$history_ids = array();
	for ( $i = 1; $i <= count( $history ); $i++ ) {
		$history_ids[] = $row_id . '-h' . $i;
	}

	$html  = '<tr class="archery-records-row' . $stripe . '">';
	$html .= archery_records_cells( $entry, $columns, $entry['progression'][0], $previous_round, false );

	if ( in_array( 'toggle', $columns, true ) ) {
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

	foreach ( $history as $i => $row ) {
		$html .= '<tr class="archery-records-history" id="' . esc_attr( $history_ids[ $i ] ) . '" hidden>';
		$html .= archery_records_cells( $entry, $columns, $row, null, true );
		if ( in_array( 'toggle', $columns, true ) ) {
			$html .= '<td class="archery-records-toggle-col"></td>';
		}
		$html .= '</tr>';
	}

	return $html;
}

/**
 * Render the data cells of one row, in column order.
 *
 * @param array       $entry          Entry.
 * @param array       $columns        Column keys.
 * @param array       $row            The submission being shown.
 * @param string|null $previous_round Heading of the row above, or null.
 * @param bool        $is_history     Whether this is a previous holder.
 * @return string HTML.
 */
function archery_records_cells( $entry, $columns, $row, $previous_round, $is_history ) {
	$archer_index = 0;
	$html         = '';

	foreach ( $columns as $column ) {
		if ( 'toggle' === $column ) {
			continue; // Added by the caller.
		}

		$classes = array();
		$value   = '';

		switch ( $column ) {
			case 'round':
				$value = $entry['heading'];
				// A round repeats when a field table lists several pegs for it.
				if ( $is_history || ( null !== $previous_round && $previous_round === $value ) ) {
					$classes[] = 'archery-records-repeat';
				}
				break;

			case 'peg':
				$value = $entry['peg'];
				if ( $is_history ) {
					$classes[] = 'archery-records-repeat';
				}
				break;

			case 'score':
				$value     = (string) $row['score'];
				$classes[] = 'archery-records-score';
				break;

			case 'archer':
				$value = isset( $row['archers'][ $archer_index ] ) ? $row['archers'][ $archer_index ] : '';
				$archer_index++;
				break;

			case 'date':
				$value = archery_records_format_date( $row['date'] );
				break;

			case 'club':
				$value = $row['club'];
				break;
		}

		$class_attr = $classes ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : '';
		$html      .= '<td' . $class_attr . '>' . esc_html( $value ) . '</td>';
	}

	return $html;
}

/**
 * Render a row for a round nobody holds a record in.
 *
 * @param array       $entry          Entry.
 * @param array       $columns        Column keys.
 * @param string|null $previous_round Heading of the row above.
 * @param string      $stripe         Extra class for alternate-row shading.
 * @return string HTML.
 */
function archery_records_render_vacant_row( $entry, $columns, $previous_round, $stripe ) {
	$leading = 0;
	foreach ( $columns as $column ) {
		if ( in_array( $column, array( 'round', 'peg' ), true ) ) {
			$leading++;
		} else {
			break;
		}
	}

	$html = '<tr class="archery-records-row archery-records-vacant' . $stripe . '">';

	$index = 0;
	foreach ( $columns as $column ) {
		if ( $index >= $leading ) {
			break;
		}

		if ( 'peg' === $column ) {
			$value   = $entry['peg'];
			$repeats = false;
		} else {
			$value   = $entry['heading'];
			$repeats = ( null !== $previous_round && $previous_round === $value );
		}

		$html .= '<td' . ( $repeats ? ' class="archery-records-repeat"' : '' ) . '>' . esc_html( $value ) . '</td>';
		$index++;
	}

	$span  = max( 1, count( $columns ) - $leading );
	$html .= '<td class="archery-records-none" colspan="' . esc_attr( $span ) . '">' .
		esc_html__( 'no current record', 'archery-records' ) . '</td>';
	$html .= '</tr>';

	return $html;
}

/**
 * A slug safe for use in an element id.
 *
 * @param string $value Text.
 * @return string
 */
function archery_records_slug( $value ) {
	$value = strtolower( $value );
	$value = preg_replace( '/[^a-z0-9]+/', '-', $value );

	return trim( preg_replace( '/-+/', '-', $value ), '-' );
}

/**
 * Present a date the way the records pages have always shown them.
 *
 * @param string $date Date as the data source gave it.
 * @return string
 */
function archery_records_format_date( $date ) {
	$date = trim( (string) $date );

	if ( '' === $date ) {
		return '';
	}

	if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
		$timestamp = gmmktime( 0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] );
		if ( $timestamp ) {
			return gmdate( 'd-M-y', $timestamp );
		}
	}

	return $date;
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
	$level   = max( 2, min( 6, (int) $level ) );
	$tag     = 'h' . $level;
	$id_attr = ( '' !== $id ) ? ' id="' . esc_attr( $id ) . '"' : '';

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
