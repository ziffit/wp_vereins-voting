<?php
/**
 * [FU002] Verwaltung von Abstimmungen (CRUD) — via vv_save_poll / vv_delete_poll.
 */
return [

	'test_02_fu002_ak01_neue_abstimmung_anlegt' => function () {
		$start = date( 'Y-m-d\TH:i', strtotime( '-1 hour' ) );
		$end   = date( 'Y-m-d\TH:i', strtotime( '+1 day' ) );
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => 0, 'title' => 'Kassenprüfung 2026',
			'question' => 'Zustimmung?', 'start_dt' => $start, 'end_dt' => $end, 'status' => 'active',
		] );
		[ $kind, $url ] = vv_fx_run( 'vv_save_poll' );
		vv_check( 'redirect' === $kind, '[FU002#AK01] vv_save_poll leitet nach Anlage weiter' );
		// ID ausgehend von redirect zurückverfolgen? Direkt über Titel.
		global $wpdb;
		$poll = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vv_tbl( 'voting_polls' ) . ' WHERE title = %s', 'Kassenprüfung 2026' ) );
		vv_check( $poll && 'Zustimmung?' === $poll->question && $poll->start_dt && $poll->end_dt, '[FU002#AK01] Felder Titel/Frage/Zeitraum korrekt gespeichert' );
		vv_check( 'draft' === $poll->status, '[FU002#AK02] neue Abstimmung startet IMMER als draft' );
	},

	'test_02_fu002_ak02_draft_bearbeitbar' => function () {
		$pid = vv_fx_poll( [ 'title' => 'Alt' ] );
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => $pid, 'title' => 'Neu', 'question' => 'Geändert?',
			'start_dt' => date( 'Y-m-d\TH:i', strtotime( '+2 hours' ) ),
			'end_dt'   => date( 'Y-m-d\TH:i', strtotime( '+3 days' ) ), 'status' => 'draft',
		] );
		vv_fx_run( 'vv_save_poll' );
		$poll = vv_fx_poll_row( $pid );
		vv_check( 'Neu' === $poll->title && 'Geändert?' === $poll->question, '[FU002#AK02] draft-Abstimmung mit Statusbebits Feldern editierbar' );
	},

	'test_02_fu002_ak02_aktiviert_draft' => function () {
		$pid = vv_fx_poll( [ 'status' => 'draft' ] );
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => $pid, 'title' => 'W', 'question' => 'Q',
			'start_dt' => date( 'Y-m-d\TH:i', strtotime( '-1 hour' ) ),
			'end_dt'   => date( 'Y-m-d\TH:i', strtotime( '+1 day' ) ), 'status' => 'active',
		] );
		vv_fx_run( 'vv_save_poll' );
		vv_check( 'active' === vv_fx_poll_row( $pid )->status, '[FU002#AK02] draft - active Übergang erlaubt' );
	},

	'test_02_fu002_ak02_aktive_wahl_gesperrt' => function () {
		$pid = vv_fx_poll( [ 'status' => 'active' ] );
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => $pid, 'title' => 'HACKED', 'question' => 'X',
			'start_dt' => date( 'Y-m-d\TH:i', strtotime( '+1 hour' ) ),
			'end_dt'   => date( 'Y-m-d\TH:i', strtotime( '+1 day' ) ), 'status' => 'active',
		] );
		vv_fx_run( 'vv_save_poll' );
		$poll = vv_fx_poll_row( $pid );
		vv_check( 'active' === $poll->status && 'Testwahl' !== 'HACKED' && 'HACKED' !== $poll->title,
			'[FU002#AK02] aktive Wahl: sicherheitsrelevante Felder gesperrt (kein Titel-Override)' );
	},

	'test_02_fu002_ak02_closed_nur_outerrolled' => function () {
		$pid = vv_fx_poll( [ 'status' => 'active' ] );
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => $pid, 'title' => 'T', 'question' => 'Q',
			'start_dt' => date( 'Y-m-d\TH:i', strtotime( '-1 hour' ) ),
			'end_dt'   => date( 'Y-m-d\TH:i', strtotime( '+1 day' ) ), 'status' => 'closed',
		] );
		vv_fx_run( 'vv_save_poll' );
		global $wpdb;
		$poll = vv_fx_poll_row( $pid );
		vv_check( 'closed' === $poll->status, '[FU002#AK02] active - closed beenden erlaubt' );

		// Kein Zurück auf draft:
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => $pid, 'title' => 'T', 'question' => 'Q',
			'start_dt' => date( 'Y-m-d\TH:i', strtotime( '-1 hour' ) ),
			'end_dt'   => date( 'Y-m-d\TH:i', strtotime( '+1 day' ) ), 'status' => 'draft',
		] );
		vv_fx_run( 'vv_save_poll' );
		vv_check( 'closed' === vv_fx_poll_row( $pid )->status, '[FU002#AK02] closed - draft wird ignoriert (kein Rückgang)' );
	},

	'test_02_fu002_ak02_plichtfelder' => function () {
		vv_fx_http( [], [ 'vv_action' => 'save_poll', 'poll_id' => 0, 'title' => '', 'start_dt' => '', 'end_dt' => '' ] );
		[ $kind, $msg ] = vv_fx_run( 'vv_save_poll' );
		vv_check( 'die' === $kind && str_contains( (string) $msg, 'Pflichtfelder' ), '[FU002#AK01] fehlende Titel/Zeiten abgewesen' );
	},

	'test_02_fu002_ak02_loeschen_draft_nur' => function () {
		global $wpdb;
		// Beendete Wahl darf nicht gelöscht werden:
		$pid_closed = vv_fx_poll( [ 'status' => 'closed' ] );
		vv_fx_http( [], [ 'vv_action' => 'delete_poll', 'poll_id' => $pid_closed ] );
		[ $kind, $msg ] = vv_fx_run( 'vv_delete_poll' );
		vv_check( 'die' === $kind && str_contains( (string) $msg, 'Entwurf' ), '[FU002#AK02] Löschen beendeter Wahl verweigert' );

		// Entwurf: Abstimmung + Teilnehmer + Urne werden entfernt:
		$pid   = vv_fx_poll();
		vv_fx_voter( $pid, '7', 'del@example.org', 'tt11' );
		vv_fx_ballot( $pid );
		vv_fx_http( [], [ 'vv_action' => 'delete_poll', 'poll_id' => $pid ] );
		[ $kind, ] = vv_fx_run( 'vv_delete_poll' );
		vv_check( 'redirect' === $kind, '[FU002#AK02] Löschen im Entwurf erfolgreich (redirect)' );
		vv_check( null === vv_fx_poll_row( $pid ), '[FU002#AK02] Enturf-Abstimmung gelöscht' );
		vv_check( 0 === vv_fx_voter_count( $pid ) && 0 === vv_fx_ballots_count( $pid ), '[FU002#AK02] Teilnehmer + Urne sauber mitgelöscht' );
	},

	'test_02_fu002_ak01_datetime_normalisierung' => function () {
		$start = '2026-10-19T14:30';
		$end   = '2026-10-20T18:45';
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => 0, 'title' => 'DT-Normalisierung',
			'question' => 'Q', 'start_dt' => $start, 'end_dt' => $end, 'status' => 'draft',
		] );
		vv_fx_run( 'vv_save_poll' );
		global $wpdb;
		$poll = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vv_tbl( 'voting_polls' ) . ' WHERE title = %s', 'DT-Normalisierung' ) );
		vv_check( '2026-10-19 14:30:00' === $poll->start_dt, '[FU002#AK01] datetime-local (T) wird auf MySQL-Format normalisiert (Start)' );
		vv_check( '2026-10-20 18:45:00' === $poll->end_dt, '[FU002#AK01] datetime-local (T) wird auf MySQL-Format normalisiert (Ende)' );
	},

	'test_02_fu002_ak03_aktivierung_nur_im_zeitraum' => function () {
		// Start in der Zukunft: Statuswechsel auf active wird ignoriert.
		$pid = vv_fx_poll( [ 'start_off' => '+1 day', 'end_off' => '+2 days', 'status' => 'draft' ] );
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => $pid, 'title' => 'T', 'question' => 'Q',
			'start_dt' => date( 'Y-m-d\TH:i', strtotime( '+1 day' ) ),
			'end_dt'   => date( 'Y-m-d\TH:i', strtotime( '+2 days' ) ), 'status' => 'active',
		] );
		vv_fx_run( 'vv_save_poll' );
		vv_check( 'draft' === vv_fx_poll_row( $pid )->status, '[FU002#AK03] Aktivierung außerhalb des Zeitraums verweigert' );

		// Formular bietet keinen Aktivieren-Radio an, solange der Zeitraum nicht läuft:
		ob_start();
		vv_render_poll_form( $pid );
		$form = (string) ob_get_clean();
		vv_check( ! str_contains( $form, 'value="active"' ), '[FU002#AK03] Aktivieren-Radio außerhalb des Zeitraums ausgeblendet' );

		// Gleiche Wahl, jetzt im Zeitraum: Wechsel erlaubt.
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => $pid, 'title' => 'T', 'question' => 'Q',
			'start_dt' => date( 'Y-m-d\TH:i', strtotime( '-1 hour' ) ),
			'end_dt'   => date( 'Y-m-d\TH:i', strtotime( '+2 days' ) ), 'status' => 'active',
		] );
		vv_fx_run( 'vv_save_poll' );
		vv_check( 'active' === vv_fx_poll_row( $pid )->status, '[FU002#AK03] Aktivierung im Zeitraum erlaubt' );
	},

	'test_02_fu002_ak04_zeitzonen_hinweis' => function () {
		ob_start();
		vv_timezone_notice();
		$html = (string) ob_get_clean();
		vv_check( str_contains( $html, 'Zeitzone' ), '[FU002#AK04] Hinweis bei UTC ohne Zeitzone' );

		update_option( 'timezone_string', 'Europe/Berlin' );
		ob_start();
		vv_timezone_notice();
		$html = (string) ob_get_clean();
		vv_check( '' === $html, '[FU002#AK04] kein Hinweis, wenn eine Zeitzone gesetzt ist' );
	},
];
