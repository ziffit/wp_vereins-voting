<?php
/**
 * [FU004] Einladungsversand + [FU006#AK01] E-Mail-Vorlage.
 */
return [

	'test_04_fu004_ak01_ak02_alle_einladungen_verschicken' => function () {
		$pid = vv_fx_poll( [ 'title' => 'Online-Abstimmung' ] );
		vv_fx_voter( $pid, '101', 'eins@example.org', 'tok1' );
		vv_fx_voter( $pid, '102', 'zwei@example.org', 'tok2' );

		vv_fx_http( [], [
			'vv_action'    => 'send_invites',
			'poll'         => $pid,
			'activate_url' => 'https://seite.example.org/aktivieren/',
		] );
		[ $kind ] = vv_fx_run( 'vv_send_invites' );

		vv_check( 'redirect' === $kind, '[FU004#AK01] Versand schließt mit Redirect ab' );
		$mails = vv_fx_mails();
		vv_check( 2 === count( $mails ), '[FU004#AK01] 2 individualisierte Einladungen erzeugt' );

		// [FU004#AK02] Empfänger und Token korrekt je E-Mail.
		foreach ( [ 'eins@example.org' => 'tok1', 'zwei@example.org' => 'tok2' ] as $email => $expected_token ) {
			$matched = null;
			foreach ( $mails as $m ) {
				if ( trim( (string) $m['to'] ) === $email && str_contains( $m['body'], 'token=' . $expected_token ) ) {
					$matched = $m;
					break;
				}
			}
			vv_check( null !== $matched,
				"[FU004#AK02] E-Mail an $email enthält Aktivierungslink mit korrektem Token" );
		}
		$all_bodies = '';
		foreach ( $mails as $m ) {
			$all_bodies .= ' ' . $m['body'];
		}
		vv_check( str_contains( $all_bodies, 'https://seite.example.org/aktivieren/?' ),
			'[FU004#AK02] Aktivierungslink basiert auf der Admin-Aktivierungs-URL' );
	},

	'test_04_fu006_ak01_einladungstext_platzhalter' => function () {
		$pid = vv_fx_poll( [ 'title' => 'Satzungsänderung', 'question' => 'Stimmt zu?' ] );
		vv_fx_voter( $pid, '101', 'empf@example.org', 'tok1' );

		vv_fx_http( [], [
			'vv_action'    => 'send_invites',
			'poll'         => $pid,
			'activate_url' => 'https://seite.example.org/aktivieren/',
		] );
		vv_fx_run( 'vv_send_invites' );
		$mails = vv_fx_mails();
		if ( ! empty( $mails[0] ) ) {
			$mail = $mails[0];
			vv_check( str_contains( $mail['subject'], 'Satzungsänderung'), '[FU006#AK01] Betreff enthält Abstimmungstitel' );
			vv_check( str_contains( $mail['body'], 'Testverein e.V.' ), '[FU006#AK01] Mailtext enthält Vereinsnamen' );
			vv_check( str_contains( $mail['body'], 'Satzungsänderung' ), '[FU006#AK01] Mailtext enthält Abstimmungstitel' );
			vv_check( str_contains( $mail['body'], 'Wahlzeitraum' ), '[FU006#AK01] Mailtext enthält Fristen' );
			vv_check( str_contains( $mail['body'], '101' ), '[FU006#AK01] Mailtext enthält Mitgliedsnummer' );
			vv_check( ! str_contains( $mail['body'], '{link}' ), 'Platzhalter {link} ist ersetzt' );
			vv_check( ! str_contains( $mail['body'], '{poll_title}' ), 'Platzhalter {poll_title} ist ersetzt' );
		} else {
			vv_check( false, 'keine Mail versendet – Mitte fehlt' );
		}
	},

	'test_04_fu004_ak01_aktivierte_bekommen_keine_mail' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '1', 'alt@example.org', 'tok_old' );
		vv_fx_voter( $pid, '2', 'neu@example.org', 'tok_new' );
		global $wpdb;
		// erster Wähler hat PIN gesetzt und Token verbraucht (wie nach FU007).
		$wpdb->query( "UPDATE " . vv_tbl( 'voting_voters' ) . " SET activation_token = NULL, pin_hash = 'x', has_voted = 0 WHERE email = 'alt@example.org'" );

		vv_fx_http( [], [
			'vv_action'    => 'send_invites',
			'poll'         => $pid,
			'activate_url' => 'https://x.example.org/a/',
		] );
		vv_fx_run( 'vv_send_invites' );
		vv_check( 1 === count( vv_fx_mails() ), '[FU004#AK01] nur noch aktive Wähler ohne Token bekommen Einladung' );
	},

	'test_04_fu004_ak02_aktivierungs_url_plicht' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '1', 'm@example.org', 't' );
		vv_fx_http( [], [ 'vv_action' => 'send_invites', 'poll' => $pid ] ); // kein activate_url
		[ $kind, $msg ] = vv_fx_run( 'vv_send_invites' );
		vv_check( 'die' === $kind, '[FU004#AK02] ohne Aktivierungs-URL bricht Versand ab' );
	},
];
