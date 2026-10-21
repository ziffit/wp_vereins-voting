<?php
/**
 * [FU009] Ergebnis + Prüfliste (Frontend-Shortcode).
 */
return [

	'test_08_fu009_ak01_kein_ergebnis_solange_laeuft' => function () {
		$pid = vv_fx_poll( [ 'status' => 'active' ] );
		vv_fx_ballot( $pid, 'ja' );
		vv_fx_http( [ 'poll' => $pid ] );
		$html = vv_shortcode_results();
		vv_check( str_contains( $html, 'läuft noch' ), '[FU009#AK01] Hinweis solange Abstimmung läuft' );
		vv_check( ! str_contains( $html, 'Prüfliste' ), '[FU009#AK01] keine Prüfliste solange laufend' );
	},

	'test_08_fu009_ak01_draft_not_started_note' => function () {
		$pid = vv_fx_poll( [ 'status' => 'draft', 'start_off' => '+1 day', 'end_off' => '+2 days' ] );
		vv_fx_http( [ 'poll' => $pid ] );
		$html = vv_shortcode_results();
		vv_check( str_contains( $html, 'noch nicht begonnen' ), '[FU009#AK01] Hinweis für nicht gestartete Wahl' );
	},

	'test_08_fu009_ak02_ergebnis_nach_ende' => function () {
		$pid = vv_fx_poll( [ 'status' => 'closed' ] );
		$h1 = vv_fx_ballot( $pid, 'ja' );
		$h2 = vv_fx_ballot( $pid, 'ja' );
		$h3 = vv_fx_ballot( $pid, 'nein' );
		vv_fx_http( [ 'poll' => $pid ] );
		$html = vv_shortcode_results();
		vv_check( str_contains( $html, '66.7' ) && str_contains( $html, '33.3' ),
			'[FU009#AK02] Stimmenverteilung in Prozent korrekt (66.7% / 33.3%)' );
		vv_check( str_contains( $html, '<td>2</td>' ) && str_contains( $html, '<td>1</td>' ),
			'[FU009#AK02] absolute Zahlen korrekt' );
	},

	'test_08_fu009_ak02_ergebnis_abgelaufen_ohne_statusschluss' => function () {
		// Reale Aufgabe: Admin hat vergessen den Status zu ändern — nach end_dt trotzdem Ergebnis.
		$pid = vv_fx_poll( [ 'status' => 'active', 'start_off' => '-2 days', 'end_off' => '-1 day' ] );
		vv_fx_ballot( $pid, 'ja' );
		vv_fx_ballot( $pid, 'nein' );
		vv_fx_http( [ 'poll' => $pid ] );
		$html = vv_shortcode_results();
		vv_check( str_contains( $html, 'Prüfliste' ), '[FU009#AK02] Ergebnis erscheint nach Zeitraum-Ende auch ohne admin-status closed' );
		vv_check( str_contains( $html, 'Wahlzeitraum abgelaufen' ), '[FU009#AK02] Label weist auf abgelaufenen Zeitraum hin' );
	},

	'test_08_fu009_ak03_pruefliste_vollstandig_und_anonym' => function () {
		global $wpdb;
		$pid = vv_fx_poll( [ 'status' => 'closed' ] );
		$voter = vv_fx_voter( $pid, '55', 'geheim@example.org', 'tokq', '123456' ); // Teilnehmer existiert.
		$h1 = vv_fx_ballot( $pid, 'ja' );
		$h2 = vv_fx_ballot( $pid, 'nein' );
		$h3 = vv_fx_ballot( $pid, 'enthaltung' );

		vv_fx_http( [ 'poll' => $pid ] );
		$html = vv_shortcode_results();
		vv_check( str_contains( $html, 'Prüfliste' ), '[FU009#AK03] Prüftabelle angezeigt' );
		vv_check( str_contains( $html, '<strong>' . substr( $h1, 0, 5 ) . '</strong>' . substr( $h1, 5 ) )
			&& str_contains( $html, '<strong>' . substr( $h2, 0, 5 ) . '</strong>' . substr( $h2, 5 ) ),
			'[FU009#AK03] Receipts in der Prüfliste, erste 5 Zeichen fett hervorgehoben' );
		// Anonymitätstest: keine Namen, keine Mailadressen, keine Tokens in der Prüfliste.
		vv_check( ! str_contains( $html, 'geheim@example.org' ), '[FU009#AK03] anonym (keine E-Mail-Adresse)' );
		vv_check( ! str_contains( $html, 'tokq' ), '[FU009#AK03] anonym (keine Token-Werte)' );
		// Keine Uhrzeit in der Prüfliste – sonst Korrelation mit „Gewählt am“ möglich.
		vv_check( ! str_contains( $html, 'Zeitpunkt' ),
			'[FU009#AK03] Prüfliste ohne Zeitstempel (keine Korrelation mit voted_at)' );
		vv_check( 1 === substr_count( $html, '<strong>' . substr( $h1, 0, 5 ) . '</strong>' ),
			'[FU009#AK03] jeder Receipt exakt einmal gelistet' );
		vv_check( str_contains( $html, 'vv-version' ), '[FU007#AK05] Versionszeile auch bei den Ergebnissen' );
	},

	'test_08_fu008_ak01_formular_auswahl' => function () {
		$pid = vv_fx_poll( [ 'title' => 'Wahlfrage', 'question' => 'Ja oder Nein?' ] );
		vv_fx_http( [ 'poll' => $pid ] );
		$html = vv_shortcode_form();
		foreach ( [ 'Mitgliedsnummer', 'E-Mail', 'Wahl-PIN', 'value="ja"', 'value="nein"', 'value="enthaltung"' ] as $needle ) {
			vv_check( str_contains( $html, $needle ), "[FU008#AK01] Formularfeld $needle vorhanden" );
		}
	},

	'test_08_fu008_ak06_formular_nur_im_zeitraum' => function () {
		// Vor dem Start: kein Formular, Hinweis wann die Abstimmung aktiv ist.
		$pid = vv_fx_poll( [ 'status' => 'draft', 'start_off' => '+1 day', 'end_off' => '+2 days' ] );
		vv_fx_http( [ 'poll' => $pid ] );
		$html = vv_shortcode_form();
		vv_check( ! str_contains( $html, '<form' ) && ! str_contains( $html, 'name="pin"' ),
			'[FU008#AK06] vor dem Start kein Stimmformular' );
		vv_check( str_contains( $html, 'noch nicht aktiv' ) && str_contains( $html, vv_fmt( vv_fx_poll_row( $pid )->start_dt ) ),
			'[FU008#AK06] Hinweis nennt den Zeitraum vor dem Start' );

		// Nach dem Ende: kein Formular, Hinweis „beendet“.
		$pid2 = vv_fx_poll( [ 'status' => 'active', 'start_off' => '-2 days', 'end_off' => '-1 day' ] );
		vv_fx_http( [ 'poll' => $pid2 ] );
		$html2 = vv_shortcode_form();
		vv_check( ! str_contains( $html2, '<form' ), '[FU008#AK06] nach dem Ende kein Stimmformular' );
		vv_check( str_contains( $html2, 'beendet' ), '[FU008#AK06] Hinweis nach dem Ende' );
	},

	'test_08_fu008_ak02_formular_kein_poll' => function () {
		vv_fx_http( [] );
		$html = vv_shortcode_form();
		vv_check( str_contains( $html, 'Keine Abstimmung ausgewählt' ), '[FU008#AK01] ohne ?poll= bricht kurz mit Hinweis' );
	},
];
