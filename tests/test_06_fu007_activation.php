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
	},

	'test_06_fu007_ak02_daten_muss_passen' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 'tokmm' );

		// falsche Mitgliedsnummer:
		vv_fx_http( [ 'poll' => $pid, 'token' => 'tokmm' ],
			[ 'member_no' => '999', 'email' => 'a@b.org', 'pin' => '123456', 'pin2' => '123456' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'nicht mit der Einladung' ), '[FU007#AK02] falsche Mitgliedsnummer abgelehnt' );

		// falsche E-Mail:
		vv_fx_http( [ 'poll' => $pid, 'token' => 'tokmm' ],
			[ 'member_no' => '101', 'email' => 'fremd@b.org', 'pin' => '123456', 'pin2' => '123456' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'nicht mit der Einladung' ), '[FU007#AK02] falsche E-Mail abgelehnt' );

		// Token bleibt unverbraucht:
		global $wpdb;
		$t = $wpdb->get_var( 'SELECT activation_token FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = ' . $pid );
		vv_check( 'tokmm' === $t, '[FU007#AK02] Token bei fehlgeschlagener Prüfung nicht verbraucht' );
	},

	'test_06_fu007_ak02_pin_wiederholung' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'a@b.org', 'tokpin' );
		vv_fx_http( [ 'poll' => $pid, 'token' => 'tokpin' ],
			[ 'member_no' => '101', 'email' => 'a@b.org', 'pin' => '123456', 'pin2' => '654321' ] );
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
			[ 'member_no' => '101', 'email' => 'a@b.org', 'pin' => '123456', 'pin2' => '123456' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'Erfolgreich' ), '[FU007#AK03] Erfolgsmeldung angezeigt' );

		$row = $wpdb->get_row( 'SELECT * FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = ' . $pid );
		vv_check( $row->pin_hash && password_verify( '123456', $row->pin_hash ), '[FU007#AK03] PIN als password_hash gespeichert, verify ok' );
		vv_check( ! preg_match( '/123456$/', (string) $row->pin_hash ), '[FU007#AK03] kein Klartext-PIN in pin_hash' );
		vv_check( null === $row->activation_token, '[FU007#AK03] activation_token gelöscht (Einmalverwendung)' );

		// Erneuter Versuch mit gleichem Token:
		vv_fx_http( [ 'poll' => $pid, 'token' => $tok ],
			[ 'member_no' => '101', 'email' => 'a@b.org', 'pin' => '654321', 'pin2' => '654321' ] );
		$html = vv_shortcode_activate();
		vv_check( str_contains( $html, 'Ungültiger oder bereits benutzter' ), '[FU007#AK03] Zweitverwendung abgelehnt' );
		vv_check( '123456' !== password_verify( '654321', $row->pin_hash ), '[FU007#AK03] PIN bleibt unverändert' );
	},
];
