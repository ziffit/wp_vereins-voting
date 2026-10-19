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
];
