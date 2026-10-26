<?php
/**
 * [FU007] Aktivierung (Wahl-Code erzeugen) über Shortcode.
 */
return [

	'test_06_fu007_ak01_ungultiger_token' => function () {
		vv_fx_http( [ 'poll' => 1, 'token' => 'deadbeefdeadbeef' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'Ungültiger oder bereits benutzter Aktivierungslink' ),
			'[FU007#AK01] unbekannter Token abgelehnt' );

		vv_fx_http( [] ); // kein poll/token in URL:
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'Ungültiger oder bereits benutzter Aktivierungslink' ),
			'[FU007#AK01] fehlender poll/token abgelehnt' );
	},

	'test_06_fu007_ak01_formular_felder' => function () {
		$pid = vv_fx_poll();
		[ $vid, $tok ] = vv_fx_voter( $pid, '101', 'a@b.org', 'tokform' );
		vv_fx_http( [ 'poll' => $pid, 'token' => 'tokform' ] );
		$html = vv_shortcode_activate();
		foreach ( [ 'Mitgliedsnummer', 'E-Mail', 'Neuen Code erzeugen', 'Diesen Code übernehmen' ] as $needle ) {
			vv_check( str_contains( $html, $needle ), "[FU007#AK01] Formularbestandteil $needle vorhanden" );
		}
		// Der generierte Code erscheint schreibgeschützt und im erzwungenen Format:
		vv_check( (bool) preg_match( '/<code class="vv-receipt">([A-HJ-NP-Z2-9]{6})<\/code>/', $html, $m ),
			'[FU007#AK01] Wahl-Code sichtbar, formatkonform (6 Zeichen, mind. 1 Buchstabe + 1 Ziffer)' );
		vv_check( str_contains( $html, (string) $m[1] ?? '' ), '[FU007#AK01] angezeigter Code auch im Formular übernommen' );
		vv_check( str_contains( $html, 'vv-version' ) && str_contains( $html, 'vereins-voting ' ),
			'[FU007#AK05] Versionszeile am Ende jeder Shortcode-Ausgabe' );
	},

	'test_06_fu007_ak02_daten_muss_passen' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 'tokmm' );

		// falsche Mitgliedsnummer:
		vv_fx_http( [ 'poll' => $pid, 'token' => 'tokmm' ],
			[ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '999', 'email' => 'a@b.org', 'pin' => 'A2B3C4' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'nicht mit der Einladung' ), '[FU007#AK02] falsche Mitgliedsnummer abgelehnt' );

		// falsche E-Mail:
		vv_fx_http( [ 'poll' => $pid, 'token' => 'tokmm' ],
			[ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '101', 'email' => 'fremd@b.org', 'pin' => 'A2B3C4' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'nicht mit der Einladung' ), '[FU007#AK02] falsche E-Mail abgelehnt' );

		// Token bleibt unverbraucht:
		global $wpdb;
		$t = $wpdb->get_var( 'SELECT activation_token FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = ' . $wpdb->prepare( '%s', $pid ) );
		vv_check( 'tokmm' === $t, '[FU007#AK02] Token bei fehlgeschlagener Prüfung nicht verbraucht' );
	},

	'test_06_fu007_ak02_ungueltiger_code' => function () {
		global $wpdb;
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 'tokpin' );
		// Nur Ziffern (kein Buchstabe) → Formatabweisung, genau wie ein
		// manipuliertes Formular-POST es versuchen könnte.
		vv_fx_http( [ 'poll' => $pid, 'token' => 'tokpin' ],
			[ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '101', 'email' => 'a@b.org', 'pin' => '123456' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'nicht gültig' ),
			'[FU007#AK02] ungültiges Code-Format abgewiesen (Server-Validierung bleibt Vertrauensgrenze)' );

		$t = $wpdb->get_var( 'SELECT activation_token FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = ' . $wpdb->prepare( '%s', $pid ) );
		vv_check( 'tokpin' === $t, '[FU007#AK02] ungültiger Code verbraucht den Token nicht' );
	},

	'test_06_fu007_ak03_code_hash_und_token_verbrauch' => function () {
		global $wpdb;
		$pid = vv_fx_poll();
		$tok = 'aa11bb22cc33dd44ee55ff66ff77aa88';
		vv_fx_voter( $pid, '101', 'a@b.org', $tok );

		// Kleinbuchstaben sollen genauso gelten (Groß-/Kleinschreibung egal):
		vv_fx_http( [ 'poll' => $pid, 'token' => $tok ],
			[ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '101', 'email' => 'a@b.org', 'pin' => 'a2b3c4' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'Erfolgreich' ), '[FU007#AK03] Erfolgsmeldung angezeigt' );
		vv_check( str_contains( $html, 'A2B3C4' ), '[FU007#AK03] Code wird auf der Erfolgsseite noch einmal angezeigt' );

		$row = $wpdb->get_row( 'SELECT * FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = ' . $wpdb->prepare( '%s', $pid ) );
		vv_check( $row->pin_hash && password_verify( 'A2B3C4', $row->pin_hash ), '[FU007#AK03] Wahl-Code als password_hash gespeichert, verify ok' );
		vv_check( ! preg_match( '/A2B3C4$/', (string) $row->pin_hash ), '[FU007#AK03] kein Klartext-Code in pin_hash' );
		vv_check( null === $row->activation_token, '[FU007#AK03] activation_token gelöscht (Einmalverwendung)' );
		vv_check( null !== $row->pin_set_at, '[FU007#AK03] pin_set_at hält den Zeitpunkt der Aktivierung fest' );
		$info = password_get_info( (string) $row->pin_hash );
		vv_check( 'bcrypt' === $info['algoName'] && 12 === (int) $info['options']['cost'],
			'[FU008#AK05] Code-Hash mit bcrypt cost 12 (Offline-Brute-Force bei 6 Zeichen dämpfen)' );

		// Erneuter Versuch mit gleichem Token:
		vv_fx_http( [ 'poll' => $pid, 'token' => $tok ],
			[ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '101', 'email' => 'a@b.org', 'pin' => 'B2C3D4' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'Ungültiger oder bereits benutzter' ), '[FU007#AK03] Zweitverwendung abgelehnt' );
	},

	'test_06_fu007_ak04_csrf_nonce_pflicht' => function () {
		global $wpdb;
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 'tokcsrf' );

		// POST ohne Nonce:
		vv_fx_http( [ 'poll' => $pid, 'token' => 'tokcsrf' ],
			[ 'member_no' => '101', 'email' => 'a@b.org', 'pin' => 'A2B3C4' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'Sicherheitsprüfung' ), '[FU007#AK04] POST ohne Nonce abgelehnt' );

		$t = $wpdb->get_var( 'SELECT activation_token FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = ' . $wpdb->prepare( '%s', $pid ) );
		vv_check( 'tokcsrf' === $t, '[FU007#AK04] ohne Nonce wird kein Wahl-Code gesetzt/Token verbraucht' );
	},

	'test_06_fu007_ak04_neuer_code_zaehlt_nicht' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 'toknew' );

		// 4 Fehlversuche (falsche Mitgliedsnummer):
		for ( $i = 0; $i < 4; $i++ ) {
			vv_fx_http( [ 'poll' => $pid, 'token' => 'toknew' ],
				[ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '999', 'email' => 'a@b.org', 'pin' => 'A2B3C4' ] );
			vv_shortcode_activate();
		}

		// Mehrfaches „Neuen Code erzeugen“ (GET) darf die Sperre nicht erhöhen
		// und erwirtschaftet jedes Mal einen frischen Code:
		$codes = [];
		for ( $i = 0; $i < 3; $i++ ) {
			vv_fx_http( [ 'poll' => $pid, 'token' => 'toknew' ] );
			$html = vv_shortcode_activate();
			vv_check( (bool) preg_match( '/<code class="vv-receipt">([A-HJ-NP-Z2-9]{6})<\/code>/', $html, $m ),
				'[FU007#AK01] „Neuen Code erzeugen“ liefert weiterhin einen gültigen Code' );
			$codes[] = $m[1];
		}
		vv_check( count( array_unique( $codes ) ) >= 2 && count( $codes ) === 3,
			'[FU007#AK01] „Neuen Code erzeugen“ erzeugt bei erneutem Aufruf neue Codes' );

		// Der 5. Fehlversuch (nicht der 7.) darf noch nicht gesperrt sein —
		// beweist, dass die GET-Reloads nicht als Fehlversuche zählen.
		vv_fx_http( [ 'poll' => $pid, 'token' => 'toknew' ],
			[ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '999', 'email' => 'a@b.org', 'pin' => 'A2B3C4' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'nicht mit der Einladung' ) && ! str_contains( $html, 'Zu viele Fehlversuche' ),
			'[FU007#AK04] GET-Neuladen zählt nicht als Fehlversuch' );
	},

	'test_06_fu007_ak04_rate_limit_aktivierung' => function () {
		global $wpdb;
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 'tokrl' );

		for ( $i = 0; $i < 5; $i++ ) {
			vv_fx_http( [ 'poll' => $pid, 'token' => 'tokrl' ],
				[ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '999', 'email' => 'a@b.org', 'pin' => 'A2B3C4' ] );
			vv_shortcode_activate();
		}

		// Sechster Versuch, jetzt mit korrekten Daten → gesperrt.
		vv_fx_http( [ 'poll' => $pid, 'token' => 'tokrl' ],
			[ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '101', 'email' => 'a@b.org', 'pin' => 'A2B3C4' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'Zu viele Fehlversuche' ), '[FU007#AK04] Brute-Force-Bremse nach 5 Fehlversuchen aktiv' );

		$t = $wpdb->get_var( 'SELECT activation_token FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = ' . $wpdb->prepare( '%s', $pid ) );
		vv_check( 'tokrl' === $t, '[FU007#AK04] gesperrter Versuch verbraucht den Token nicht' );
	},
];
