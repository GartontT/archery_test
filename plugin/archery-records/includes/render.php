<?php
/**
 * Builds the HTML for a records page.
 *
 * Every row of every table comes from the records database, including the empty ones
 * that read "no current record" - the database holds a row for a category nobody has
 * claimed, so there is no separate list of expected categories to keep in step.
 *
 * The markup follows the same idea as the Archery Europe records pages: current holder
 * and previous holders alike are rendered into the table, and the previous ones start
 * out hidden. The "+" simply unhides them. There is no second request and no
 * client-side data.
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

	$html = '<div class="archery-records-page" data-page="' . esc_attr( $page_key ) . '">';

	// With JavaScript off, the "+" cannot do anything, so show the full history
	// rather than hiding it behind a button that will never work.
	$html .= '<noscript><style>' .
		'.archery-records-page .archery-records-history{display:table-row !important}' .
		'.archery-records-page .archery-records-toggle{display:none}' .
		'</style></noscript>';

	$html .= archery_records_status_notice( $data );

	$rendered      = 0;
	$archived_seen = false;

	foreach ( $rounds as $round_key => $round ) {
		$archived = ! empty( $round['archived'] );

		if ( $archived && empty( $options['show_archived'] ) ) {
			continue;
		}

		$table = archery_records_render_round( $round_key, $round, $progressions, $vacancies, $options );

		if ( '' === $table ) {
			continue;
		}

		// A single heading introduces the retired rounds at the foot of the page.
		if ( $archived && ! $archived_seen && $rendered > 0 ) {
			$html         .= archery_records_heading(
				__( 'Archived records - no longer shot for', 'archery-records' ),
				$options['heading_level'],
				'archery-records-section'
			);
			$archived_seen = true;
		}

		$html .= $table;
		$rendered++;
	}

	if ( 0 === $rendered ) {
		$html .= archery_records_admin_only_notice(
			sprintf(
				/* translators: %s: the page key */
				__( 'No rounds were found for the page "%s".', 'archery-records' ),
				$page_key
			)
		);
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
 * Render the heading and table for a single round.
 *
 * @param string $round_key    Round key.
 * @param array  $round        Round definition.
 * @param array  $progressions Record progressions, keyed by record key.
 * @param array  $vacancies    Vacant categories, keyed by record key.
 * @param array  $options      Render options.
 * @return string HTML, or an empty string if the round has no rows at all.
 */
function archery_records_render_round( $round_key, $round, $progressions, $vacancies, $options ) {
	$rows = archery_records_rows_for_round( $round_key, $progressions, $vacancies );

	if ( empty( $rows ) ) {
		return '';
	}

	$has_peg     = false;
	$archers     = 1;
	$has_history = false;

	foreach ( $rows as $row ) {
		if ( ! empty( $row['classification']['peg'] ) ) {
			$has_peg = true;
		}
		foreach ( $row['progression'] as $entry ) {
			$archers = max( $archers, count( $entry['archers'] ) );
		}
		if ( count( $row['progression'] ) > 1 ) {
			$has_history = true;
		}
	}

	// Only give the table a toggle column if something on it can actually expand.
	$show_history = ! empty( $options['show_history'] ) && $has_history;

	$columns    = archery_records_columns_for( $has_peg, $archers, $show_history );
	$heading_id = 'ar-' . substr( md5( $round_key ), 0, 10 );

	$html  = archery_records_heading( $round['heading'], $options['heading_level'], 'archery-records-heading', $heading_id );
	$html .= '<div class="archery-records-scroller">';
	$html .= '<table class="archery-records-table" aria-labelledby="' . esc_attr( $heading_id ) . '">';

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

	$previous = null;
	$index    = 0;

	foreach ( $rows as $row ) {
		$html .= archery_records_render_record( $row, $columns, $show_history, $previous, $index );

		$previous        = $row['classification'];
		$previous['bow'] = $row['bow'];
		$index++;
	}

	$html .= '</tbody></table></div>';

	return $html;
}

/**
 * Gather every category belonging to one round, in display order.
 *
 * @param string $round_key    Round key.
 * @param array  $progressions Record progressions.
 * @param array  $vacancies    Vacant categories.
 * @return array List of rows, each with classification, bow, progression and key.
 */
function archery_records_rows_for_round( $round_key, $progressions, $vacancies ) {
	$prefix = $round_key . '|';
	$rows   = array();

	foreach ( $progressions as $key => $progression ) {
		if ( 0 !== strpos( $key, $prefix ) ) {
			continue;
		}
		$current      = $progression[0];
		$rows[ $key ] = array(
			'classification' => $current['classification'],
			'bow'            => $current['bow'],
			'progression'    => $progression,
			'key'            => $key,
		);
	}

	// Categories with no record at all. A category that has since been claimed is
	// already present above, so this never displaces a real record.
	foreach ( $vacancies as $key => $vacant ) {
		if ( 0 !== strpos( $key, $prefix ) || isset( $rows[ $key ] ) ) {
			continue;
		}
		$rows[ $key ] = array(
			'classification' => $vacant['classification'],
			'bow'            => $vacant['bow'],
			'progression'    => array(),
			'key'            => $key,
		);
	}

	uasort( $rows, 'archery_records_compare_rows' );

	return $rows;
}

/**
 * Order rows the way the pages read: by peg, then class, then bow.
 *
 * @param array $a Row.
 * @param array $b Row.
 * @return int
 */
function archery_records_compare_rows( $a, $b ) {
	$peg_a = isset( $a['classification']['peg'] ) ? $a['classification']['peg'] : '';
	$peg_b = isset( $b['classification']['peg'] ) ? $b['classification']['peg'] : '';

	$rank_a = archery_records_peg_rank( $peg_a );
	$rank_b = archery_records_peg_rank( $peg_b );
	if ( $rank_a !== $rank_b ) {
		return $rank_a - $rank_b;
	}

	$class_a = isset( $a['classification']['class'] ) ? $a['classification']['class'] : '';
	$class_b = isset( $b['classification']['class'] ) ? $b['classification']['class'] : '';

	$rank_a = archery_records_class_rank( $class_a );
	$rank_b = archery_records_class_rank( $class_b );
	if ( $rank_a !== $rank_b ) {
		return $rank_a - $rank_b;
	}
	if ( $class_a !== $class_b ) {
		return strcmp( $class_a, $class_b );
	}

	$rank_a = archery_records_bow_rank( $a['bow'] );
	$rank_b = archery_records_bow_rank( $b['bow'] );
	if ( $rank_a !== $rank_b ) {
		return $rank_a - $rank_b;
	}

	return strcmp( $a['bow'], $b['bow'] );
}

/**
 * The columns a table of this shape needs.
 *
 * @param bool $has_peg      Whether the round uses pegs.
 * @param int  $archers      How many archer columns are needed.
 * @param bool $show_history Whether to add the toggle column.
 * @return array List of column keys; 'archer' may appear more than once.
 */
function archery_records_columns_for( $has_peg, $archers, $show_history ) {
	$columns = array();

	if ( $has_peg ) {
		$columns[] = 'peg';
	}
	$columns[] = 'class';
	$columns[] = 'bow';
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
		case 'peg':
			return __( 'Peg', 'archery-records' );
		case 'class':
			return __( 'Class', 'archery-records' );
		case 'bow':
			return __( 'Bow', 'archery-records' );
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
 * Render one record: the current holder, then its hidden history rows.
 *
 * @param array      $row          Row definition.
 * @param array      $columns      Column keys.
 * @param bool       $show_history Whether history is being shown on this table.
 * @param array|null $previous     Classification of the row above, for dimming repeats.
 * @param int        $index        Position in the table, for striping.
 * @return string HTML.
 */
function archery_records_render_record( $row, $columns, $show_history, $previous, $index ) {
	$stripe = ( 1 === $index % 2 ) ? ' archery-records-row--alt' : '';

	if ( empty( $row['progression'] ) ) {
		return archery_records_render_vacant_row( $row, $columns, $previous, $stripe );
	}

	$row_id  = 'ar-' . substr( md5( $row['key'] ), 0, 12 );
	$history = $show_history ? array_slice( $row['progression'], 1 ) : array();

	$history_ids = array();
	for ( $i = 1; $i <= count( $history ); $i++ ) {
		$history_ids[] = $row_id . '-h' . $i;
	}

	$html  = '<tr class="archery-records-row' . $stripe . '">';
	$html .= archery_records_cells( $row, $columns, $row['progression'][0], $previous, false );

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

	foreach ( $history as $i => $entry ) {
		$html .= '<tr class="archery-records-history" id="' . esc_attr( $history_ids[ $i ] ) . '" hidden>';
		$html .= archery_records_cells( $row, $columns, $entry, null, true );
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
 * @param array      $row        Row definition.
 * @param array      $columns    Column keys.
 * @param array      $entry      The submission being shown.
 * @param array|null $previous   Classification of the row above, or null.
 * @param bool       $is_history Whether this is a previous holder.
 * @return string HTML.
 */
function archery_records_cells( $row, $columns, $entry, $previous, $is_history ) {
	$archer_index = 0;
	$html         = '';

	foreach ( $columns as $column ) {
		if ( 'toggle' === $column ) {
			continue; // Added by the caller.
		}

		$classes = array();
		$value   = '';

		switch ( $column ) {
			case 'peg':
			case 'class':
				$value = isset( $row['classification'][ $column ] ) ? $row['classification'][ $column ] : '';
				// Dim a value only where it genuinely repeats the row above, which is what
				// the hand-built tables conveyed by leaving the cell blank.
				if ( $is_history || ( is_array( $previous ) && isset( $previous[ $column ] ) && $previous[ $column ] === $value ) ) {
					$classes[] = 'archery-records-repeat';
				}
				break;

			case 'bow':
				$value = $row['bow'];
				if ( $is_history ) {
					$classes[] = 'archery-records-repeat';
				}
				break;

			case 'score':
				$value     = (string) $entry['score'];
				$classes[] = 'archery-records-score';
				break;

			case 'archer':
				$value = isset( $entry['archers'][ $archer_index ] ) ? $entry['archers'][ $archer_index ] : '';
				$archer_index++;
				break;

			case 'date':
				$value = archery_records_format_date( $entry['date'] );
				break;

			case 'club':
				$value = $entry['club'];
				break;
		}

		$class_attr = $classes ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : '';
		$html      .= '<td' . $class_attr . '>' . esc_html( $value ) . '</td>';
	}

	return $html;
}

/**
 * Render a row for a category that nobody holds a record in.
 *
 * @param array      $row      Row definition.
 * @param array      $columns  Column keys.
 * @param array|null $previous Classification of the row above.
 * @param string     $stripe   Extra class for alternate-row shading.
 * @return string HTML.
 */
function archery_records_render_vacant_row( $row, $columns, $previous, $stripe ) {
	$leading = 0;
	foreach ( $columns as $column ) {
		if ( in_array( $column, array( 'peg', 'class', 'bow' ), true ) ) {
			$leading++;
		} else {
			break;
		}
	}

	$html  = '<tr class="archery-records-row archery-records-vacant' . $stripe . '">';
	$index = 0;

	foreach ( $columns as $column ) {
		if ( $index >= $leading ) {
			break;
		}

		if ( 'bow' === $column ) {
			$value   = $row['bow'];
			$repeats = false;
		} else {
			$value   = isset( $row['classification'][ $column ] ) ? $row['classification'][ $column ] : '';
			$repeats = is_array( $previous ) && isset( $previous[ $column ] ) && $previous[ $column ] === $value;
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
 * Present a date the way the records pages have always shown them.
 *
 * The database stores proper dates, so this is presentation only. Anything we cannot
 * read is passed through untouched rather than blanked.
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
	$tag     = 'h' . (int) $level;
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
