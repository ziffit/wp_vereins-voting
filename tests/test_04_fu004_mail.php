<?php
/**
 * [FU004] Einladungsversand + [FU006] E-Mail-Vorlage, Vorschau und Queue.
 */
return [

	'test_04_fu004_ak01_ak02_alle_einladungen_verschicken' => function () {
		vv_fx_mail_settings();
		$pid = vv_fx_poll( [ 'title' => 'Online-Abstimmung' ] );
		vv_fx_voter( $pid, '101', 'eins@example.org', 'tok1' );
		vv_fx_voter( $pid, '102', 'zwei@example.org', 'tok2' );

		vv_fx_http( [], [
			'vv_action' => 'send_invites',
			'poll'      => $pid,
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
		vv_check( str_contains( $all_bodies, 'https://seite.example.org/aktivieren/' ),
			'[FU004#AK02] Aktivierungslink basiert auf der gespeicherten Aktivierungs-URL' );
		vv_check( in_array( 'Content-Type: text/html; charset=UTF-8', $mails[0]['headers'], true ),
			'[FU006#AK02] Mail geht als HTML mit UTF-8 raus' );
		vv_check( in_array( 'Reply-To: admin@example.test', $mails[0]['headers'], true ),
			'[FU006#AK02] Reply-To ist die WordPress-Adminadresse' );

		$row = vv_fx_voter_row( 'eins@example.org' );
		vv_check( $row && ! empty( $row->invited_at ) && 0 === (int) $row->invite_attempts,
			'[FU004#AK04] erfolgreicher Versand wird je Person vermerkt' );
		vv_check( 2 === (int) ( get_option( 'vv_send_window' )['count'] ?? 0 ),
			'[FU004#AK04] globales Versandfenster zählt beide Versuche' );

		// Nach dem Erzeugen des Wahl-Codes ist der Token weg, die Einladung bleibt als gesendet gezählt.
		global $wpdb;
		$wpdb->update( vv_tbl( 'voting_voters' ), [ 'activation_token' => null ], [ 'email' => 'eins@example.org' ] );
		$counts = vv_poll_mail_counts( $pid );
		vv_check( 2 === $counts['sent'], '[FU004#AK04] gesendete Einladung bleibt nach Code-Vergabe gezählt' );
	},

	'test_04_fu006_ak01_einladungstext_platzhalter' => function () {
		vv_fx_mail_settings();
		$pid = vv_fx_poll( [ 'title' => 'Satzungsänderung', 'question' => 'Stimmt zu?' ] );
		vv_fx_voter( $pid, '101', 'empf@example.org', 'tok1', null, 'Erika', 'Muster' );

		vv_fx_http( [], [ 'vv_action' => 'send_invites', 'poll' => $pid ] );
		vv_fx_run( 'vv_send_invites' );
		$mails = vv_fx_mails();
		vv_check( 1 === count( $mails ), '[FU006#AK01] eine Einladung erzeugt' );
		$mail = $mails[0];

		vv_check( str_contains( $mail['subject'], 'Satzungsänderung' ), '[FU006#AK01] Betreff enthält Abstimmungstitel' );
		vv_check( str_contains( $mail['body'], 'Testverein e.V.' ), '[FU006#AK01] Mailtext enthält Vereinsnamen aus dem Footer' );
		vv_check( str_contains( $mail['body'], 'Satzungsänderung' ), '[FU006#AK01] Mailtext enthält Abstimmungstitel' );
		vv_check( str_contains( $mail['body'], 'Wahlzeitraum' ), '[FU006#AK01] Mailtext enthält Fristen' );
		// [FU006#AK01] Die Mitgliedsnummer ist bewusst kein Mail-Bestandteil:
		// Wer eine fremde E-Mail liest, kennt damit nicht die Mitgliedsnummer.
		vv_check( ! str_contains( $mail['body'], '101' ), '[FU006#AK01] Mailtext enthält keine Mitgliedsnummer' );
		vv_check( str_contains( $mail['body'], 'Hallo Erika Muster,' ), '[FU006#AK04] Standardtext begrüßt mit Vor- und Nachnamen' );
		vv_check( str_contains( $mail['body'], 'Wahl-Code erzeugen' ), '[FU006#AK02] persönlicher Aktivierungslink ist ein Button' );
		foreach ( [ '{link}', '{poll_title}', '{vorname}', '{nachname}' ] as $ph ) {
			vv_check( ! str_contains( $mail['body'], $ph ), "[FU006#AK04] Platzhalter $ph ist ersetzt" );
		}
	},

	'test_04_fu004_ak01_aktivierte_bekommen_keine_mail' => function () {
		vv_fx_mail_settings();
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '1', 'alt@example.org', 'tok_old' );
		vv_fx_voter( $pid, '2', 'neu@example.org', 'tok_new' );
		global $wpdb;
		// erster Wähler hat Wahl-Code gesetzt und Token verbraucht (wie nach FU007).
		$wpdb->query( "UPDATE " . vv_tbl( 'voting_voters' ) . " SET activation_token = NULL, pin_hash = 'x', has_voted = 0 WHERE email = 'alt@example.org'" );

		vv_fx_http( [], [ 'vv_action' => 'send_invites', 'poll' => $pid ] );
		vv_fx_run( 'vv_send_invites' );
		vv_check( 1 === count( vv_fx_mails() ), '[FU004#AK01] nur noch aktive Wähler ohne Token bekommen Einladung' );
	},

	'test_04_fu004_ak01_ohne_offene_einladungen_raeumt_queue_status' => function () {
		vv_fx_mail_settings();
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '1', 'fertig@example.org', null );
		global $wpdb;
		$wpdb->update( vv_tbl( 'voting_voters' ), [ 'invited_at' => current_time( 'mysql' ) ], [ 'email' => 'fertig@example.org' ] );
		update_option( vv_queue_active_key( $pid ), 1 );
		update_option( vv_queue_paused_key( $pid ), 1 );

		vv_fx_http( [], [ 'vv_action' => 'send_invites', 'poll' => $pid ] );
		[ $kind, $url ] = vv_fx_run( 'vv_send_invites' );
		vv_check( 'redirect' === $kind && str_contains( (string) $url, 'queued=0' ),
			'[FU004#AK01] Start ohne offene Einladungen meldet queued=0' );
		vv_check( ! vv_queue_is_active( $pid ) && ! vv_queue_is_paused( $pid ),
			'[FU004#AK01] veralteter Queue-Status wird dabei aufgeräumt' );
	},

	'test_04_fu004_ak02_aktivierungs_url_plicht' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '1', 'm@example.org', 't' );
		update_option( 'vv_mail_footer', 'Testverein e.V.' );
		vv_fx_http( [], [ 'vv_action' => 'send_invites', 'poll' => $pid ] ); // keine Aktivierungs-URL
		[ $kind, $msg ] = vv_fx_run( 'vv_send_invites' );
		vv_check( 'die' === $kind && str_contains( (string) $msg, 'Aktivierungsseite' ),
			'[FU004#AK02] ohne Aktivierungs-URL bricht Versand ab' );

		update_option( 'vv_mail_activate_url', 'https://' );
		vv_fx_http( [], [ 'vv_action' => 'send_invites', 'poll' => $pid ] );
		[ $kind, $msg ] = vv_fx_run( 'vv_send_invites' );
		vv_check( 'die' === $kind && str_contains( (string) $msg, 'Aktivierungsseite' ),
			'[FU004#AK02] URL ohne Host wird abgewiesen' );
	},

	'test_04_fu004_ak02_footer_plicht' => function () {
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '1', 'm@example.org', 't' );
		vv_fx_mail_settings( [ 'footer' => '' ] );
		vv_fx_http( [], [ 'vv_action' => 'send_invites', 'poll' => $pid ] );
		[ $kind, $msg ] = vv_fx_run( 'vv_send_invites' );
		vv_check( 'die' === $kind && str_contains( (string) $msg, 'Footer' ),
			'[FU004#AK02] ohne Footer bricht Versand ab' );
	},

	'test_04_fu006_ak03_vorlage_wird_global_gespeichert' => function () {
		update_option( 'vv_invite_subject', 'Alter Betreff {poll_title}' );
		vv_check( 'Alter Betreff {poll_title}' === vv_mail_subject_template(),
			'[FU006#AK03] alter Betreff bleibt als Fallback lesbar' );

		vv_fx_http( [], [
			'vv_action'          => 'save_mail_settings',
			'mail_subject'       => 'Eigener Betreff {poll_title}',
			'mail_intro'         => "Hallo {vorname} {nachname},\r\n\r\nbitte abstimmen.",
			'mail_footer'        => "Testverein e.V.\nImpressum: https://admin.test/impressum/",
			'mail_activate_url'  => 'https://seite.example.org/aktivieren/',
			'mail_vote_url'      => 'https://seite.example.org/waehlen/',
			'mail_results_url'   => 'https://seite.example.org/ergebnis/',
			'mail_rate'          => '12',
		] );
		[ $kind, $url ] = vv_fx_run( 'vv_save_mail_settings' );
		vv_check( 'redirect' === $kind && str_contains( (string) $url, 'mail_saved=1' ),
			'[FU006#AK03] globale Vorlage wird gespeichert' );
		vv_check( 'Eigener Betreff {poll_title}' === get_option( 'vv_mail_subject' ),
			'[FU006#AK03] Betreff wird global gespeichert' );
		vv_check( str_starts_with( (string) get_option( 'vv_mail_intro' ), 'Hallo {vorname} {nachname},' ),
			'[FU006#AK03] Einleitung wird global gespeichert' );
		vv_check( 'https://seite.example.org/waehlen/' === get_option( 'vv_mail_vote_url' )
			&& 'https://seite.example.org/ergebnis/' === get_option( 'vv_mail_results_url' ),
			'[FU006#AK02] Pflicht-URLs der Stimmformular- und Ergebnisseite werden gespeichert' );
		vv_check( 12 === (int) get_option( 'vv_mail_rate' ), '[FU004#AK04] Rate wird gespeichert' );

		ob_start();
		vv_render_mail_settings();
		$html = (string) ob_get_clean();
		vv_check( str_contains( $html, 'Eigener Betreff {poll_title}' ), '[FU006#AK03] Einstellungsseite zeigt den Betreff' );
		vv_check( str_contains( $html, 'Testverein e.V.' ), '[FU006#AK03] Einstellungsseite zeigt den Footer' );
	},

	'test_04_fu006_ak03_unbekannter_platzhalter_wird_abgewiesen' => function () {
		vv_fx_mail_settings();
		vv_fx_http( [], [
			'vv_action'         => 'save_mail_settings',
			'mail_subject'      => 'Test {unbekannt}',
			'mail_intro'        => 'Hallo',
			'mail_footer'       => 'Footer',
			'mail_activate_url' => 'https://seite.example.org/a/',
			'mail_rate'         => '10',
		] );
		[ $kind, $url ] = vv_fx_run( 'vv_save_mail_settings' );
		vv_check( 'redirect' === $kind && str_contains( (string) $url, 'mail_error=1' ),
			'[FU006#AK04] unbekannter Platzhalter verhindert das Speichern' );
		vv_check( 'Einladung: {poll_title}' === get_option( 'vv_mail_subject' ),
			'[FU006#AK04] vorhandene Vorlage bleibt bei Fehler unverändert' );
	},

	'test_04_fu006_ak02_ungueltige_optionale_url_wird_abgewiesen' => function () {
		vv_fx_mail_settings( [ 'vote' => 'https://seite.example.org/w/', 'results' => '' ] );
		vv_fx_http( [], [
			'vv_action'         => 'save_mail_settings',
			'mail_subject'      => 'Test',
			'mail_intro'        => 'Hallo',
			'mail_footer'       => 'Footer',
			'mail_activate_url' => 'https://seite.example.org/a/',
			'mail_vote_url'     => 'https:///pfad',
			'mail_results_url'  => 'https://seite.example.org/e/',
			'mail_rate'         => '10',
		] );
		[ $kind, $url ] = vv_fx_run( 'vv_save_mail_settings' );
		vv_check( 'redirect' === $kind && str_contains( (string) $url, 'mail_error=1' ),
			'[FU006#AK02] URL ohne Host wird abgewiesen' );
		vv_check( 'https://seite.example.org/w/' === get_option( 'vv_mail_vote_url' )
			&& '' === (string) get_option( 'vv_mail_results_url', '' ),
			'[FU006#AK02] optionale URLs bleiben bei Abweisung unverändert' );
	},

	'test_04_fu006_ak01_url_pflichtfelder' => function () {
		// Speichern ohne Stimmformular- oder Ergebnis-URL wird abgewiesen.
		vv_fx_mail_settings( [ 'vote' => 'https://seite.example.org/w/', 'results' => 'https://seite.example.org/e/' ] );
		vv_fx_http( [], [
			'vv_action'         => 'save_mail_settings',
			'mail_subject'      => 'Test',
			'mail_intro'        => 'Hallo',
			'mail_footer'       => 'Footer',
			'mail_activate_url' => 'https://seite.example.org/a/',
			'mail_vote_url'     => '',
			'mail_results_url'  => '',
			'mail_rate'         => '10',
		] );
		[ $kind, $url ] = vv_fx_run( 'vv_save_mail_settings' );
		vv_check( 'redirect' === $kind && str_contains( (string) $url, 'mail_error=1' ),
			'[FU006#AK01] leere Pflicht-URLs werden abgewiesen' );
		vv_check( 'https://seite.example.org/w/' === get_option( 'vv_mail_vote_url' )
			&& 'https://seite.example.org/e/' === get_option( 'vv_mail_results_url' ),
			'[FU006#AK01] vorhandene Pflicht-URLs bleiben bei Abweisung unverändert' );

		// Versand-Sperre: ohne Stimmformular-URL bricht der Versand ab.
		vv_fx_mail_settings( [ 'vote' => '' ] );
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '1', 'pflicht@example.org', 't' );
		vv_fx_http( [], [ 'vv_action' => 'send_invites', 'poll' => $pid ] );
		[ $kind, $msg ] = vv_fx_run( 'vv_send_invites' );
		vv_check( 'die' === $kind && str_contains( (string) $msg, 'Stimmformular-Seite' ),
			'[FU006#AK01] ohne Stimmformular-URL bricht der Versand ab' );
	},

	'test_04_fu006_ak03_nur_leerzeichen_ist_pflichtfeld_verletzung' => function () {
		vv_fx_mail_settings();
		vv_fx_http( [], [
			'vv_action'         => 'save_mail_settings',
			'mail_subject'      => '   ',
			'mail_intro'        => 'Hallo',
			'mail_footer'       => 'Footer',
			'mail_activate_url' => 'https://seite.example.org/a/',
			'mail_rate'         => '10',
		] );
		[ $kind, $url ] = vv_fx_run( 'vv_save_mail_settings' );
		vv_check( 'redirect' === $kind && str_contains( (string) $url, 'mail_error=1' ),
			'[FU006#AK03] reine Leerzeichen gelten als leeres Pflichtfeld' );
		vv_check( 'Einladung: {poll_title}' === get_option( 'vv_mail_subject' ),
			'[FU006#AK03] vorhandener Betreff bleibt bei Fehler unverändert' );
	},

	'test_04_fu004_ak04_rate_ausserhalb_des_bereichs' => function () {
		vv_fx_http( [], [
			'vv_action'         => 'save_mail_settings',
			'mail_subject'      => 'Betreff',
			'mail_intro'        => 'Hallo',
			'mail_footer'       => 'Footer',
			'mail_activate_url' => 'https://seite.example.org/a/',
			'mail_rate'         => '-1',
		] );
		[ $kind, $url ] = vv_fx_run( 'vv_save_mail_settings' );
		vv_check( 'redirect' === $kind && str_contains( (string) $url, 'mail_error=1' ),
			'[FU004#AK04] negative Rate wird abgewiesen' );
		vv_check( 10 === vv_mail_rate(), '[FU004#AK04] Standardrate bleibt bei ungültiger Eingabe' );
	},

	'test_04_fu006_ak04_namens_platzhalter' => function () {
		vv_fx_mail_settings( [
			'subject' => 'Einladung für {first_name}',
			'intro'   => "Hallo {first_name} {last_name},\r\n\r\nbitte abstimmen.",
		] );
		$pid = vv_fx_poll( [ 'title' => 'Namenswahl' ] );
		vv_fx_voter( $pid, '101', 'erika@example.org', 'tok1', null, 'Erika', 'Muster' );
		vv_fx_voter( $pid, '102', 'max@example.org', 'tok2', null, 'Max', 'Beispiel' );

		vv_fx_http( [], [ 'vv_action' => 'send_invites', 'poll' => $pid ] );
		vv_fx_run( 'vv_send_invites' );

		$all = '';
		foreach ( vv_fx_mails() as $m ) {
			$all .= $m['subject'] . "\n" . $m['body'] . "\n";
		}
		vv_check( str_contains( $all, 'Hallo Erika Muster,' ) && str_contains( $all, 'Hallo Max Beispiel,' ),
			'[FU006#AK04] {first_name}/{last_name} werden je Empfänger ersetzt' );
		vv_check( ! str_contains( $all, '{first_name}' ) && ! str_contains( $all, '{last_name}' ),
			'[FU006#AK04] keine Platzhalter-Reste in den Mails' );
	},

	'test_04_fu004_ak04_rate_limit_und_cron_fortsetzung' => function () {
		vv_fx_mail_settings( [ 'rate' => 1 ] );
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '1', 'eins@example.org', 't1' );
		vv_fx_voter( $pid, '2', 'zwei@example.org', 't2' );

		vv_fx_http( [], [ 'vv_action' => 'send_invites', 'poll' => $pid ] );
		vv_fx_run( 'vv_send_invites' );
		vv_check( 1 === count( vv_fx_mails() ), '[FU004#AK04] Rate 1 begrenzt den ersten Lauf auf eine Mail' );
		vv_check( [] !== vv_queue_pending_polls(), '[FU004#AK04] offene Einladung bleibt für den nächsten Lauf' );
		vv_check( false !== wp_next_scheduled( 'vv_send_queue_tick' ), '[FU004#AK04] WP-Cron-Termin ist geplant' );

		// Fenster abgelaufen: der nächste Lauf darf wieder senden.
		update_option( 'vv_send_window', [ 'start' => time() - 301, 'count' => 1 ] );
		$sent = vv_run_send_queue();
		vv_check( 1 === $sent && 2 === count( vv_fx_mails() ), '[FU004#AK04] Folgelauf sendet die restliche Einladung' );
		$counts = vv_poll_mail_counts( $pid );
		vv_check( 0 === $counts['pending'] && 2 === $counts['sent'], '[FU004#AK04] danach ist nichts mehr offen' );
		vv_check( ! vv_queue_is_active( $pid ), '[FU004#AK04] aktive Markierung wird aufgeräumt' );
	},

	'test_04_fu004_ak04_beendete_abstimmung_wird_nicht_gesendet' => function () {
		vv_fx_mail_settings();
		$closed = vv_fx_poll( [ 'status' => 'closed' ] );
		vv_check( ! vv_queue_poll_sendable( vv_fx_poll_row( $closed ) ),
			'[FU004#AK01] geschlossene Abstimmung erhält keinen Versand' );

		$pid = vv_fx_poll( [ 'end_off' => '-1 hour' ] );
		vv_fx_voter( $pid, '1', 'alt@example.org', 't1' );

		vv_fx_http( [], [ 'vv_action' => 'send_invites', 'poll' => $pid ] );
		[ $kind, $msg ] = vv_fx_run( 'vv_send_invites' );
		vv_check( 'die' === $kind && str_contains( (string) $msg, 'nicht mehr möglich' ),
			'[FU004#AK04] abgelaufene Abstimmung erhält keinen Versand' );
		vv_check( [] === vv_fx_mails(), '[FU004#AK04] keine Mail an abgelaufener Abstimmung' );

		update_option( vv_queue_active_key( $pid ), 1 );
		vv_run_send_queue();
		vv_check( ! vv_queue_is_active( $pid ), '[FU004#AK04] beendete Abstimmung wird aus der Queue geräumt' );
	},

	'test_04_fu004_ak04_pause_und_fortsetzen' => function () {
		vv_fx_mail_settings( [ 'rate' => 1 ] );
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '1', 'eins@example.org', 't1' );
		vv_fx_voter( $pid, '2', 'zwei@example.org', 't2' );

		vv_fx_http( [], [ 'vv_action' => 'pause_send', 'poll' => $pid ] );
		[ $kind ] = vv_fx_run( 'vv_pause_send' );
		vv_check( 'die' === $kind, '[FU004#AK04] Pausieren vor dem Start wird abgewiesen' );

		vv_fx_http( [], [ 'vv_action' => 'send_invites', 'poll' => $pid ] );
		vv_fx_run( 'vv_send_invites' );
		vv_check( 1 === count( vv_fx_mails() ), '[FU004#AK04] erster Lauf sendet eine Einladung' );

		vv_fx_http( [], [ 'vv_action' => 'pause_send', 'poll' => $pid ] );
		[ $kind ] = vv_fx_run( 'vv_pause_send' );
		vv_check( 'redirect' === $kind && vv_queue_is_paused( $pid ), '[FU004#AK04] Versand lässt sich pausieren' );

		update_option( 'vv_send_window', [ 'start' => time() - 301, 'count' => 1 ] );
		vv_run_send_queue();
		vv_check( 1 === count( vv_fx_mails() ), '[FU004#AK04] pausiert wird nicht gesendet' );

		vv_fx_http( [], [ 'vv_action' => 'resume_send', 'poll' => $pid ] );
		[ $kind ] = vv_fx_run( 'vv_resume_send' );
		vv_check( 'redirect' === $kind && 2 === count( vv_fx_mails() ), '[FU004#AK04] Fortsetzen sendet weiter' );
		vv_check( ! vv_queue_is_paused( $pid ), '[FU004#AK04] Pause wird beim Fortsetzen aufgehoben' );
	},

	'test_04_fu004_ak05_retry_nach_drei_fehlern' => function () {
		vv_fx_mail_settings();
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '1', 'retry@example.org', 't1' );
		$GLOBALS['vv_mail_fail'] = true;

		vv_fx_http( [], [ 'vv_action' => 'send_invites', 'poll' => $pid ] );
		vv_fx_run( 'vv_send_invites' );
		$row = vv_fx_voter_row( 'retry@example.org' );
		vv_check( 1 === (int) $row->invite_attempts && empty( $row->invited_at ),
			'[FU004#AK05] Fehlversuch wird gezählt, nicht als gesendet markiert' );

		update_option( 'vv_send_window', [ 'start' => time() - 301, 'count' => 0 ] );
		vv_run_send_queue();
		update_option( 'vv_send_window', [ 'start' => time() - 301, 'count' => 0 ] );
		vv_run_send_queue();
		$row = vv_fx_voter_row( 'retry@example.org' );
		vv_check( 3 === (int) $row->invite_attempts, '[FU004#AK05] nach drei Fehlversuchen stoppt der Automat' );
		vv_check( ! vv_queue_is_active( $pid ), '[FU004#AK05] kein automatischer Weiterlauf nach drei Fehlern' );

		$before = count( vv_fx_mails() );
		$GLOBALS['vv_mail_fail'] = false;
		vv_fx_http( [], [ 'vv_action' => 'retry_send', 'poll' => $pid ] );
		[ $kind ] = vv_fx_run( 'vv_retry_send' );
		$row = vv_fx_voter_row( 'retry@example.org' );
		vv_check( 'redirect' === $kind && ! empty( $row->invited_at ) && 0 === (int) $row->invite_attempts,
			'[FU004#AK05] manueller Retry setzt den Zähler zurück und sendet erfolgreich' );
		vv_check( $before + 1 === count( vv_fx_mails() ), '[FU004#AK05] Retry versendet genau eine neue Mail' );
	},

	'test_04_fu006_ak02_html_und_plain_text' => function () {
		// Ohne URLs bleibt der renderer-basierte Schritt 2/3 aus (Restverhalten der Vorlage).
		vv_fx_mail_settings( [ 'vote' => '', 'results' => '' ] );
		$pid = vv_fx_poll( [ 'title' => 'Layoutwahl', 'question' => 'Frage?' ] );
		global $wpdb;
		$wpdb->update( vv_tbl( 'voting_polls' ), [ 'mail_url' => 'https://verein.example.org/info/' ], [ 'id' => $pid ] );
		$poll   = vv_fx_poll_row( $pid );
		$voter  = (object) [
			'member_no'        => '101',
			'email'            => 'layout@example.org',
			'first_name'       => 'Erika',
			'last_name'        => 'Muster',
			'activation_token' => 'toklayout',
		];
		$parts = vv_mail_parts( $poll, $voter );

		vv_check( str_contains( $parts['html'], '<!DOCTYPE html>' ) && str_contains( $parts['html'], 'Wahl-Code erzeugen' ),
			'[FU006#AK02] HTML-Teil enthält Layout und Aktivierungsbutton' );
		vv_check( str_contains( $parts['html'], 'Mehr zum Ablauf' ) && str_contains( $parts['html'], 'https://verein.example.org/info/' ),
			'[FU006#AK02] optionaler Website-Link steht im HTML-Teil' );
		vv_check( str_contains( $parts['html'], 'Testverein e.V.' ), '[FU006#AK02] Footer steht im HTML-Teil' );
		vv_check( str_contains( $parts['plain'], 'Wahl-Code erzeugen (6 Zeichen: Buchstaben und Ziffern): https://seite.example.org/aktivieren/' ),
			'[FU006#AK02] Text-Teil enthält den persönlichen Link' );
		vv_check( str_contains( $parts['plain'], 'Mehr zum Ablauf und zur eingesetzten Software: https://verein.example.org/info/' )
			&& str_contains( $parts['plain'], 'Testverein e.V.' ),
			'[FU006#AK02] Text-Teil enthält Website-Link und Footer' );
		vv_check( ! str_contains( $parts['html'], 'Jetzt abstimmen' ) && ! str_contains( $parts['html'], 'Ergebnisse ansehen' ),
			'[FU006#AK01] ohne optionale URLs bleiben Schritt 2 und 3 aus' );

		vv_mail_send_parts( 'layout@example.org', $parts );
		$mail = vv_fx_mails()[0];
		vv_check( in_array( 'Content-Type: text/html; charset=UTF-8', $mail['headers'], true ),
			'[FU006#AK02] Sendeweg setzt den HTML-Header' );
		vv_check( ! preg_grep( '/^From:/i', $mail['headers'] ),
			'[FU006#AK02] Plugin überschreibt den Absender nicht' );
	},

	'test_04_fu006_ak01_ablaufschritte_mit_optionalen_urls' => function () {
		vv_fx_mail_settings( [
			'vote'    => 'https://seite.example.org/waehlen/',
			'results' => 'https://seite.example.org/ergebnis/',
		] );
		$pid  = vv_fx_poll( [ 'title' => 'Schrittwahl' ] );
		$poll = vv_fx_poll_row( $pid );
		$voter = (object) [
			'member_no'        => '101',
			'email'            => 'schritte@example.org',
			'first_name'       => 'Erika',
			'last_name'        => 'Muster',
			'activation_token' => 'tokschritte',
		];
		$parts = vv_mail_parts( $poll, $voter );

		vv_check( str_contains( $parts['html'], 'So geht’s weiter' ) && str_contains( $parts['html'], 'Wahl-Code erzeugen' ),
			'[FU006#AK01] Schritt 1: Aktivierungsbutton' );
		vv_check( str_contains( $parts['html'], 'Jetzt abstimmen' ) && str_contains( $parts['html'], 'https://seite.example.org/waehlen/' ),
			'[FU006#AK01] Schritt 2: Button zur Stimmformular-Seite' );
		vv_check( str_contains( $parts['html'], 'Ergebnisse ansehen' ) && str_contains( $parts['html'], 'https://seite.example.org/ergebnis/' ),
			'[FU006#AK01] Schritt 3: Link zur Ergebnisseite' );
		vv_check( str_contains( $parts['plain'], '2. Im Wahlzeitraum abstimmen: https://seite.example.org/waehlen/' )
			&& str_contains( $parts['plain'], '3. Nach Wahlende Ergebnisse prüfen: https://seite.example.org/ergebnis/' ),
			'[FU006#AK01] Plain-Text nennt die drei Schritte' );
	},

	'test_04_fu006_ak05_vorschau_und_testversand' => function () {
		vv_fx_mail_settings();
		$pid = vv_fx_poll( [ 'title' => 'Vorschauwahl', 'question' => 'Frage zur Vorschau?' ] );
		vv_fx_voter( $pid, '999', 'echt@example.org', 'echtertoken', null, 'Echt', 'Person' );

		$page = vv_mail_preview_page( $pid );
		vv_check( str_contains( $page, 'Erika Muster' ) && str_contains( $page, '0123456789abcdef0123456789abcdef' ),
			'[FU006#AK05] Vorschau nutzt erfundene Beispieldaten' );
		vv_check( ! str_contains( $page, 'echt@example.org' ) && ! str_contains( $page, 'Echt Person' )
			&& ! str_contains( $page, 'echtertoken' ),
			'[FU006#AK05] Vorschau enthält keine echten Mitgliedsdaten' );
		vv_check( str_contains( $page, 'Vorschauwahl' ) && str_contains( $page, 'Frage zur Vorschau?' ),
			'[FU006#AK05] Vorschau nutzt den Text der gewählten Abstimmung' );
		// [FU006#AK05] Bestandsinstallation ohne Pflicht-URLs: Hinweis auf entfallene Schritte 2/3.
		vv_fx_mail_settings( [ 'vote' => '', 'results' => '' ] );
		$page2 = vv_mail_preview_page( $pid );
		vv_check( str_contains( $page2, 'Schritt 2 („Jetzt abstimmen“)' ) && str_contains( $page2, 'Schritt 3 („Ergebnisse ansehen“)' ),
			'[FU006#AK05] Vorschau weist auf fehlende Pflicht-URLs der Schritte hin' );

		// Gespeicherte Pflicht-URLs: kein Schritt-Hinweis mehr.
		vv_fx_mail_settings();
		$page = vv_mail_preview_page( $pid );
		vv_check( ! str_contains( $page, 'Schritt 2 („Jetzt abstimmen“)' ),
			'[FU006#AK05] mit gesetzten Pflicht-URLs entfällt der Schritt-Hinweis' );

		$before = count( vv_fx_mails() );
		$result = vv_send_test_mail( $pid );
		vv_check( true === $result, '[FU006#AK05] Testmail lässt sich versenden' );
		$mails = vv_fx_mails();
		$test_mail = $mails[ count( $mails ) - 1 ];
		vv_check( $before + 1 === count( $mails ) && 'admin@example.test' === $test_mail['to'],
			'[FU006#AK05] Testmail geht an das angemeldete WordPress-Konto' );
		vv_check( ! str_contains( $test_mail['body'], 'echt@example.org' ) && ! str_contains( $test_mail['body'], 'Echt Person' )
			&& ! str_contains( $test_mail['body'], 'echtertoken' ),
			'[FU006#AK05] Testmail enthält keine echten Mitgliedsdaten' );
		$row = vv_fx_voter_row( 'echt@example.org' );
		vv_check( empty( $row->invited_at ) && 0 === (int) $row->invite_attempts,
			'[FU006#AK05] Testmail verändert den Versandstatus nicht' );
	},

	'test_04_fu004_ak03_poll_text_und_link_speichern' => function () {
		vv_fx_mail_settings();
		$pid = vv_fx_poll();

		vv_fx_http( [], [
			'vv_action' => 'save_poll_mail',
			'poll'      => $pid,
			'mail_text' => "Hallo {vorname},\r\n\r\n{poll_title}: {question}",
			'mail_url'  => 'https://verein.example.org/info/',
		] );
		[ $kind, $url ] = vv_fx_run( 'vv_save_poll_mail' );
		$row = vv_fx_poll_row( $pid );
		vv_check( 'redirect' === $kind && str_contains( (string) $url, 'mail_saved=1' ),
			'[FU004#AK03] Textblock wird gespeichert' );
		vv_check( "Hallo {vorname},\r\n\r\n{poll_title}: {question}" === $row->mail_text
			&& 'https://verein.example.org/info/' === $row->mail_url,
			'[FU004#AK03] Textblock und Website-Link stehen an der Abstimmung' );

		vv_fx_http( [], [
			'vv_action' => 'save_poll_mail',
			'poll'      => $pid,
			'mail_text' => 'Text',
			'mail_url'  => 'javascript:alert(1)',
		] );
		[ $kind, $url ] = vv_fx_run( 'vv_save_poll_mail' );
		vv_check( 'redirect' === $kind && str_contains( (string) $url, 'mail_error=1' ),
			'[FU004#AK03] ungültiger Website-Link wird abgewiesen' );
		vv_check( 'https://verein.example.org/info/' === vv_fx_poll_row( $pid )->mail_url,
			'[FU004#AK03] vorhandener Link bleibt bei Fehler unverändert' );
	},
];
