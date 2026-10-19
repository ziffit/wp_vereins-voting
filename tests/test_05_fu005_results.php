<?php
/**
 * [FU005] Admin-Beteiligungsübersicht + Aggregierte Ergebnisse (Datenschutz).
 */
return [

	'test_05_fu005_ak03_aggregierte_zahl' => function () {
		$pid = vv_fx_poll();
		vv_fx_ballot( $pid, 'ja' );
		vv_fx_ballot( $pid, 'ja' );
		vv_fx_ballot( $pid, 'nein' );
		vv_fx_ballot( $pid, 'enthaltung' );

		$r = vv_get_results( $pid );
		vv_check( 2 === $r['ja']['count'], '[FU005#AK03] 2×Ja gezählt' );
		vv_check( 1 === $r['nein']['count'], '[FU005#AK03] 1×Nein gezählt' );
		vv_check( 1 === $r['enthaltung']['count'], '[FU005#AK03] 1×Enthaltung gezählt' );
		vv_check( abs( $r['ja']['percent'] - 50.0 ) < 0.01, '[FU005#AK03] Anteil Ja = 50%' );
		vv_check( abs( $r['nein']['percent'] - 25.0 ) < 0.01, '[FU005#AK03] Anteil Nein = 25%' );
		vv_check( abs( $r['enthaltung']['percent'] - 25.0 ) < 0.01, '[FU005#AK03] Anteil Enthaltung = 25%' );
	},

	'test_05_fu005_ak03_leere_urne_kein_teiler_0' => function () {
		$pid = vv_fx_poll();
		$r = vv_get_results( $pid );
		vv_check( 0 === $r['ja']['count'] && 0 === $r['nein']['count'] && 0 === $r['enthaltung']['count'],
			'[FU005#AK03] leere Urne: keine geteilten Stimmen (kein division by zero)' );
		vv_check( 0 === (int) $r['ja']['percent'] && 0 === (int) $r['nein']['percent'] && 0 === (int) $r['enthaltung']['percent'],
			'[FU005#AK03] leere Urne: Anteile 0 %' );
	},

	'test_05_fu005_ak03_unbekannte_option_gezählt' => function () {
		$pid = vv_fx_poll();
		vv_fx_ballot( $pid, 'sonstwas' );
		vv_fx_ballot( $pid, 'ja' );
		$r = vv_get_results( $pid );
		vv_check( 1 === $r['ja']['count'], '[FU005#AK03] fach correct gezählt ist ja=1' );
		vv_check( 0 === $r['nein']['count'] && 0 === $r['enthaltung']['count'],
			'[FU005#AK03] unbekannte Stimme stört aggregierte Zählung nicht' );
	},

	'test_05_fu005_ak02_backend_zeigt_keine_stimminhalte' => function () {
		global $wpdb;
		$pid     = vv_fx_poll( [ 'status' => 'closed' ] );
		$member  = vv_fx_voter( $pid, '55', 'ein@beispiel.org', 'tokx' );
		vv_fx_pin( $member[0], '123456' );
		$wpdb->query( "UPDATE " . vv_tbl( 'voting_voters' ) . " SET has_voted = 1, voted_at = NOW() WHERE id = " . (int) $member[0] );
		vv_fx_ballot( $pid, 'ja' );  // diese Person hat für JA gestimmt (Backend darf das NICHT anzeigen können).

		ob_start();
		vv_render_members_table( vv_fx_poll_row( $pid ) );
		$html = (string) ob_get_clean();

		// [FU005#AK01] Status-Tabelle: Hat gewählt + Zeitstempel vorhanden.
		vv_check( str_contains( $html, 'Hat gewählt' ), '[FU005#AK01] Spalte "Hat gewählt" vorhanden' );
		vv_check( str_contains( $html, 'Gewählt am' ), '[FU005#AK01] Spalte "Gewählt am" vorhanden' );
		vv_check( str_contains( $html, 'ein@beispiel.org' ), '[FU005#AK01] Mitglieder in Tabelle' );

		// [FU005#AK02] Urnen-Spalten (Werte klein) dürfen nie personenbezogen sichtbar sein.
		vv_check( ! str_contains( $html, 'ja' ), '[FU005#AK02] kein Urnen-Optionswert "ja" (kleingeschrieben) im Backend sichtbar' );
		vv_check( ! str_contains( $html, 'enthaltung' ), '[FU005#AK02] kein Urnen-Optionswort "enthaltung" (kleingeschrieben) im Backend sichtbar' );

		// [FU005#AK03] Aggregierte Zahl im Backend: nur Zählersummen, keine Personen-Verknüpfung.
		vv_check( str_contains( $html, 'Gesamtergebnis' ), '[FU005#AK03] aggregiertes Ergebnis sichtbar nach Wahlende' );
		vv_check( str_contains( $html, 'Enthaltung' ), '[FU005#AK03] Aggregatzeile für Enthaltung angezeigt' );
	},

	'test_05_fu005_ak01_uebersicht_zaehler' => function () {
		$pid = vv_fx_poll( [ 'title' => 'Zählwahl' ] );
		vv_fx_voter( $pid, '1', 'x@example.org', 'tt1' );
		vv_fx_voter( $pid, '2', 'y@example.org', 'tt2' );
		vv_fx_ballot( $pid, 'ja' );

		vv_fx_http( [] );
		ob_start();
		vv_render_admin_page();
		$html = (string) ob_get_clean();

		vv_check( str_contains( $html, 'Zählwahl' ), '[FU005#AK01] Übersicht listet die Abstimmung' );
		vv_check( str_contains( $html, '<td>2</td>' ) && str_contains( $html, '<td>1</td>' ),
			'[FU005#AK01] Übersicht zeigt Teilnehmer- und Stimmenzahl (eine Abfrage statt N+1)' );
	},

	'test_05_fu005_ak01_paginierung' => function () {
		$pid = vv_fx_poll();
		for ( $i = 1; $i <= 105; $i++ ) {
			vv_fx_voter( $pid, sprintf( '%03d', $i ), "m{$i}@example.org", null );
		}

		vv_fx_http( [] );
		ob_start();
		vv_render_members_table( vv_fx_poll_row( $pid ) );
		$p1 = (string) ob_get_clean();
		vv_check( str_contains( $p1, 'Seite 1 von 2' ), '[FU005#AK01] Teilnehmerliste zeigt Seite 1 von 2' );
		vv_check( str_contains( $p1, '001' ) && ! str_contains( $p1, '105' ),
			'[FU005#AK01] Seite 1 ist auf 100 Einträge begrenzt' );

		vv_fx_http( [ 'vv_page' => 2 ] );
		ob_start();
		vv_render_members_table( vv_fx_poll_row( $pid ) );
		$p2 = (string) ob_get_clean();
		vv_check( str_contains( $p2, '105' ) && ! str_contains( $p2, '>001<' ),
			'[FU005#AK01] Seite 2 zeigt die restlichen Einträge' );
	},
];
