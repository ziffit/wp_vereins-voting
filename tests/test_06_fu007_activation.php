<?php
/**
 * [FU007] Aktivierung (PIN-Registrierung) über Shortcode.
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
		foreach ( [ 'Mitgliedsnummer', 'E-Mail', 'PIN', 'bestätigen' ] as $needle ) {
			vv_check( str_contains( $html, $needle ), "[FU007#AK01] Formularfeld $needle vorhanden" );
		}
		vv_check( str_contains( $html, 'vv-version' ) && str_contains( $html, 'vereins-voting ' ),
			'[FU007#AK05] Versionszeile am Ende jeder Shortcode-Ausgabe' );
	},

	'test_06_fu007_ak02_daten_muss_passen' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 'tokmm' );

		// falsche Mitgliedsnummer:
		vv_fx_http( [ 'poll' => $pid, 'token' => 'tokmm' ],
			[ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '999', 'email' => 'a@b.org', 'pin' => '123456', 'pin2' => '123456' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'nicht mit der Einladung' ), '[FU007#AK02] falsche Mitgliedsnummer abgelehnt' );

		// falsche E-Mail:
		vv_fx_http( [ 'poll' => $pid, 'token' => 'tokmm' ],
			[ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '101', 'email' => 'fremd@b.org', 'pin' => '123456', 'pin2' => '123456' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'nicht mit der Einladung' ), '[FU007#AK02] falsche E-Mail abgelehnt' );

		// Token bleibt unverbraucht:
		global $wpdb;
		$t = $wpdb->get_var( 'SELECT activation_token FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = ' . $wpdb->prepare( '%s', $pid ) );
		vv_check( 'tokmm' === $t, '[FU007#AK02] Token bei fehlgeschlagener Prüfung nicht verbraucht' );
	},

	'test_06_fu007_ak02_pin_wiederholung' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 'tokpin' );
		vv_fx_http( [ 'poll' => $pid, 'token' => 'tokpin' ],
			[ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '101', 'email' => 'a@b.org', 'pin' => '123456', 'pin2' => '654321' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, '6-stellig' ) && str_contains( $html, 'gleich' ),
			'[FU007#AK02] PIN-Wiederholung muss exakt übereinstimmen' );
	},

	'test_06_fu007_ak03_pin_hash_und_token_verbrauch' => function () {
		global $wpdb;
		$pid = vv_fx_poll();
		$tok = 'aa11bb22cc33dd44ee55ff66ff77aa88';
		vv_fx_voter( $pid, '101', 'a@b.org', $tok );

		vv_fx_http( [ 'poll' => $pid, 'token' => $tok ],
			[ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '101', 'email' => 'a@b.org', 'pin' => '123456', 'pin2' => '123456' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'Erfolgreich' ), '[FU007#AK03] Erfolgsmeldung angezeigt' );

		$row = $wpdb->get_row( 'SELECT * FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = ' . $wpdb->prepare( '%s', $pid ) );
		vv_check( $row->pin_hash && password_verify( '123456', $row->pin_hash ), '[FU007#AK03] PIN als password_hash gespeichert, verify ok' );
		vv_check( ! preg_match( '/123456$/', (string) $row->pin_hash ), '[FU007#AK03] kein Klartext-PIN in pin_hash' );
		vv_check( null === $row->activation_token, '[FU007#AK03] activation_token gelöscht (Einmalverwendung)' );
		vv_check( null !== $row->pin_set_at, '[FU007#AK03] pin_set_at hält den Zeitpunkt der PIN-Registrierung fest' );
		$info = password_get_info( (string) $row->pin_hash );
		vv_check( 'bcrypt' === $info['algoName'] && 12 === (int) $info['options']['cost'],
			'[FU008#AK05] PIN-Hash mit bcrypt cost 12 (Offline-Brute-Force bei 6 Ziffern dämpfen)' );

		// Erneuter Versuch mit gleichem Token:
		vv_fx_http( [ 'poll' => $pid, 'token' => $tok ],
			[ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '101', 'email' => 'a@b.org', 'pin' => '654321', 'pin2' => '654321' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'Ungültiger oder bereits benutzter' ), '[FU007#AK03] Zweitverwendung abgelehnt' );
		vv_check( '123456' !== password_verify( '654321', $row->pin_hash ), '[FU007#AK03] PIN bleibt unverändert' );
	},

	'test_06_fu007_ak04_csrf_nonce_pflicht' => function () {
		global $wpdb;
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 'tokcsrf' );

		// POST ohne Nonce:
		vv_fx_http( [ 'poll' => $pid, 'token' => 'tokcsrf' ],
			[ 'member_no' => '101', 'email' => 'a@b.org', 'pin' => '123456', 'pin2' => '123456' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'Sicherheitsprüfung' ), '[FU007#AK04] POST ohne Nonce abgelehnt' );

		$t = $wpdb->get_var( 'SELECT activation_token FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = ' . $wpdb->prepare( '%s', $pid ) );
		vv_check( 'tokcsrf' === $t, '[FU007#AK04] ohne Nonce wird kein PIN gesetzt/Token verbraucht' );
	},

	'test_06_fu007_ak04_rate_limit_aktivierung' => function () {
		global $wpdb;
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 'tokrl' );

		for ( $i = 0; $i < 5; $i++ ) {
			vv_fx_http( [ 'poll' => $pid, 'token' => 'tokrl' ],
				[ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '999', 'email' => 'a@b.org', 'pin' => '123456', 'pin2' => '123456' ] );
			vv_shortcode_activate();
		}

		// Sechster Versuch, jetzt mit korrekten Daten → gesperrt.
		vv_fx_http( [ 'poll' => $pid, 'token' => 'tokrl' ],
			[ 'vv_frontend_nonce' => vv_fx_nonce(), 'member_no' => '101', 'email' => 'a@b.org', 'pin' => '123456', 'pin2' => '123456' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'Zu viele Fehlversuche' ), '[FU007#AK04] Brute-Force-Bremse nach 5 Fehlversuchen aktiv' );

		$t = $wpdb->get_var( 'SELECT activation_token FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = ' . $wpdb->prepare( '%s', $pid ) );
		vv_check( 'tokrl' === $t, '[FU007#AK04] gesperrter Versuch verbraucht den Token nicht' );
	},
];
