<?php
/**
 * [FU003] CSV-Import & Token-Generierung.
 */
return [

	'test_03_fu003_ak01_grundimport' => function () {
		$pid = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail\n101;ein@example.org\n102;zwo@example.org\n" ) );
		[ $kind ] = vv_fx_run( 'vv_import_csv' );
		vv_check( 'redirect' === $kind, '[FU003#AK01] Import schließt mit Redirect ab' );
		vv_check( 2 === vv_fx_voter_count( $pid ), '[FU003#AK01] beide Zeilen importiert' );
		$v = vv_fx_voter_row( 'ein@example.org' );
		vv_check( $v && '101' === $v->member_no, '[FU003#AK01] Mitgliedsnummer korrekt übernommen' );
	},

	'test_03_fu003_ak02_token_sicher_und_eindeutig' => function () {
		$pid = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail\n1;a@x.org\n2;b@x.org\n3;c@x.org\n" ) );
		vv_fx_run( 'vv_import_csv' );
		global $wpdb;
		$toks = array_map( fn( $r ) => $r->activation_token,
			$wpdb->get_results( 'SELECT activation_token FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = ' . $pid ) );
		vv_check( 3 === count( array_unique( $toks ) ), '[FU003#AK02] drei eindeutige Tokens' );
		$all_hex = (bool) ! array_filter( $toks, fn( $t ) => ! preg_match( '/^[0-9a-f]{32}$/', $t ) );
		vv_check( $all_hex, '[FU003#AK02] Tokens sind 32-Zeichen-Hex (kryptographisch zufällig)' );
	},

	'test_03_fu003_ak02_ungultige_email_verworfen' => function () {
		$pid = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail\n9;keine-mail\n8;hallo@ohne-tld\n" ) );
		vv_fx_run( 'vv_import_csv' );
		vv_check( 0 === vv_fx_voter_count( $pid ), '[FU003#AK02] ungültige E-Mails werden verworfen' );
	},

	'test_03_fu003_ak02_duplikat_pro_abstimmung' => function () {
		$pid = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail\n101;A@x.org\n101;a@x.org\n102;a@x.org\n" ) );
		vv_fx_run( 'vv_import_csv' );
		vv_check( 1 === vv_fx_voter_count( $pid ), '[FU003#AK02] Duplikat je Abstimmung verhindert (auch bei Groß-Klein)' );
		// Gleiche E-Mail in einer ANDEREN Abstimmung bleibt erlaubt:
		$pid2 = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid2 ],
			vv_fx_csv_upload( "9;a@x.org\n" ) );
		vv_fx_run( 'vv_import_csv' );
		vv_check( 1 === vv_fx_voter_count( $pid2 ), '[FU003#AK02] Duplikat nur innerhalb der Abstimmung eingeschränkt, nicht global' );
	},

	'test_03_fu003_ak01_header_erkennung_alphanumerisch' => function () {
		// Mitgliedsnummern können alphanumerisch sein — nur die erste Zeile
		// mit nicht-E-Mail zweiter Spalte gilt als Kopfzeile.
		$pid = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "ABC;abc@example.org\nDEF;def@example.org\n" ) );
		vv_fx_run( 'vv_import_csv' );
		vv_check( 2 === vv_fx_voter_count( $pid ), '[FU003#AK01] CSV ohne Kopfzeile mit alphanumerischen Mitgliedsnummern wird vollständig importiert' );
	},

	'test_03_fu003_ak01_delimiter_erkennung' => function () {
		$pid = vv_fx_poll();
		// Komma ohne Header:
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "1,comma@example.org\n2,second@example.org\n" ) );
		vv_fx_run( 'vv_import_csv' );
		vv_check( 2 === vv_fx_voter_count( $pid ), '[FU003#AK01] Komma-Trennzeichen ohne Kopfzeile funktioniert' );

		// Semikolon mit Header:
		$pid2 = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid2 ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail\n3;semi@example.org\n4;semi2@example.org\n" ) );
		vv_fx_run( 'vv_import_csv' );
		vv_check( 2 === vv_fx_voter_count( $pid2 ), '[FU003#AK01] Semikolon-Trennzeichen mit Kopfzeile funktioniert' );
	},

	'test_03_fu003_ak02_import_nur_draft' => function () {
		$pid = vv_fx_poll( [ 'status' => 'active' ] );
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ], vv_fx_csv_upload( "1;x@y.org\n" ) );
		[ $kind, $msg ] = vv_fx_run( 'vv_import_csv' );
		vv_check( 'die' === $kind && str_contains( (string) $msg, 'Entwurf' ), '[FU003#AK02] Import nur während draft erlaubt' );
	},

	'test_03_fu003_ak01_utf8_bom_wird_entfernt' => function () {
		// Excel schreibt UTF-8-CSVs mit BOM — der darf nicht in der Mitgliedsnummer landen.
		$pid = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "\xEF\xBB\xBF42;bom@example.org\n" ) );
		vv_fx_run( 'vv_import_csv' );
		$v = vv_fx_voter_row( 'bom@example.org' );
		vv_check( $v && '42' === $v->member_no, '[FU003#AK01] UTF-8-BOM wird aus der ersten Spalte entfernt' );
	},
];
