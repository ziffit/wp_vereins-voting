<?php
/**
 * [FU003] CSV-Import & Token-Generierung.
 */
return [

	'test_03_fu003_ak01_grundimport' => function () {
		$pid = vv_fx_poll();
		// Spaltenreihenfolge frei, zusätzliche Spalte wird ignoriert, "Name" als Nachname.
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "E-Mail;Abteilung;Mitgliedsnummer;Vorname;Name\nein@example.org;Tennis;101;Erika;Muster\nzwo@example.org;Fußball;102;Max;Beispiel\n" ) );
		[ $kind ] = vv_fx_run( 'vv_import_csv' );
		vv_check( 'redirect' === $kind, '[FU003#AK01] Import schließt mit Redirect ab' );
		vv_check( 2 === vv_fx_voter_count( $pid ), '[FU003#AK01] beide Zeilen importiert' );
		$v = vv_fx_voter_row( 'ein@example.org' );
		vv_check( $v && '101' === $v->member_no, '[FU003#AK01] Mitgliedsnummer korrekt übernommen' );
		vv_check( $v && 'Erika' === $v->first_name && 'Muster' === $v->last_name,
			'[FU003#AK01] Vorname/Name über die Kopfzeile zugeordnet (Reihenfolge frei)' );
	},

	'test_03_fu003_ak01_nachname_alias_und_normalisierung' => function () {
		$pid = vv_fx_poll();
		// „Nachname“ als Alias, Komma-Trennzeichen, Großschreibung in der Kopfzeile.
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "Mitgliedsnummer,E-Mail,Vorname,Nachname\n7,a@x.org,Anna,Albern\n" ) );
		vv_fx_run( 'vv_import_csv' );
		$v = vv_fx_voter_row( 'a@x.org' );
		vv_check( $v && 'Albern' === $v->last_name, '[FU003#AK01] „Nachname“ ist gleichwertig zu „Name“' );
	},

	'test_03_fu003_ak01_kopfzeile_pflicht' => function () {
		$pid = vv_fx_poll();
		// Positionsbasierte Datei ohne Kopfzeile wird nicht mehr akzeptiert.
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "101;ein@example.org;Erika;Muster\n" ) );
		[ $kind, $msg ] = vv_fx_run( 'vv_import_csv' );
		vv_check( 'die' === $kind && str_contains( (string) $msg, 'Mitgliedsnummer' ) && str_contains( (string) $msg, 'E-Mail' ),
			'[FU003#AK01] fehlende Pflichtspalten werden mit Meldung abgewiesen' );
		vv_check( 0 === vv_fx_voter_count( $pid ), '[FU003#AK01] ohne gültige Kopfzeile wird nichts importiert' );
	},

	'test_03_fu003_ak01_fehlende_spalte_wird_gemeldet' => function () {
		$pid = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail;Name\n101;a@x.org;Albern\n" ) ); // Vorname fehlt
		[ $kind, $msg ] = vv_fx_run( 'vv_import_csv' );
		vv_check( 'die' === $kind && str_contains( (string) $msg, 'Vorname' ),
			'[FU003#AK01] fehlender Vorname wird gemeldet' );
	},

	'test_03_fu003_ak02_token_sicher_und_eindeutig' => function () {
		$pid = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail;Vorname;Name\n1;a@x.org;Anna;Albern\n2;b@x.org;Bea;Beispiel\n3;c@x.org;Cara;Claas\n" ) );
		vv_fx_run( 'vv_import_csv' );
		global $wpdb;
		$toks = array_map( fn( $r ) => $r->activation_token,
			$wpdb->get_results( 'SELECT activation_token FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = ' . $wpdb->prepare( '%s', $pid ) ) );
		vv_check( 3 === count( array_unique( $toks ) ), '[FU003#AK02] drei eindeutige Tokens' );
		$all_hex = (bool) ! array_filter( $toks, fn( $t ) => ! preg_match( '/^[0-9a-f]{32}$/', $t ) );
		vv_check( $all_hex, '[FU003#AK02] Tokens sind 32-Zeichen-Hex (kryptographisch zufällig)' );
	},

	'test_03_fu003_ak02_ungultige_email_verworfen' => function () {
		$pid = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail;Vorname;Name\n9;keine-mail;Karl;Keinmail\n8;hallo@ohne-tld;Olga;Ohnetld\n" ) );
		vv_fx_run( 'vv_import_csv' );
		vv_check( 0 === vv_fx_voter_count( $pid ), '[FU003#AK02] ungültige E-Mails werden verworfen' );
	},

	'test_03_fu003_ak02_duplikat_und_namen_nachtragen' => function () {
		$pid = vv_fx_poll();
		// Altdaten ohne Namen (z. B. vor 1.2.0 importiert):
		vv_fx_voter( $pid, '101', 'a@x.org', 'tok0' );

		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail;Vorname;Name\n101;A@x.org;Anna;Albern\n101;a@x.org;Anna;Albern\n101;a@X.ORG;Anna;Albern\n" ) );
		vv_fx_run( 'vv_import_csv' );
		vv_check( 1 === vv_fx_voter_count( $pid ), '[FU003#AK02] Duplikat (gleiche Mitgliedsnummer + E-Mail) verhindert, auch bei Groß-Klein' );

		// [FU003#AK02] Re-Import trägt leere Namensfelder nach …
		$v = vv_fx_voter_row( 'a@x.org' );
		vv_check( 'Anna' === $v->first_name && 'Albern' === $v->last_name,
			'[FU003#AK02] leere Namensfelder werden beim Re-Import nachgetragen' );
		vv_check( 'tok0' === $v->activation_token, '[FU003#AK02] Nachtragen verändert den Aktivierungstoken nicht' );

		// … überschreibt aber vorhandene Werte nicht.
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail;Vorname;Name\n101;a@x.org;Andrea;Anders\n" ) );
		vv_fx_run( 'vv_import_csv' );
		vv_check( 'Anna' === vv_fx_voter_row( 'a@x.org' )->first_name,
			'[FU003#AK02] vorhandene Namen werden nicht überschrieben' );

		// Gleiche E-Mail in einer ANDEREN Abstimmung bleibt erlaubt:
		$pid2 = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid2 ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail;Vorname;Name\n9;a@x.org;Bea;Beispiel\n" ) );
		vv_fx_run( 'vv_import_csv' );
		vv_check( 1 === vv_fx_voter_count( $pid2 ), '[FU003#AK02] Duplikat nur innerhalb der Abstimmung eingeschränkt, nicht global' );
	},

	'test_03_fu003_ak02_gleiche_email_fuer_mehrere_mitglieder' => function () {
		$pid = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail;Vorname;Name\n201;paar@example.org;Anna;Albern\n202;paar@example.org;Bernd;Beispiel\n" ) );
		vv_fx_run( 'vv_import_csv' );
		vv_check( 2 === vv_fx_voter_count( $pid ),
			'[FU003#AK02] gleiche E-Mail für verschiedene Mitgliedsnummern erlaubt (z. B. Ehepaare)' );

		// Gleiche Kombination wird weiterhin als Duplikat behandelt:
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail;Vorname;Name\n201;Paar@Example.org;Anna;Albern\n" ) );
		vv_fx_run( 'vv_import_csv' );
		vv_check( 2 === vv_fx_voter_count( $pid ), '[FU003#AK02] Mitgliedsnummer + E-Mail bleibt eindeutig' );
	},

	'test_03_fu003_ak03_zeilen_ohne_pflichtwerte_uebersprungen' => function () {
		$pid = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail;Vorname;Name\n1;ok@x.org;Olaf;Ok\n2;keine-mail;Karla;Kaputt\n3;ohne@x.org;;Ohnename\n\n" ) );
		[ $kind, $url ] = vv_fx_run( 'vv_import_csv' );
		vv_check( 'redirect' === $kind && str_contains( (string) $url, 'imported=1' ) && str_contains( (string) $url, 'skipped=2' ),
			'[FU003#AK03] Zeilen ohne Pflichtwerte werden übersprungen und gezählt (Leerzeile nicht)' );
		vv_check( 1 === vv_fx_voter_count( $pid ), '[FU003#AK03] nur die vollständige Zeile landet in der Liste' );
	},

	'test_03_fu003_ak03_namen_auf_100_zeichen_begrenzt' => function () {
		$pid  = vv_fx_poll();
		$long = str_repeat( 'N', 120 );
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail;Vorname;Name\n1;lang@x.org;$long;$long\n" ) );
		vv_fx_run( 'vv_import_csv' );
		$v = vv_fx_voter_row( 'lang@x.org' );
		vv_check( $v && 100 === mb_strlen( (string) $v->first_name ) && 100 === mb_strlen( (string) $v->last_name ),
			'[FU003#AK03] Namen werden auf die DB-Spaltenlänge (100) gekürzt' );
	},

	'test_03_fu003_ak01_delimiter_erkennung' => function () {
		$pid = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "Mitgliedsnummer,E-Mail,Vorname,Name\n1,comma@example.org,Cora,Komma\n2,second@example.org,Sam,Zweiter\n" ) );
		vv_fx_run( 'vv_import_csv' );
		vv_check( 2 === vv_fx_voter_count( $pid ), '[FU003#AK01] Komma-Trennzeichen funktioniert' );

		$pid2 = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid2 ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail;Vorname;Name\n3;semi@example.org;Klara;Semi\n4;semi2@example.org;Kurt;Semi\n" ) );
		vv_fx_run( 'vv_import_csv' );
		vv_check( 2 === vv_fx_voter_count( $pid2 ), '[FU003#AK01] Semikolon-Trennzeichen funktioniert' );
	},

	'test_03_fu003_ak02_import_nur_draft' => function () {
		$pid = vv_fx_poll( [ 'status' => 'active' ] );
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "Mitgliedsnummer;E-Mail;Vorname;Name\n1;x@y.org;Xenia;X\n" ) );
		[ $kind, $msg ] = vv_fx_run( 'vv_import_csv' );
		vv_check( 'die' === $kind && str_contains( (string) $msg, 'Entwurf' ), '[FU003#AK02] Import nur während draft erlaubt' );
	},

	'test_03_fu003_ak01_utf8_bom_wird_entfernt' => function () {
		// Excel schreibt UTF-8-CSVs mit BOM — der darf die Kopfzeilenzuordnung nicht stören.
		$pid = vv_fx_poll();
		vv_fx_http( [], [ 'vv_action' => 'import_csv', 'poll' => $pid ],
			vv_fx_csv_upload( "\xEF\xBB\xBFMitgliedsnummer;E-Mail;Vorname;Name\n42;bom@example.org;Berta;Bom\n" ) );
		vv_fx_run( 'vv_import_csv' );
		$v = vv_fx_voter_row( 'bom@example.org' );
		vv_check( $v && '42' === $v->member_no, '[FU003#AK01] UTF-8-BOM stört die Kopfzeilenzuordnung nicht' );
	},
];
