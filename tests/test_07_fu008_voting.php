<?php
/**
 * [FU008] Stimmabgabe — Kern in vv_cast_vote (Transaktion + Guards).
 */
return [

	'test_07_fu008_ak01_unbekannte_option' => function () {
		$pid = vv_fx_poll();
		try {
			vv_cast_vote( $pid, '101', 'a@b.org', '123456', 'vielleicht' );
			vv_check( false, 'unbekannte Option erfolgreich ?!' );
		} catch ( RuntimeException $e ) {
			vv_check( str_contains( $e->getMessage(), 'Bitte wähle' ), '[FU008#AK01] nur ja/nein/enthaltung erlaubt' );
		}
	},

	'test_07_fu008_ak01_unbekannte_abstimmung' => function () {
		try {
			vv_cast_vote( '00000000-0000-4000-8000-000000000000', '101', 'a@b.org', '123456', 'ja' );
			vv_check( false, 'unbekannte Abstimmung erfolgreich ?!' );
		} catch ( RuntimeException $e ) {
			vv_check( str_contains( $e->getMessage(), 'nicht gefunden' ), '[FU008#AK01] unbekannte Abstimmung abgelehnt' );
		}
	},

	'test_07_fu008_ak02_zeitraum_zukunft' => function () {
		global $wpdb;
		$pid = vv_fx_poll( [ 'start_off' => '+1 day', 'end_off' => '+2 days', 'status' => 'draft' ] );
		vv_fx_voter( $pid, '101', 'a@b.org', 't1', '123456' );
		try {
			vv_cast_vote( $pid, '101', 'a@b.org', '123456', 'ja' );
			vv_check( false, 'abstimmen vor Start ?!' );
		} catch ( RuntimeException $e ) {
			vv_check( str_contains( $e->getMessage(), 'nicht aktiv' ), '[FU008#AK02] Abstimmen nur innerhalb des Zeitraums' );
		}
		vv_check( 'draft' === vv_fx_poll_row( $pid )->status, '[FU008#AK02] Abstimmung bleibt draft außerhalb des Zeitraums' );
		vv_check( 0 === vv_fx_ballots_count( $pid ), '[FU008#AK02] Urne bleibt leer bei abgelehnter Stimme' );
	},

	'test_07_fu008_ak02_zeitraum_nach_ende' => function () {
		$pid = vv_fx_poll( [ 'start_off' => '-2 days', 'end_off' => '-1 day', 'status' => 'active' ] );
		vv_fx_voter( $pid, '101', 'a@b.org', 't1', '123456' );
		try {
			vv_cast_vote( $pid, '101', 'a@b.org', '123456', 'ja' );
			vv_check( false, 'abstimmen nach Ende ?!' );
		} catch ( RuntimeException $e ) {
			vv_check( str_contains( $e->getMessage(), 'nicht aktiv' ), '[FU008#AK02] Wahl nach end_dt abgelehnt, auch bei Status active' );
		}
		vv_check( 0 === vv_fx_ballots_count( $pid ), '[FU008#AK02] keine Urne nach end_dt' );
	},

	'test_07_fu008_ak02_zeitraum_autoaktivierung' => function () {
		$pid = vv_fx_poll( [ 'start_off' => '-1 hour', 'end_off' => '+1 day', 'status' => 'draft' ] );
		vv_fx_voter( $pid, '101', 'a@b.org', 't1', '123456' );
		$receipt = vv_cast_vote( $pid, '101', 'a@b.org', '123456', 'ja' );
		vv_check( 'active' === vv_fx_poll_row( $pid )->status, '[FU008#AK02] draft wird im Zeitraum automatisch aktiviert' );
		vv_check( (bool) preg_match( '/^[0-9a-f]{64}$/', $receipt ), '[FU008#AK03] sha256 Receipt erzeugt (64 hex)' );
	},

	'test_07_fu008_ak02_falscher_pin' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 't1', '123456' );
		try {
			vv_cast_vote( $pid, '101', 'a@b.org', '999999', 'ja' );
			vv_check( false, 'falsche PIN erfolgreich ?!' );
		} catch ( RuntimeException $e ) {
			vv_check( str_contains( $e->getMessage(), 'PIN' ), '[FU008#AK02] falscher PIN abgelehnt (password_verify)' );
		}
		vv_check( 0 === vv_fx_ballots_count( $pid ), '[FU008#AK02] keine Urne bei falscher PIN' );
	},

	'test_07_fu008_ak02_email_mitgliedsnr_zusammen' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 't1', '123456' );
		try {
			vv_cast_vote( $pid, '101', 'fremd@b.org', '123456', 'ja' );
			vv_check( false, 'fremde E-Mail erfolgreich ?!' );
		} catch ( RuntimeException $e ) {
			vv_check( str_contains( $e->getMessage(), 'zusammen' ), '[FU008#AK02] Mitgliedsnummer + E-Mail müssen zusammenpassen' );
		}
	},

	'test_07_fu008_ak03_transaktion_commit' => function () {
		global $wpdb;
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 't1', '123456' );

		$receipt = vv_cast_vote( $pid, '101', 'a@b.org', '123456', 'ja' );
		vv_check( (bool) preg_match( '/^[0-9a-f]{64}$/', $receipt ), '[FU008#AK03] Receipt = sha256-64hex' );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = %s', $pid ) );
		vv_check( 1 === (int) $row->has_voted, '[FU008#AK03] has_voted = 1 (committed)' );
		vv_check( null !== $row->voted_at, '[FU008#AK03] voted_at gesetzt (committed)' );

		$ballot = $wpdb->get_row( 'SELECT * FROM ' . vv_tbl( 'voting_ballots' ) . ' WHERE receipt_hash = ' . $wpdb->prepare( '%s', $receipt ) );
		vv_check( $ballot && 'ja' === $ballot->vote_option && $ballot->poll_id === $pid,
			'[FU008#AK03] Urnen-Zeile: nur poll_id, vote_option, receipt_hash' );
		// Anonymität: keine Mitgliedsnummer/E-Mail in der Urne.
		vv_check( ! property_exists( $ballot, 'member_no' ) && ! property_exists( $ballot, 'email' ),
			'[FU008#AK03] Urne enthält keine Mitgliederkategorien' );
		// Kein Zeitstempel, keine laufende Nummer: voted_at/„Gewählt am“ und
		// Urnenzeile sind weder über die Zeit noch über die Reihenfolge verknüpfbar.
		vv_check( ! property_exists( $ballot, 'created_at' ) && ! property_exists( $ballot, 'id' ),
			'[FU008#AK03] Urne ohne Zeitstempel und ohne laufende Nummer' );
	},

	'test_07_fu008_ak02_doppelvoting' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 't1', '123456' );
		vv_cast_vote( $pid, '101', 'a@b.org', '123456', 'ja' );
		try {
			vv_cast_vote( $pid, '101', 'a@b.org', '123456', 'nein' );
			vv_check( false, 'Doppelvoting erfolgreich ?!' );
		} catch ( RuntimeException $e ) {
			vv_check( str_contains( $e->getMessage(), 'bereits abgestimmt' ), '[FU008#AK02] Doppelvoting verhindert (has_voted)' );
		}
		vv_check( 1 === vv_fx_ballots_count( $pid ), '[FU008#AK02] Urne unverändert nach abgelehntem Versuch (nur 1 Stimme)' );
	},

	'test_07_fu008_ak04_formular_erfolgsanzeige' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 't1', '123456' );
		vv_fx_http( [ 'poll' => $pid ], [ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '101', 'email' => 'a@b.org', 'pin' => '123456', 'option' => 'nein' ] );
		$html = vv_shortcode_form();
		vv_check( str_contains( $html, 'vv-receipt' ) && str_contains( $html, 'Stimme wurde gezählt' ),
			'[FU008#AK04] Shortcode zeigt Receipt-Hash nach Erfolg im Browser' );
		vv_check( str_contains( $html, 'ersten 5 Stellen' ),
			'[FU008#AK04] Hinweis: ersten 5 Stellen des Prüfcodes notieren' );
		vv_check( str_contains( $html, 'vv-version' ), '[FU007#AK05] Versionszeile auch bei der Stimmabgabe' );
		vv_check( ! str_contains( $html, '<form' ), '[FU008#AK04] Formular nach Abstimmung ausgeblendet' );
		vv_check( 1 === vv_fx_ballots_count( $pid ), '[FU008#AK04] Urne erhielt genau eine Stimme' );

		// Fehlerfall zeigt Formular wieder + Nachbericht:
		$pid2 = vv_fx_poll();
		vv_fx_voter( $pid2, '202', 'c@d.org', 't2', '654321' );
		vv_fx_http( [ 'poll' => $pid2 ], [ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '202', 'email' => 'c@d.org', 'pin' => '000000', 'option' => 'ja' ] );
		$html2 = vv_shortcode_form();
		vv_check( str_contains( $html2, '<form' ) && str_contains( $html2, 'PIN ist falsch' ),
			'[FU008#AK04] Fehler: Formular bleibt mit Fehlermeldung' );
	},

	'test_07_fu008_ak02_datetime_t_format' => function () {
		global $wpdb;
		// Poll mit T-Format-Datum (wie vom Admin-Formular gespeichert) — muss trotzdem funktionieren.
		$pid = vv_fx_poll( [ 'start_off' => '-1 hour', 'end_off' => '+1 day', 'status' => 'draft' ] );
		$wpdb->query( $wpdb->prepare(
			"UPDATE " . vv_tbl( 'voting_polls' ) . " SET start_dt = %s, end_dt = %s WHERE id = %s",
			date( 'Y-m-d\\TH:i', strtotime( '-1 hour' ) ),
			date( 'Y-m-d\\TH:i', strtotime( '+1 day' ) ),
			$pid
		) );
		vv_fx_voter( $pid, '101', 'a@b.org', 't1', '123456' );
		$receipt = vv_cast_vote( $pid, '101', 'a@b.org', '123456', 'ja' );
		vv_check( (bool) preg_match( '/^[0-9a-f]{64}$/', $receipt ), '[FU008#AK02] Abstimmung funktioniert auch mit T-Format-Datum' );
	},

	'test_07_fu008_ak05_csrf_nonce_pflicht' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 't1', '123456' );

		// POST ohne Nonce darf keine Stimme zählen.
		vv_fx_http( [ 'poll' => $pid ], [ 'member_no' => '101', 'email' => 'a@b.org', 'pin' => '123456', 'option' => 'ja' ] );
		$html = vv_shortcode_form();
		vv_check( str_contains( $html, 'Sicherheitsprüfung' ), '[FU008#AK05] POST ohne Nonce abgelehnt' );
		vv_check( 0 === vv_fx_ballots_count( $pid ), '[FU008#AK05] ohne Nonce landet keine Stimme in der Urne' );
		vv_check( 0 === (int) vv_fx_voter_row( 'a@b.org' )->has_voted, '[FU008#AK05] has_voted bleibt 0' );
	},

	'test_07_fu008_ak05_rate_limit_voting' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 't1', '123456' );

		for ( $i = 0; $i < 5; $i++ ) {
			try {
				vv_cast_vote( $pid, '101', 'a@b.org', '999999', 'ja' );
			} catch ( RuntimeException $e ) {
				// erwartet: Wahl-PIN ist falsch
			}
		}

		try {
			vv_cast_vote( $pid, '101', 'a@b.org', '123456', 'ja' );
			vv_check( false, 'sechste Stimme trotz Sperre durchgelassen ?!' );
		} catch ( RuntimeException $e ) {
			vv_check( str_contains( $e->getMessage(), 'Zu viele Fehlversuche' ), '[FU008#AK05] Brute-Force-Bremse nach 5 Fehlversuchen aktiv' );
		}
		vv_check( 0 === vv_fx_ballots_count( $pid ), '[FU008#AK05] gesperrte Stimme landet nicht in der Urne' );
	},
];
