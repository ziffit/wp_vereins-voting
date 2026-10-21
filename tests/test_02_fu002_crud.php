<?php
/**
 * [FU002] Verwaltung von Abstimmungen (CRUD) — via vv_save_poll / vv_delete_poll.
 */
return [

	'test_02_fu002_ak01_neue_abstimmung_anlegt' => function () {
		$start = date( 'Y-m-d\TH:00', strtotime( '-1 hour' ) );
		$end   = date( 'Y-m-d\TH:00', strtotime( '+1 day' ) );
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => '', 'title' => 'Kassenprüfung 2026',
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
			'start_dt' => date( 'Y-m-d\TH:00', strtotime( '+2 hours' ) ),
			'end_dt'   => date( 'Y-m-d\TH:00', strtotime( '+3 days' ) ), 'status' => 'draft',
		] );
		vv_fx_run( 'vv_save_poll' );
		$poll = vv_fx_poll_row( $pid );
		vv_check( 'Neu' === $poll->title && 'Geändert?' === $poll->question, '[FU002#AK02] draft-Abstimmung mit Statusbebits Feldern editierbar' );
	},

	'test_02_fu002_ak02_aktiviert_draft' => function () {
		$pid = vv_fx_poll( [ 'status' => 'draft' ] );
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => $pid, 'title' => 'W', 'question' => 'Q',
			'start_dt' => date( 'Y-m-d\TH:00', strtotime( '-1 hour' ) ),
			'end_dt'   => date( 'Y-m-d\TH:00', strtotime( '+1 day' ) ), 'status' => 'active',
		] );
		vv_fx_run( 'vv_save_poll' );
		vv_check( 'active' === vv_fx_poll_row( $pid )->status, '[FU002#AK02] draft - active Übergang erlaubt' );
	},

	'test_02_fu002_ak02_aktive_wahl_gesperrt' => function () {
		$pid = vv_fx_poll( [ 'status' => 'active' ] );
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => $pid, 'title' => 'HACKED', 'question' => 'X',
			'start_dt' => date( 'Y-m-d\TH:00', strtotime( '+1 hour' ) ),
			'end_dt'   => date( 'Y-m-d\TH:00', strtotime( '+1 day' ) ), 'status' => 'active',
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
			'start_dt' => date( 'Y-m-d\TH:00', strtotime( '-1 hour' ) ),
			'end_dt'   => date( 'Y-m-d\TH:00', strtotime( '+1 day' ) ), 'status' => 'closed',
		] );
		vv_fx_run( 'vv_save_poll' );
		global $wpdb;
		$poll = vv_fx_poll_row( $pid );
		vv_check( 'closed' === $poll->status, '[FU002#AK02] active - closed beenden erlaubt' );

		// Kein Zurück auf draft:
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => $pid, 'title' => 'T', 'question' => 'Q',
			'start_dt' => date( 'Y-m-d\TH:00', strtotime( '-1 hour' ) ),
			'end_dt'   => date( 'Y-m-d\TH:00', strtotime( '+1 day' ) ), 'status' => 'draft',
		] );
		vv_fx_run( 'vv_save_poll' );
		vv_check( 'closed' === vv_fx_poll_row( $pid )->status, '[FU002#AK02] closed - draft wird ignoriert (kein Rückgang)' );
	},

	'test_02_fu002_ak02_plichtfelder' => function () {
		vv_fx_http( [], [ 'vv_action' => 'save_poll', 'poll_id' => '', 'title' => '', 'start_dt' => '', 'end_dt' => '' ] );
		[ $kind, ] = vv_fx_run( 'vv_save_poll' );
		vv_check( null === $kind, '[FU002#AK01] fehlende Pflichtfelder brechen die Seite nicht ab (kein wp_die)' );

		ob_start();
		vv_render_poll_form( '' );
		$html = (string) ob_get_clean();
		vv_check( str_contains( $html, 'Pflichtfelder' ), '[FU002#AK05] Pflichtfeld-Meldung erscheint in der Maske' );
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
		$start = '2026-10-19T14:00';
		$end   = '2026-10-20T18:00';
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => '', 'title' => 'DT-Normalisierung',
			'question' => 'Q', 'start_dt' => $start, 'end_dt' => $end, 'status' => 'draft',
		] );
		vv_fx_run( 'vv_save_poll' );
		global $wpdb;
		$poll = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vv_tbl( 'voting_polls' ) . ' WHERE title = %s', 'DT-Normalisierung' ) );
		vv_check( '2026-10-19 14:00:00' === $poll->start_dt, '[FU002#AK01] datetime-local (T) wird auf MySQL-Format normalisiert (Start)' );
		vv_check( '2026-10-20 18:00:00' === $poll->end_dt, '[FU002#AK01] datetime-local (T) wird auf MySQL-Format normalisiert (Ende)' );
	},

	'test_02_fu002_ak01_nur_volle_stunden' => function () {
		global $wpdb;
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . vv_tbl( 'voting_polls' ) );
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => '', 'title' => 'Krumme Zeit', 'question' => 'Q',
			'start_dt' => '2026-10-19T14:30', 'end_dt' => '2026-10-20T18:45', 'status' => 'draft',
		] );
		[ $kind, ] = vv_fx_run( 'vv_save_poll' );
		$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . vv_tbl( 'voting_polls' ) );
		vv_check( null === $kind && $before === $after, '[FU002#AK01] Zeiten ohne volle Stunde werden nicht gespeichert' );

		// [FU002#AK05] Meldung + Eingaben bleiben in der Maske:
		ob_start();
		vv_render_poll_form( '' );
		$html = (string) ob_get_clean();
		vv_check( str_contains( $html, 'volle Stunde' ), '[FU002#AK01] Meldung „volle Stunde“ erscheint in der Maske' );
		vv_check( str_contains( $html, 'value="Krumme Zeit"' ), '[FU002#AK05] Titel bleibt nach dem Fehler erhalten' );
	},

	'test_02_fu002_ak03_aktivierung_nur_im_zeitraum' => function () {
		// Start in der Zukunft: Statuswechsel auf active wird ignoriert.
		$pid = vv_fx_poll( [ 'start_off' => '+1 day', 'end_off' => '+2 days', 'status' => 'draft' ] );
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => $pid, 'title' => 'T', 'question' => 'Q',
			'start_dt' => date( 'Y-m-d\TH:00', strtotime( '+1 day' ) ),
			'end_dt'   => date( 'Y-m-d\TH:00', strtotime( '+2 days' ) ), 'status' => 'active',
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
			'start_dt' => date( 'Y-m-d\TH:00', strtotime( '-1 hour' ) ),
			'end_dt'   => date( 'Y-m-d\TH:00', strtotime( '+2 days' ) ), 'status' => 'active',
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

	'test_02_fu002_ak06_fremde_posts_ignoriert' => function () {
		// Heartbeat/fremde Admin-Formulare: kein vv_action -> Handler tut nichts (kein wp_die).
		vv_fx_http( [], [ 'action' => 'heartbeat', 'screen_id' => 'dashboard' ] );
		[ $kind, ] = vv_fx_run( 'vv_handle_admin_actions' );
		vv_check( null === $kind, '[FU002#AK06] fremder Admin-POST wird nicht angefasst' );
	},

	'test_02_fu002_ak06_eigene_aktion_braucht_nonce' => function () {
		vv_fx_http( [], [ 'vv_action' => 'delete_poll', 'poll_id' => 1 ] );
		[ $kind, ] = vv_fx_run( 'vv_handle_admin_actions' );
		vv_check( 'die' === $kind, '[FU002#AK06] eigene Aktion ohne Nonce abgewiesen' );

		$pid = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'delete_poll', 'poll_id' => $pid, 'vv_nonce' => wp_create_nonce( 'vv_admin' ) ] );
		[ $kind, ] = vv_fx_run( 'vv_handle_admin_actions' );
		vv_check( 'redirect' === $kind && null === vv_fx_poll_row( $pid ), '[FU002#AK06] eigene Aktion mit gültigem Nonce ausgeführt' );
	},

	'test_02_fu002_ak05_masken_abbrechen_und_meldungen' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'form@example.org', 'tokform' );

		ob_start();
		vv_render_poll_form( $pid );
		$poll_form = (string) ob_get_clean();
		vv_check( str_contains( $poll_form, 'Abbrechen' ), '[FU002#AK05] Abstimmungsmaske bietet Abbrechen' );

		ob_start();
		vv_render_import_form( vv_fx_poll_row( $pid ) );
		$import_form = (string) ob_get_clean();
		ob_start();
		vv_render_invite_form( vv_fx_poll_row( $pid ) );
		$invite_form = (string) ob_get_clean();
		vv_check( str_contains( $import_form, 'Abbrechen' ), '[FU003#AK01] Importmaske bietet Abbrechen' );
		vv_check( str_contains( $invite_form, 'Abbrechen' ), '[FU004#AK01] Einladungsmaske bietet Abbrechen' );
		vv_check( str_contains( $invite_form, 'confirm(' ), '[FU004#AK01] Massenversand erst nach Bestätigung' );

		// Bestätigung nach dem Speichern (Redirect-Signal + sichtbare Meldung):
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => $pid, 'title' => 'Gespeichert', 'question' => 'Q',
			'start_dt' => date( 'Y-m-d\TH:00', strtotime( '-1 hour' ) ),
			'end_dt'   => date( 'Y-m-d\TH:00', strtotime( '+1 day' ) ), 'status' => 'draft',
		] );
		[ $kind, $url ] = vv_fx_run( 'vv_save_poll' );
		vv_check( 'redirect' === $kind && str_contains( (string) $url, 'saved=1' ), '[FU002#AK05] Redirect nach dem Speichern signalisiert Erfolg' );

		vv_fx_http( [ 'saved' => '1' ] );
		ob_start();
		vv_render_members_table( vv_fx_poll_row( $pid ) );
		$members = (string) ob_get_clean();
		vv_check( str_contains( $members, 'gespeichert' ), '[FU002#AK05] Bestätigung nach dem Speichern sichtbar' );

		// Bestätigung nach dem Löschen:
		vv_fx_http( [ 'deleted' => '1' ] );
		ob_start();
		vv_render_admin_page();
		$list = (string) ob_get_clean();
		vv_check( str_contains( $list, 'gelöscht' ), '[FU002#AK05] Bestätigung nach dem Löschen sichtbar' );
	},

	'test_02_fu002_ak01_zeitraum_und_titellaenge' => function () {
		global $wpdb;
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . vv_tbl( 'voting_polls' ) );
		$start  = date( 'Y-m-d\TH:00', strtotime( '+2 days' ) );
		$end    = date( 'Y-m-d\TH:00', strtotime( '+1 day' ) );

		// Ende vor Start wird abgewiesen – Meldung in der Maske, nichts gespeichert:
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => '', 'title' => 'Verdreht', 'question' => 'Q',
			'start_dt' => $start, 'end_dt' => $end, 'status' => 'draft',
		] );
		[ $kind, ] = vv_fx_run( 'vv_save_poll' );
		$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . vv_tbl( 'voting_polls' ) );
		vv_check( null === $kind && $before === $after, '[FU002#AK01] verdrehter Zeitraum wird nicht gespeichert' );

		// [FU002#AK05] Fehler und Eingaben bleiben in der Maske:
		ob_start();
		vv_render_poll_form( '' );
		$html = (string) ob_get_clean();
		vv_check( str_contains( $html, 'Ende muss nach dem Start liegen' ), '[FU002#AK05] Meldung erscheint in der Maske' );
		vv_check( str_contains( $html, 'value="Verdreht"' ), '[FU002#AK05] eingegebener Titel bleibt erhalten' );
		vv_check( str_contains( $html, 'value="' . $start . '"' ) && str_contains( $html, 'value="' . $end . '"' ), '[FU002#AK05] eingegebene Zeiten bleiben erhalten' );

		// Zu langer Titel wird auf 190 Zeichen gekürzt statt still zu scheitern:
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => '', 'title' => str_repeat( 'A', 250 ), 'question' => 'TITELTEST',
			'start_dt' => date( 'Y-m-d\TH:00', strtotime( '-1 hour' ) ),
			'end_dt'   => date( 'Y-m-d\TH:00', strtotime( '+1 day' ) ), 'status' => 'draft',
		] );
		vv_fx_run( 'vv_save_poll' );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vv_tbl( 'voting_polls' ) . ' WHERE question = %s', 'TITELTEST' ) );
		vv_check( $row && 190 === strlen( $row->title ), '[FU002#AK01] Titel wird auf 190 Zeichen gekürzt' );
	},

	'test_02_fu002_ak02_gesperrte_altwahl_mit_verdrehtem_zeitraum' => function () {
		// Altbestand (vor 1.1.1) kann Ende <= Start haben. Bei gesperrten Feldern
		// werden die Zeiten ignoriert – das Verabschieden darf nicht daran scheitern.
		$pid = vv_fx_poll( [ 'status' => 'active', 'start_off' => '+2 days', 'end_off' => '+1 day' ] );
		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => $pid, 'title' => 'Alt', 'question' => 'Q',
			'start_dt' => date( 'Y-m-d\TH:00', strtotime( '+2 days' ) ),
			'end_dt'   => date( 'Y-m-d\TH:00', strtotime( '+1 day' ) ), 'status' => 'closed',
		] );
		[ $kind, ] = vv_fx_run( 'vv_save_poll' );
		vv_check( 'redirect' === $kind && 'closed' === vv_fx_poll_row( $pid )->status, '[FU002#AK02] verdrehter Bestandszeitraum blockiert das Schließen nicht' );
	},

	'test_02_fu002_ak02_abschluss_speichert_fingerprint' => function () {
		global $wpdb;
		$pid = vv_fx_poll( [ 'status' => 'active' ] );
		$ha  = str_repeat( 'a', 63 ) . '1';
		$hb  = str_repeat( 'b', 63 ) . '2';
		vv_fx_ballot( $pid, 'ja', $ha );
		vv_fx_ballot( $pid, 'nein', $hb );

		vv_fx_http( [], [
			'vv_action' => 'save_poll', 'poll_id' => $pid, 'title' => 'Alt', 'question' => 'Q',
			'start_dt' => date( 'Y-m-d\TH:00', strtotime( '-1 hour' ) ),
			'end_dt'   => date( 'Y-m-d\TH:00', strtotime( '+1 hour' ) ), 'status' => 'closed',
		] );
		[ $kind, ] = vv_fx_run( 'vv_save_poll' );
		$row = vv_fx_poll_row( $pid );

		$hashes = [ min( $ha, $hb ), max( $ha, $hb ) ]; // Prüfcodes sortiert (Rezept aus FU002#AK02).
		$expected = hash( 'sha256', implode( '', $hashes ) . '|1,1,0' );
		vv_check( 'closed' === $row->status && hash_equals( $expected, (string) $row->urn_fingerprint ),
			'[FU002#AK02] Abschluss hält den Abgabefingerprint fest (dokumentiertes Rezept)' );
		vv_check( 64 === strlen( (string) $row->urn_fingerprint ) && ctype_xdigit( (string) $row->urn_fingerprint ),
			'[FU002#AK02] Fingerprint im sha256-Format (64 hex)' );
		vv_check( vv_urn_fingerprint( $pid ) === $row->urn_fingerprint,
			'[FU002#AK02] Nachberechnung stimmt mit dem gespeicherten Abschlusswert überein' );

		// Jede spätere Urnen-Änderung weicht ab.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . vv_tbl( 'voting_ballots' ) . ' SET vote_option = %s WHERE receipt_hash = %s', 'enthaltung', $ha ) );
		vv_check( vv_urn_fingerprint( $pid ) !== $row->urn_fingerprint,
			'[FU002#AK02] Urnenänderung nach dem Abschluss wird am Fingerprint erkannt' );

		ob_start();
		vv_render_members_table( vv_fx_poll_row( $pid ) );
		$html = (string) ob_get_clean();
		vv_check( str_contains( $html, 'abweichend' ),
			'[FU002#AK02] Backend-Verdict meldet die Abweichung' );
	},

	'test_02_fu002_ak08_teilnehmerdaten_loeschen_nur_bei_beendeter_wahl' => function () {
		global $wpdb;
		$pid  = vv_fx_poll( [ 'status' => 'closed' ] );
		vv_fx_ballot( $pid, 'ja' );
		$pid2 = vv_fx_poll( [ 'status' => 'active' ] );
		vv_fx_voter( $pid, '77', 'weg@example.org', 'tok7' );

		vv_fx_http( [], [ 'vv_action' => 'delete_voter_data', 'poll_id' => $pid2, 'vv_nonce' => wp_create_nonce( 'vv_admin' ) ] );
		[ $kind, ] = vv_fx_run( 'vv_handle_admin_actions' );
		vv_check( 'die' === $kind, '[FU002#AK08] Räumung nur bei beendeter Wahl' );

		vv_fx_http( []   , [ 'vv_action' => 'delete_voter_data', 'poll_id' => $pid, 'vv_nonce' => wp_create_nonce( 'vv_admin' ) ] );
		[ $kind, $url ] = vv_fx_run( 'vv_handle_admin_actions' );
		vv_check( 'redirect' === $kind && str_contains( (string) $url, 'voters_deleted=1' ), '[FU002#AK08] Räumung bei beendeter Wahl erlaubt' );
		vv_check( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = ' . $wpdb->prepare( '%s', $pid ) ),
			'[FU002#AK08] Wählerzeilen entfernt' );
		vv_check( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . vv_tbl( 'voting_ballots' ) . ' WHERE poll_id = ' . $wpdb->prepare( '%s', $pid ) ),
			'[FU002#AK08] Urne bleibt vollständig' );
		vv_check( 1 === vv_get_results( $pid )['ja']['count'],
			'[FU002#AK08] Ergebnis bleibt nach der Räumung unverändert' );
	},

	'test_02_fu002_ak02_beendete_maske_nur_zurueck' => function () {
		$pid = vv_fx_poll( [ 'status' => 'closed' ] );
		ob_start();
		vv_render_poll_form( $pid );
		$html = (string) ob_get_clean();
		vv_check( ! str_contains( $html, 'Speichern' ) && ! str_contains( $html, '"save_poll"' ) && ! str_contains( $html, '<form' ),
			'[FU002#AK02] beendete Wahl: Bearbeiten-Maske ohne Speichern-Aktion (kein POST-Formular)' );
		vv_check( str_contains( $html, '>Zurück</' ), '[FU002#AK02] beendete Wahl: Maske zeigt nur „Zurück“' );

		// Laufende Wahlen behalten Speichern/Abbrechen (z. B. vorzeitiges Beenden).
		$pid2 = vv_fx_poll( [ 'status' => 'active' ] );
		ob_start();
		vv_render_poll_form( $pid2 );
		$html2 = (string) ob_get_clean();
		vv_check( str_contains( $html2, 'Speichern' ) && str_contains( $html2, '<form' ),
			'[FU002#AK02] laufende Wahl: Maske behält die Aktionen' );
	},

	'test_02_fu002_ak08_beendete_teilnehmeransicht_ohne_optionen' => function () {
		$pid = vv_fx_poll( [ 'status' => 'closed' ] );
		vv_fx_http( [ 'page' => 'vereins-voting', 'view' => 'members', 'poll' => $pid ] );
		ob_start();
		vv_render_admin_page();
		$html = (string) ob_get_clean();
		vv_check( ! str_contains( $html, 'Teilnehmer (CSV-Import)' ) && ! str_contains( $html, 'Einladungen versenden' ),
			'[FU002#AK08] beendete Wahl: Teilnehmeransicht ohne CSV-Import/Einladungsoptionen' );
		vv_check( str_contains( $html, 'Gesamtergebnis' ) && str_contains( $html, '>Zurück</' ),
			'[FU002#AK08] Ergebnisblock und Zurück bleiben sichtbar' );
		vv_check( ! str_contains( $html, 'delete_voter_data' )
			&& str_contains( $html, 'Keine Teilnehmerdaten mehr vorhanden' ),
			'[FU002#AK08] ohne Teilnehmer: Meldung statt Lösch-Button' );

		// Mit Teilnehmern bleibt der Lösch-Button.
		$pid3 = vv_fx_poll( [ 'status' => 'closed' ] );
		vv_fx_voter( $pid3, '99', 'da@example.org' );
		vv_fx_http( [ 'page' => 'vereins-voting', 'view' => 'members', 'poll' => $pid3 ] );
		ob_start();
		vv_render_admin_page();
		$html3 = (string) ob_get_clean();
		vv_check( str_contains( $html3, 'delete_voter_data' ) && str_contains( $html3, 'Teilnehmerdaten löschen' ),
			'[FU002#AK08] mit Teilnehmern: Lösch-Button sichtbar' );

		// Laufende Wahlen behalten die Optionen.
		$pid2 = vv_fx_poll( [ 'status' => 'active' ] );
		vv_fx_http( [ 'page' => 'vereins-voting', 'view' => 'members', 'poll' => $pid2 ] );
		ob_start();
		vv_render_admin_page();
		$html2 = (string) ob_get_clean();
		vv_check( str_contains( $html2, 'Teilnehmer (CSV-Import)' ) && str_contains( $html2, 'Einladungen versenden' ),
			'[FU002#AK08] laufende Wahl: Optionen verbleiben' );
	},
];
