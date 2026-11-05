<?php
/**
 * [FU002] Teilnehmerverwaltung: Liste im Entwurf löschen, Handanlage bei
 * laufender Wahl; [FU005] E-Mail ändern und Einladung erneut senden.
 */
return [

	'test_09_fu002_ak09_teilnehmerliste_im_entwurf_loeschen' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@example.org' );
		vv_fx_voter( $pid, '102', 'b@example.org' );

		vv_fx_http( [], [ 'poll_id' => $pid ] );
		[ $kind, $url ] = vv_fx_run( 'vv_delete_voter_data' );

		vv_check( 'redirect' === $kind && str_contains( (string) $url, 'voters_deleted=1' ),
			'[FU002#AK09] Löschen im Entwurf schließt mit Bestätigung ab' );
		vv_check( 0 === vv_fx_voter_count( $pid ), '[FU002#AK09] komplette Teilnehmerliste entfernt' );
	},

	'test_09_fu002_ak09_loeschen_bei_laufender_wahl_verweigert' => function () {
		$pid = vv_fx_poll( [ 'status' => 'active' ] );
		vv_fx_voter( $pid, '101', 'a@example.org' );

		vv_fx_http( [], [ 'poll_id' => $pid ] );
		[ $kind, $msg ] = vv_fx_run( 'vv_delete_voter_data' );

		vv_check( 'die' === $kind && str_contains( (string) $msg, 'vor dem Start' ),
			'[FU002#AK09] laufende Wahl: Liste nicht löschbar' );
		vv_check( 1 === vv_fx_voter_count( $pid ), '[FU002#AK09] Teilnehmer bleibt erhalten' );
	},

	'test_09_fu002_ak10_teilnehmer_bei_laufender_wahl_anlegen' => function () {
		$pid = vv_fx_poll( [ 'status' => 'active' ] );

		vv_fx_http( [], [
			'poll'       => $pid,
			'member_no'  => '201',
			'email'      => 'geschaeftsstelle@example.org',
			'first_name' => 'Erika',
			'last_name'  => 'Muster',
		] );
		[ $kind ] = vv_fx_run( 'vv_add_voter' );

		vv_check( 'redirect' === $kind, '[FU002#AK10] Handanlage schließt mit Redirect ab' );
		$v = vv_fx_voter_row( 'geschaeftsstelle@example.org' );
		vv_check( $v && '201' === $v->member_no && 'Erika' === $v->first_name,
			'[FU002#AK10] Teilnehmer mit gemeinsamer E-Mail angelegt' );
		vv_check( $v && preg_match( '/^[0-9a-f]{32}$/', (string) $v->activation_token ),
			'[FU002#AK10] sicherer Aktivierungstoken erzeugt' );
	},

	'test_09_fu002_ak10_handanlage_validiert_und_verhindert_duplikate' => function () {
		$pid = vv_fx_poll( [ 'status' => 'active' ] );

		vv_fx_http( [], [ 'poll' => $pid, 'member_no' => '201', 'email' => 'keine-mail' ] );
		[ , $bad ] = vv_fx_run( 'vv_add_voter' );
		vv_check( str_contains( (string) $bad, 'voter_error=1' ), '[FU002#AK10] ungültige E-Mail wird abgewiesen' );
		vv_check( 0 === vv_fx_voter_count( $pid ), '[FU002#AK10] nichts gespeichert' );

		vv_fx_voter( $pid, '201', 'geschaeftsstelle@example.org' );
		vv_fx_http( [], [ 'poll' => $pid, 'member_no' => '201', 'email' => 'Geschaeftsstelle@example.org' ] );
		[ , $dupe ] = vv_fx_run( 'vv_add_voter' );
		vv_check( str_contains( (string) $dupe, 'voter_duplicate=1' ),
			'[FU002#AK10] Duplikat (case-insensitiv) wird abgewiesen' );
		vv_check( 1 === vv_fx_voter_count( $pid ), '[FU002#AK10] kein doppelter Eintrag' );
	},

	'test_09_fu002_ak10_handanlage_nur_bei_laufender_wahl' => function () {
		$pid = vv_fx_poll();

		vv_fx_http( [], [ 'poll' => $pid, 'member_no' => '201', 'email' => 'a@example.org' ] );
		[ $kind ] = vv_fx_run( 'vv_add_voter' );

		vv_check( 'die' === $kind, '[FU002#AK10] im Entwurf keine Handanlage (dort CSV-Import)' );
		vv_check( 0 === vv_fx_voter_count( $pid ), '[FU002#AK10] nichts gespeichert' );
	},

	'test_09_fu005_ak06_email_vor_stimmabgabe_aendern' => function () {
		$pid = vv_fx_poll( [ 'status' => 'active' ] );
		[ $vid ] = vv_fx_voter( $pid, '101', 'alt@example.org' );
		global $wpdb;
		$wpdb->update( vv_tbl( 'voting_voters' ),
			[ 'invited_at' => current_time( 'mysql' ), 'invite_attempts' => 3 ], [ 'id' => $vid ] );

		vv_fx_http( [], [ 'poll' => $pid, 'voter_id' => $vid, 'email' => 'neu@example.org' ] );
		[ $kind ] = vv_fx_run( 'vv_update_voter_email' );

		$v = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE id = %d', $vid ) );
		vv_check( 'redirect' === $kind, '[FU005#AK06] Änderung schließt mit Redirect ab' );
		vv_check( $v && 'neu@example.org' === $v->email, '[FU005#AK06] E-Mail-Adresse geändert' );
		vv_check( $v && null === $v->invited_at && 0 === (int) $v->invite_attempts,
			'[FU005#AK06] Einladung gilt wieder als offen' );
	},

	'test_09_fu005_ak06_email_nach_stimmabgabe_verweigert' => function () {
		$pid = vv_fx_poll( [ 'status' => 'active' ] );
		[ $vid ] = vv_fx_voter( $pid, '101', 'alt@example.org' );
		global $wpdb;
		$wpdb->update( vv_tbl( 'voting_voters' ), [ 'has_voted' => 1 ], [ 'id' => $vid ] );

		vv_fx_http( [], [ 'poll' => $pid, 'voter_id' => $vid, 'email' => 'neu@example.org' ] );
		[ $kind ] = vv_fx_run( 'vv_update_voter_email' );

		$v = $wpdb->get_row( $wpdb->prepare( 'SELECT email FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE id = %d', $vid ) );
		vv_check( 'die' === $kind && 'alt@example.org' === $v->email,
			'[FU005#AK06] nach der Stimmabgabe keine Änderung' );
	},

	'test_09_fu005_ak06_email_duplikat_wird_abgewiesen' => function () {
		$pid = vv_fx_poll( [ 'status' => 'active' ] );
		[ $vid ] = vv_fx_voter( $pid, '101', 'eins@example.org' );
		vv_fx_voter( $pid, '101', 'zwei@example.org' );

		vv_fx_http( [], [ 'poll' => $pid, 'voter_id' => $vid, 'email' => 'ZWEI@example.org' ] );
		[ , $url ] = vv_fx_run( 'vv_update_voter_email' );

		global $wpdb;
		$email = $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE id = %d', $vid ) );
		vv_check( str_contains( (string) $url, 'email_error=1' ) && 'eins@example.org' === $email,
			'[FU005#AK06] Duplikat (Mitgliedsnummer + E-Mail) verhindert die Änderung' );
	},

	'test_09_fu005_ak06_einladung_manuell_erneut_senden' => function () {
		$pid = vv_fx_poll( [ 'status' => 'active' ] );
		[ $vid ] = vv_fx_voter( $pid, '101', 'ein@example.org', 'aa11', null, 'Erika', 'Muster' );
		vv_fx_mail_settings();

		vv_fx_http( [], [ 'poll' => $pid, 'voter_id' => $vid ] );
		[ $kind, $url ] = vv_fx_run( 'vv_resend_invite' );

		global $wpdb;
		$v = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE id = %d', $vid ) );
		$mails = vv_fx_mails();
		vv_check( 'redirect' === $kind && str_contains( (string) $url, 'resent=1' ),
			'[FU005#AK06] Versand schließt mit Bestätigung ab' );
		vv_check( 1 === count( $mails ) && 'ein@example.org' === $mails[0]['to'],
			'[FU005#AK06] genau eine Einladung an die Person' );
		vv_check( $v && null !== $v->invited_at && 0 === (int) $v->invite_attempts,
			'[FU005#AK06] invited_at gesetzt, Fehlversuche zurückgesetzt' );
	},

	'test_09_fu005_ak06_kein_versand_ohne_token_oder_nach_stimmabgabe' => function () {
		$pid = vv_fx_poll( [ 'status' => 'active' ] );
		[ $vid1 ] = vv_fx_voter( $pid, '101', 'a@example.org', null ); // Token verbraucht
		[ $vid2 ] = vv_fx_voter( $pid, '102', 'b@example.org', 'bb22' );
		global $wpdb;
		$wpdb->update( vv_tbl( 'voting_voters' ), [ 'has_voted' => 1 ], [ 'id' => $vid2 ] );
		vv_fx_mail_settings();

		vv_fx_http( [], [ 'poll' => $pid, 'voter_id' => $vid1 ] );
		[ $kind1 ] = vv_fx_run( 'vv_resend_invite' );
		vv_fx_http( [], [ 'poll' => $pid, 'voter_id' => $vid2 ] );
		[ $kind2 ] = vv_fx_run( 'vv_resend_invite' );

		vv_check( 'die' === $kind1, '[FU005#AK06] ohne Aktivierungstoken kein Versand' );
		vv_check( 'die' === $kind2, '[FU005#AK06] nach der Stimmabgabe kein Versand' );
		vv_check( 0 === count( vv_fx_mails() ), '[FU005#AK06] keine Mail versendet' );
	},

	'test_09_fu005_ak01_suche_und_summenzeile' => function () {
		$pid = vv_fx_poll();
		[ $vid ] = vv_fx_voter( $pid, '101', 'a@example.org', 'aa11', 'ABCDEF', 'Erika', 'Muster' );
		vv_fx_voter( $pid, '102', 'b@example.org', 'bb22', null, 'Max', 'Beispiel' );
		global $wpdb;
		$wpdb->update( vv_tbl( 'voting_voters' ), [ 'has_voted' => 1 ], [ 'id' => $vid ] );

		vv_fx_http( [ 'page' => 'vereins-voting', 'view' => 'members', 'poll' => $pid, 'vv_q' => 'Erika' ] );
		ob_start();
		vv_render_admin_page();
		$html = (string) ob_get_clean();

		vv_check( str_contains( $html, '<td>101</td>' ) && ! str_contains( $html, '<td>102</td>' ),
			'[FU005#AK01] Suche nach Name filtert die Liste' );
		vv_check( str_contains( $html, '1 Treffer für „Erika“' ), '[FU005#AK01] Trefferzahl wird angezeigt' );
		vv_check( str_contains( $html, 'Mitglieder: <strong>2</strong> · Wahl-Code gesetzt: <strong>1</strong> · Hat gewählt: <strong>1</strong>' ),
			'[FU005#AK01] Summen gelten für die gesamte Abstimmung' );

		vv_fx_http( [ 'page' => 'vereins-voting', 'view' => 'members', 'poll' => $pid, 'vv_q' => '102' ] );
		ob_start();
		vv_render_admin_page();
		$html = (string) ob_get_clean();
		vv_check( str_contains( $html, '<td>102</td>' ) && ! str_contains( $html, '<td>101</td>' ),
			'[FU005#AK01] Suche nach Mitgliedsnummer filtert die Liste' );
	},
];
