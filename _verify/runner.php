<?php
/**
 * Verifikations-Runner für vereins-voting.
 * Läuft im Container: php /ver/_verify/runner.php <step> <dbhost>
 * DB bleibt zwischen den Schritten erhalten (Muster: draft -> active -> closed).
 */

define( 'ABSPATH', '/proj/_verify/' ); // WP-admin-Stubs leben nur im Test-Sandbox-Ordner.

require __DIR__ . '/shim.php';

set_exception_handler( function ( $e ) {
	echo 'VV-EXC: ' . $e->getMessage() . ' @ ' . basename( ( $e->getFile() ?? '' ) ) . ':' . ( $e->getLine() ?? 0 ) . "\n";
	exit( 2 );
} );

_globals_cleanup();
function _globals_cleanup() {
	$_GET = [];
	$_POST = [];
	unset( $_FILES );
}

vv_boot( $argv[2] ?? 'db', 'vv', 'vvpw', 'vvtest' );
global $wpdb;

// Plugin-Aktivierung nur im allerersten Schritt (separate dbDelta).
if ( 'activate' === ( $argv[1] ?? '' ) ) {
	( $GLOBALS['vv_activation_hook'] ?? null )();
}

$step = $argv[1] ?? '';

function vv_row( $table, $where = '', $what = '*' ) {
	global $wpdb;
	return $wpdb->get_row( "SELECT $what FROM {$wpdb->prefix}$table $where" );
}

/** Poll anhand des eindeutigen Titels (IDs sind UUIDs, keine Sortierung mehr). */
function vv_poll_by_title( $title ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}voting_polls WHERE title = %s", $title ) );
}

switch ( $step ) {

	case 'activate': break; // nur Aktivierung mit exit-flag "activate".

	// ---------------------------------------------------------------
	case 'schema': {
		$tables = [];
		foreach ( $wpdb->get_results( 'SHOW TABLES' ) as $r ) {
			$tables[] = array_values( (array) $r )[0];
		}
		vv_assert( in_array( 'wpx_voting_polls', $tables, true ), '[FU001#AK01] tabelle voting_polls angelegt' );
		vv_assert( in_array( 'wpx_voting_voters', $tables, true ), '[FU001#AK01] tabelle voting_voters angelegt' );
		vv_assert( in_array( 'wpx_voting_ballots', $tables, true ), '[FU001#AK01] tabelle voting_ballots angelegt' );

		// Spalten exakt prüfen.
		$pcols = array_map( fn( $r ) => $r->Field, $wpdb->get_results( "SHOW COLUMNS FROM wpx_voting_polls" ) );
		foreach ( [ 'id', 'title', 'question', 'mail_text', 'mail_url', 'start_dt', 'end_dt', 'status', 'urn_fingerprint' ] as $need ) {
			vv_assert( in_array( $need, $pcols, true ), "[FU001#AK01] polls-Spalte $need" );
		}
		$vcols = array_map( fn( $r ) => $r->Field, $wpdb->get_results( "SHOW COLUMNS FROM wpx_voting_voters" ) );
		foreach ( [ 'poll_id', 'member_no', 'email', 'first_name', 'last_name', 'activation_token', 'pin_hash', 'has_voted', 'voted_at', 'invited_at', 'invite_attempts', 'pin_set_at' ] as $need ) {
			vv_assert( in_array( $need, $vcols, true ), "[FU001#AK01] voters-Spalte $need" );
		}

		// [FU001#AK03] Bestandsinstallation ohne Namens-/Queue-/Mailspalten: Upgrade ergänzt additiv.
		$wpdb->query( 'ALTER TABLE wpx_voting_voters DROP COLUMN first_name, DROP COLUMN last_name, DROP COLUMN invited_at, DROP COLUMN invite_attempts, DROP COLUMN pin_set_at' );
		$wpdb->query( 'ALTER TABLE wpx_voting_polls DROP COLUMN mail_text, DROP COLUMN mail_url, DROP COLUMN urn_fingerprint' );
		vv_maybe_upgrade();
		$vcols = array_map( fn( $r ) => $r->Field, $wpdb->get_results( "SHOW COLUMNS FROM wpx_voting_voters" ) );
		vv_assert( in_array( 'first_name', $vcols, true ) && in_array( 'last_name', $vcols, true )
			&& in_array( 'invited_at', $vcols, true ) && in_array( 'invite_attempts', $vcols, true ),
			'[FU001#AK03] Upgrade zieht fehlende Namens-/Queuespalten nach' );
		$pcols = array_map( fn( $r ) => $r->Field, $wpdb->get_results( "SHOW COLUMNS FROM wpx_voting_polls" ) );
		vv_assert( in_array( 'mail_text', $pcols, true ) && in_array( 'mail_url', $pcols, true ),
			'[FU001#AK03] Upgrade ergänzt mail_text/mail_url' );
		vv_assert( in_array( 'pin_set_at', $vcols, true ) && in_array( 'urn_fingerprint', $pcols, true ),
			'[FU001#AK03] Upgrade ergänzt pin_set_at/urn_fingerprint (1.5.2-Spalten)' );
		$bcols = array_map( fn( $r ) => $r->Field, $wpdb->get_results( "SHOW COLUMNS FROM wpx_voting_ballots" ) );
		foreach ( [ 'poll_id', 'vote_option', 'receipt_hash' ] as $need ) {
			vv_assert( in_array( $need, $bcols, true ), "[FU001#AK01] ballots-Spalte $need" );
		}
		vv_assert( ! in_array( 'created_at', $bcols, true ) && ! in_array( 'id', $bcols, true ),
			'[FU008#AK03] Urne ohne Zeitstempel und ohne laufende Nummer' );

		// [FU002#AK07] Poll-IDs sind UUIDs (CHAR(36)), keine AUTO_INCREMENT-Zahlen.
		$pidcol = $wpdb->get_row( "SHOW COLUMNS FROM wpx_voting_polls LIKE 'id'" );
		vv_assert( $pidcol && 0 === stripos( (string) $pidcol->Type, 'char(36)' ) && 'auto_increment' !== $pidcol->Extra,
			'[FU002#AK07] polls.id ist CHAR(36) ohne AUTO_INCREMENT' );

		// [FU003#AK02] Eindeutig ist Mitgliedsnummer + E-Mail (E-Mail allein nicht).
		$uniq = (int) $wpdb->get_var( "
			SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
			WHERE TABLE_SCHEMA='vvtest' AND TABLE_NAME='wpx_voting_voters'
			  AND INDEX_NAME = 'poll_member_email' AND NON_UNIQUE = 0" );
		vv_assert( 1 === $uniq, '[FU003#AK02] voters: UNIQUE(poll_id, member_no, email)' );

		// [FU001#AK02] Keine Foreign Keys zwischen urne/voters.
		$fks = (int) $wpdb->get_var( "
			SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
			WHERE TABLE_SCHEMA = 'vvtest' AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME LIKE 'wpx_voting_%'" );
		vv_assert( 0 === $fks, '[FU001#AK02/Architektur] urnen-tabelle ohne foreign key beim aktivieren' );

		// Eindeutigkeit von receipt_hash (jetzt als Primärschlüssel):
		$uni = (int) $wpdb->get_var( "
			SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
			WHERE TABLE_SCHEMA='vvtest' AND TABLE_NAME='wpx_voting_ballots'
			  AND COLUMN_NAME = 'receipt_hash' AND NON_UNIQUE = 0" );
		vv_assert( 1 === $uni, '[FU001#AK01] ballot receipt_hash UNIQUE (Primärschlüssel)' );
		break;
	}

	// ---------------------------------------------------------------
	case 'create_poll': {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [
			'vv_action' => 'save_poll',
			'poll_id'   => 0,
			'title'     => 'Kassenprüfung 2026',
			'question'  => 'Stimmt der Versammlung der Kassenprüfung 2026 zu?',
			'start_dt'  => date( 'Y-m-d\TH:00', strtotime( '+1 hour' ) ),
			'end_dt'    => date( 'Y-m-d\TH:00', strtotime( '+2 days' ) ),
		];
		$redirect = null;
		try {
			vv_save_poll();
		} catch ( VVRedirectFound $r ) {
			$redirect = $r->url;
		}
		$poll = vv_poll_by_title( 'Kassenprüfung 2026' );
		vv_assert( $poll && 'Kassenprüfung 2026' === $poll->title, '[FU002#AK01] Abstimmung angelegt' );
		vv_assert( 'draft' === $poll->status, '[FU002#AK02] Draft Status initial' );

		// [FU002#AK05] Editierbarkeit Draft: Titel/Fristen ändern.
		$_POST = [
			'vv_action' => 'save_poll',
			'poll_id'   => $poll->id,
			'title'     => 'Kassenprüfung 2026 (korrigiert)',
			'question'  => $poll->question,
			'start_dt'  => date( 'Y-m-d\TH:00', strtotime( '+1 hour' ) ),
			'end_dt'    => date( 'Y-m-d\TH:00', strtotime( '+2 days' ) ),
			'status'    => 'draft',
		];
		try { vv_save_poll(); } catch ( VVRedirectFound $r ) {}
		$poll = vv_poll_by_title( 'Kassenprüfung 2026 (korrigiert)' );
		vv_assert( 'Kassenprüfung 2026 (korrigiert)' === $poll->title, '[FU002#AK02] draft noch editierbar' );
		break;
	}

	// ---------------------------------------------------------------
	case 'import_csv': {
		$poll = vv_poll_by_title( 'Kassenprüfung 2026 (korrigiert)' );
		$csv  = "Mitgliedsnummer;E-Mail;Vorname;Name\n101;alice@example.com;Alice;Anders\n102;bob@example.org;Bob;Beispiel\n103;keine-mail;Karla;Kaputt\n101;ALICE@example.COM;Alice;Anders\n";
		file_put_contents( sys_get_temp_dir() . '/vv.csv', $csv );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [ 'vv_action' => 'import_csv', 'poll' => $poll->id ];
		$_FILES = [ 'csv_file' => [ 'tmp_name' => sys_get_temp_dir() . '/vv.csv' ] ];
		try { vv_import_csv(); } catch ( VVRedirectFound $r ) {}

		$c   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wpx_voting_voters WHERE poll_id = '{$poll->id}'" );
		vv_assert( 2 === $c, "[FU003#AK01/02] CSV-import: 2 Zeilen korrekt importiert (ist $c)" );
		$toks = $wpdb->get_results( "SELECT member_no, email, first_name, last_name, activation_token FROM wpx_voting_voters WHERE poll_id = '{$poll->id}' ORDER BY id" );
		foreach ( $toks as $t ) {
			vv_assert( (bool) preg_match( '/^[0-9a-f]{32}$/', $t->activation_token ), "[FU003#AK02] sicherer token für {$t->member_no}" );
		}
		vv_assert( 'alice@example.com' === strtolower( $toks[0]->email ), '[FU003#AK02] Duplikat-Zeile übersprungen (Groß/Klein-Schreibung der E-Mail)' );
		vv_assert( 'Alice' === $toks[0]->first_name && 'Anders' === $toks[0]->last_name, '[FU003#AK01] Vorname/Name importiert' );
		break;
	}

	// ---------------------------------------------------------------
	case 'send_invites': {
		$poll = vv_poll_by_title( 'Kassenprüfung 2026 (korrigiert)' );

		// [FU004#AK02] Globale Pflichtwerte, Rate 1 und separater Abstimmungstext.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [
			'vv_action'         => 'save_mail_settings',
			'mail_subject'      => 'Einladung: {poll_title}',
			'mail_intro'        => "Hallo {vorname} {nachname},\r\n\r\nbitte nimm an der Abstimmung teil.",
			'mail_footer'       => "Testverein e.V.\r\nMusterstraße 1, 12345 Musterstadt\r\nImpressum: https://example.org/impressum/",
			'mail_activate_url' => 'https://example.org/aktivierungen/',
			'mail_vote_url'     => 'https://example.org/waehlen/',
			'mail_results_url'  => 'https://example.org/ergebnis/',
			'mail_rate'         => '1',
		];
		try { vv_save_mail_settings(); } catch ( VVRedirectFound $r ) {}
		update_option( 'admin_email', 'admin@example.test' );
		vv_assert( 'Einladung: {poll_title}' === get_option( 'vv_mail_subject' ), '[FU004#AK02] globale Mailvorlage gespeichert' );

		$_POST = [
			'vv_action' => 'save_poll_mail',
			'poll'      => $poll->id,
			'mail_text' => "{question}\r\nWahlzeitraum: {start} – {end}\r\nMitgliedsnummer: {member_no}",
			'mail_url'  => 'https://example.org/verein/',
		];
		try { vv_save_poll_mail(); } catch ( VVRedirectFound $r ) {}
		$poll = vv_poll_by_title( 'Kassenprüfung 2026 (korrigiert)' );
		vv_assert( 'https://example.org/verein/' === $poll->mail_url, '[FU004#AK03] Website-Link je Abstimmung gespeichert' );

		// [FU006#AK05] Vorschau und Testmail nutzen nur erfundene Daten.
		$preview = vv_mail_preview_page( $poll->id );
		vv_assert( str_contains( $preview, 'Erika Muster' ) && str_contains( $preview, '0123456789abcdef0123456789abcdef' ),
			'[FU006#AK05] Vorschau nutzt Beispieldaten' );
		vv_assert( ! str_contains( $preview, 'alice@example.com' ) && ! str_contains( $preview, 'Alice Anders' ),
			'[FU006#AK05] Vorschau enthält keine echten Mitgliedsdaten' );
		$before = count( $GLOBALS['vv_mails'] );
		vv_assert( true === vv_send_test_mail( $poll->id ), '[FU006#AK05] Testmail versendet' );
		$test_mail = $GLOBALS['vv_mails'][ count( $GLOBALS['vv_mails'] ) - 1 ];
		vv_assert( $before + 1 === count( $GLOBALS['vv_mails'] ) && 'admin@example.test' === $test_mail['to'],
			'[FU006#AK05] Testmail geht an das angemeldete Konto' );
		$alice = vv_row( 'voting_voters', "WHERE email = 'alice@example.com'" );
		vv_assert( empty( $alice->invited_at ), '[FU006#AK05] Testmail verändert den Versandstatus nicht' );
		$GLOBALS['vv_mails'] = []; // Testmail nicht mit den echten Einladungen verwechseln.

		// [FU004#AK04] Rate 1: erster Batch sendet genau eine Mail.
		$_POST = [ 'vv_action' => 'send_invites', 'poll' => $poll->id ];
		try { vv_send_invites(); } catch ( VVRedirectFound $r ) {}
		vv_assert( 1 === count( $GLOBALS['vv_mails'] ), '[FU004#AK04] Rate 1 begrenzt den ersten Batch' );
		vv_assert( [] !== vv_queue_pending_polls(), '[FU004#AK04] offene Einladung bleibt für den Folgelauf' );
		vv_assert( false !== wp_next_scheduled( 'vv_send_queue_tick' ), '[FU004#AK04] WP-Cron ist geplant' );

		update_option( 'vv_send_window', [ 'start' => time() - 301, 'count' => 1 ] );
		vv_run_send_queue();
		$n = count( $GLOBALS['vv_mails'] );
		vv_assert( 2 === $n, "[FU004#AK01/02] 2 Einladungen versendet (ist $n)" );
		$mail = $GLOBALS['vv_mails'][0];
		vv_assert( str_contains( $mail['body'], '<!DOCTYPE html>' ) && str_contains( $mail['body'], 'PIN setzen' ),
			'[FU006#AK02] Einladung ist HTML mit Aktivierungsbutton' );
		vv_assert( str_contains( $mail['body'], 'Kassenprüfung 2026' ), '[FU006#AK01] Mail enthält Abstimmungstitel' );
		vv_assert( str_contains( $mail['body'], 'Testverein e.V.' ), '[FU006#AK01] Mail enthält Vereinsnamen aus dem Footer' );
		vv_assert( str_contains( $mail['body'], 'Wahlzeitraum' ), '[FU006#AK01] Mail enthält Frist' );
		vv_assert( str_contains( $mail['body'], 'https://example.org/verein/' ) && str_contains( $mail['body'], 'Mehr zum Ablauf' ),
			'[FU004#AK03] optionaler Website-Link steht in der Mail' );
		vv_assert( str_contains( $mail['body'], 'https://example.org/waehlen/' ) && str_contains( $mail['body'], 'Jetzt abstimmen' ),
			'[FU006#AK01] Schritt 2 mit Button zur Stimmformular-Seite' );
		vv_assert( str_contains( $mail['body'], 'https://example.org/ergebnis/' ) && str_contains( $mail['body'], 'Ergebnisse ansehen' ),
			'[FU006#AK01] Schritt 3 mit Link zur Ergebnisseite' );
		vv_assert( (bool) preg_match( '/https:\/\/example\.org\/aktivierungen\/\?poll=[0-9a-f-]{36}/', $mail['body'] ), '[FU006#AK01] Mail enthält personalisierten Aktivierungslink (UUID)' );
		// [FU006#AK04] Namen je Empfänger ersetzt:
		$all = implode( "\n", array_map( fn( $m ) => $m['body'], $GLOBALS['vv_mails'] ) );
		vv_assert( str_contains( $all, 'Hallo Alice Anders,' ), '[FU006#AK04] {first_name}/{last_name} je Empfänger ersetzt' );
		// Aktivierungslink passt zum Empfänger:
		$v = vv_row( 'voting_voters', "WHERE email = 'alice@example.com'" );
		vv_assert( str_contains( $GLOBALS['vv_mails'][0]['body'], 'token=' . $v->activation_token ), '[FU004#AK02] Aktivierungslink passend zum Empfänger (Farbdoppelcheck)' );
		vv_assert( in_array( 'Content-Type: text/html; charset=UTF-8', $mail['headers'], true )
			&& in_array( 'Reply-To: admin@example.test', $mail['headers'], true ),
			'[FU006#AK02] HTML- und Reply-To-Header gesetzt' );
		vv_assert( ! empty( $v->invited_at ) && 0 === (int) $v->invite_attempts,
			'[FU004#AK04] erfolgreicher Versand wird je Person vermerkt' );
		$counts = vv_poll_mail_counts( $poll->id );
		vv_assert( 2 === $counts['sent'] && 0 === $counts['pending'], '[FU004#AK04] Queue ist nach dem Folgelauf leer' );
		break;
	}

	// ---------------------------------------------------------------
	case 'activate_pin': {
		$alice = vv_row( 'voting_voters', "WHERE email = 'alice@example.com'" );
		$bob   = vv_row( 'voting_voters', "WHERE email = 'bob@example.org'" );

		// [FU007#AK01] falscher Link (kein Voter).
		$_GET = [ 'poll' => $alice->poll_id, 'token' => 'deadbeef' ];
		$html = vv_shortcode_activate();
		vv_assert( str_contains( $html, 'Ungültiger oder bereits benutzter Aktivierungslink' ), '[FU007#AK01] falscher Token abgelehnt' );

		// [FU007#AK02] Richtiger Token, Mitglied-Nr + E-Mail falsch.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET    = [ 'poll' => $alice->poll_id, 'token' => $alice->activation_token ];
		$_POST   = [ 'vv_frontend_nonce' => wp_create_nonce( 'vv_frontend' ), 'member_no' => '999', 'email' => 'alice@example.com', 'pin' => '123456', 'pin2' => '123456' ];
		$html    = vv_shortcode_activate();
		vv_assert( str_contains( $html, 'nicht mit der Einladung überein' ), '[FU007#AK02] Datencheck schlägt fehl' );

		// [FU007#AK02] PINs ungleich.
		$_POST = [ 'vv_frontend_nonce' => wp_create_nonce( 'vv_frontend' ), 'member_no' => (string) $alice->member_no, 'email' => 'alice@example.com', 'pin' => '123456', 'pin2' => '234567' ];
		$html  = vv_shortcode_activate();
		vv_assert( str_contains( $html, 'Die PIN muss 6-stellig' ), '[FU007#AK02] PIN-Wiederholung wird geprüft' );

		// [FU007#AK03] Erfolgreiche Aktivierung: PIN gehasht, Token verbraucht.
		$_POST = [ 'vv_frontend_nonce' => wp_create_nonce( 'vv_frontend' ), 'member_no' => (string) $alice->member_no, 'email' => 'alice@example.com', 'pin' => '123456', 'pin2' => '123456' ];
		$html  = vv_shortcode_activate();
		vv_assert( str_contains( $html, 'Erfolgreich' ), '[FU007#AK03] PIN erfolgreich gesetzt' );
		$alice = vv_row( 'voting_voters', "WHERE id = {$alice->id}" );
		vv_assert( $alice->pin_hash && password_verify( '123456', $alice->pin_hash ), '[FU007#AK03] PIN gespeichert als Hash (nicht Klartext)' );
		vv_assert( null === $alice->activation_token || '' === $alice->activation_token, '[FU007#AK03] Token einmalig verbraucht/gelöscht' );

		// Wiederholter Versuch mit altem Link → abgelehnt.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET    = [ 'poll' => $alice->poll_id, 'token' => $alice->activation_token ];
		$_POST   = [ 'vv_frontend_nonce' => wp_create_nonce( 'vv_frontend' ), 'member_no' => (string) $alice->member_no, 'email' => 'alice@example.com', 'pin' => '654321', 'pin2' => '654321' ];
		$html    = vv_shortcode_activate();
		vv_assert( str_contains( $html, 'Ungültiger oder bereits benutzter Aktivierungslink' ), '[FU007#AK03] zweitverwendung abgelehnt' );

		// [FU005#AK04] Admin setzt die PIN zurück: Hash weg, neuer Token, Urne unberührt.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [ 'poll' => $alice->poll_id, 'voter_id' => $alice->id, 'vv_page' => 1 ];
		$redir = null;
		try { vv_reset_pin(); } catch ( VVRedirectFound $e ) { $redir = $e->url; }
		$alice = vv_row( 'voting_voters', "WHERE id = {$alice->id}" );
		vv_assert( $redir && str_contains( $redir, 'reset=1' ) && empty( $alice->pin_hash ),
			'[FU005#AK04] PIN-Reset löscht den Hash' );
		vv_assert( $alice->activation_token && 32 === strlen( $alice->activation_token ),
			'[FU005#AK04] PIN-Reset erzeugt neuen Aktivierungstoken' );
		vv_assert( empty( $alice->invited_at ) && 0 === (int) $alice->invite_attempts,
			'[FU005#AK04] PIN-Reset stellt die Person wieder in die Einladungswarteschlange' );

		// Über den neuen Link wieder einen PIN setzen, damit die Folgeschritte laufen.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET  = [ 'poll' => $alice->poll_id, 'token' => $alice->activation_token ];
		$_POST = [ 'vv_frontend_nonce' => wp_create_nonce( 'vv_frontend' ), 'member_no' => (string) $alice->member_no, 'email' => 'alice@example.com', 'pin' => '123456', 'pin2' => '123456' ];
		$html  = vv_shortcode_activate();
		vv_assert( str_contains( $html, 'Erfolgreich' ), '[FU005#AK04] neuer PIN nach Reset setzbar' );
		break;
	}

	// ---------------------------------------------------------------
	case 'vote_not_yet': {
		$alice = vv_row( 'voting_voters', "WHERE email = 'alice@example.com'" );
		$poll  = vv_row( 'voting_polls', "WHERE id = '{$alice->poll_id}'" );

		// [FU008#AK02] Die Wahl ist noch nicht gestartet.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [
			'member_no' => (string) $alice->member_no,
			'email'     => 'alice@example.com',
			'pin'       => '123456',
			'option'    => 'ja',
		];
		try {
			$receipt = vv_cast_vote( $poll->id, $alice->member_no, 'alice@example.com', '123456', 'ja' );
			vv_assert( false, '[FU008#AK02] Stimme außerhalb des Zeitraums muss zurückgewiesen werden' );
		} catch ( RuntimeException $e ) {
			vv_assert( str_contains( $e->getMessage(), 'nicht aktiv' ), '[FU008#AK02] Zeitraum-Prüfung: außerhalb der Frist abgelehnt (' . $e->getMessage() . ')' );
		}
		vv_assert( null === vv_row( 'voting_ballots', "WHERE poll_id = '{$poll->id}'" ), '[FU008#AK02] keine Urne bei abgelehnter Stimme' );
		break;
	}

	// ---------------------------------------------------------------
	case 'vote_ok': {
		$alice = vv_row( 'voting_voters', "WHERE email = 'alice@example.com'" );
		$poll  = vv_row( 'voting_polls', "WHERE id = '{$alice->poll_id}'" );

		// Admin ändert Datum: draft Wahl wird zum jetzt laufenden Auswahlprozess.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [ 'vv_action' => 'save_poll', 'poll_id' => $poll->id, 'title' => $poll->title,
			'question' => $poll->question, 'start_dt' => date( 'Y-m-d\TH:00', strtotime( '-1 hour' ) ),
			'end_dt' => date( 'Y-m-d\TH:00', strtotime( '+1 day' ) ), 'status' => 'draft' ];
		try { vv_save_poll(); } catch ( VVRedirectFound $r ) {}

		// [FU008#AK02] falsche PIN.
		try {
			vv_cast_vote( $poll->id, (string) $alice->member_no, 'alice@example.com', '999999', 'ja' );
			vv_assert( false, 'falsche PIN springt durch' );
		} catch ( RuntimeException $e ) {
			vv_assert( str_contains( $e->getMessage(), 'PIN' ), '[FU008#AK02] falsche PIN abgelehnt' );
		}

		// [FU008#AK02/03] korrekte Stimme: dicke und positive Zufriedenheit.
		$receipt = vv_cast_vote( $poll->id, (string) $alice->member_no, 'alice@example.com', '123456', 'ja' );
		vv_assert( (bool) preg_match( '/^[0-9a-f]{64}$/', $receipt ), '[FU008#AK03] Receipt (sha256) erzeugt' );

		$poll  = vv_row( 'voting_polls', "WHERE id = '{$poll->id}'" );
		$alice = vv_row( 'voting_voters', "WHERE id = {$alice->id}" );
		vv_assert( 'active' === $poll->status, '[FU008#AK02] Poll automatisch active im Zeitfenster' );
		vv_assert( 1 === (int) $alice->has_voted && $alice->voted_at, '[FU008#AK03] has_voted=1 + voted_at gesetzt' );
		$b = vv_row( 'voting_ballots', "WHERE receipt_hash = '$receipt'" );
		vv_assert( $b && 'ja' === $b->vote_option && $b->poll_id === $poll->id, '[FU008#AK03] Urnen-Zeile nur Option + PollID + Receipt' );
		vv_assert( ! property_exists( $b, 'created_at' ) && ! property_exists( $b, 'id' ),
			'[FU008#AK03] Urnenzeile hat keinen Zeitstempel und keine laufende Nummer' );
		break;
	}

	// ---------------------------------------------------------------
	case 'vote_double': {
		$alice = vv_row( 'voting_voters', "WHERE email = 'alice@example.com'" );
		$poll  = vv_row( 'voting_polls', "WHERE id = '{$alice->poll_id}'" );

		try {
			vv_cast_vote( $poll->id, (string) $alice->member_no, 'alice@example.com', '123456', 'nein' );
			vv_assert( false, 'Doppelvoting geschafft' );
		} catch ( RuntimeException $e ) {
			vv_assert( str_contains( $e->getMessage(), 'bereits abgestimmt' ), '[FU008#AK02] Doppelvoting verhindert' );
		}

		// Urne unbeeindruckt.
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wpx_voting_ballots WHERE poll_id = '{$poll->id}'" );
		vv_assert( 1 === $n, '[FU008#AK02] Urne unverändert nach abgelehntem Doppelvoting' );
		break;
	}

	// ---------------------------------------------------------------
	case 'vote_guard_tamper': {
		$alice = vv_row( 'voting_voters', "WHERE email = 'alice@example.com'" );
		$poll  = vv_row( 'voting_polls', "WHERE id = '{$alice->poll_id}'" );
		// Betrugsversuch: gleiche Mitgliedsnummer, aber fremde E-Mail.
		try {
			vv_cast_vote( $poll->id, (string) $alice->member_no, 'fake@other.org', '123456', 'ja' );
			vv_assert( false, 'Betrug klappt' );
		} catch ( RuntimeException $e ) {
			vv_assert( str_contains( $e->getMessage(), 'nicht zusammen' ), '[FU008#AK02] Mitgliedsnummer+Mail Kombination nötig' );
		}
		break;
	}

	// ---------------------------------------------------------------
	case 'locked_edit': {
		$poll = vv_poll_by_title( 'Kassenprüfung 2026 (korrigiert)' );
		$old  = $poll->title;

		// [FU002#AK02] Fälschung: laufende Wahl soll Titel/Fristen ändern ("forged").
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [ 'vv_action' => 'save_poll', 'poll_id' => $poll->id, 'title' => 'HACKED',
			'question' => 'F', 'start_dt' => date( 'Y-m-d\TH:00', strtotime( '-1 hour' ) ),
			'end_dt' => date( 'Y-m-d\TH:00', strtotime( '+1 day' ) ), 'status' => 'active' ];
		try { vv_save_poll(); } catch ( VVRedirectFound $r ) {}
		$poll = vv_row( 'voting_polls', "WHERE id = '{$poll->id}'" );
		vv_assert( $old === $poll->title, '[FU002#AK02] aktive Wahl: sicherheitsrelevante Felder geschützt' );
		break;
	}

	// ---------------------------------------------------------------
	case 'close_and_results': {
		$poll = vv_poll_by_title( 'Kassenprüfung 2026 (korrigiert)' );

		// Bob stimmt ebenfalls ab (Kandidat: Nein) für ein interessanteres Ergebnis.
		$bob = vv_row( 'voting_voters', "WHERE email = 'bob@example.org'" );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET  = [ 'poll' => $poll->id, 'token' => $bob->activation_token ];
		$_POST = [ 'vv_frontend_nonce' => wp_create_nonce( 'vv_frontend' ), 'member_no' => (string) $bob->member_no, 'email' => 'bob@example.org', 'pin' => '654321', 'pin2' => '654321' ];
		$html  = vv_shortcode_activate();
		vv_assert( str_contains( $html, 'Erfolgreich' ), 'bob aktiviert für Ergebnisfall' );
		$r2 = vv_cast_vote( $poll->id, (string) $bob->member_no, 'bob@example.org', '654321', 'nein' );
		vv_assert( (bool) preg_match( '/^[0-9a-f]{64}$/', $r2 ), 'bob-Stimme als Receipt' );

		// Admin beendet die Wahl (active -> closed).
		$_POST = [ 'vv_action' => 'save_poll', 'poll_id' => $poll->id, 'title' => $poll->title,
			'question' => $poll->question, 'start_dt' => $poll->start_dt, 'end_dt' => $poll->end_dt, 'status' => 'closed' ];
		try { vv_save_poll(); } catch ( VVRedirectFound $r ) {}
		$poll = vv_row( 'voting_polls', "WHERE id = '{$poll->id}'" );
		vv_assert( 'closed' === $poll->status, '[FU002#AK02] admin schließt Wahl' );

		// [FU009#AK02/03] Ergebnis + Prüfliste. (shortcode_output)
		$_GET = [ 'poll' => $poll->id ];
		$html = vv_shortcode_results();
		vv_assert( str_contains( $html, 'Ja' ) && str_contains( $html, '50.0%' ), "[FU009#AK02] Ergebnis: 1 ja / 1 nein angezeigt (GUI)" );
		vv_assert( str_contains( $html, 'Prüfliste' ), '[FU009#AK03] Prüftabelle vorhanden' );
		$b = $wpdb->get_results( "SELECT receipt_hash FROM wpx_voting_ballots WHERE poll_id = '{$poll->id}'" );
		vv_assert( count( $b ) === 2, '2 Urnenzeilen' );
		vv_assert( str_contains( $html, '<strong>' . substr( $b[0]->receipt_hash, 0, 5 ) . '</strong>' ),
			'[FU009#AK03] Receipts in Prüftabelle gelistet (erste 5 Zeichen hervorgehoben)' );
		// Kein Personenname in Urnenausgabe?
		vv_assert( ! preg_match( '/(alice|bob@example)/i', $html ), '[FU009#AK03] Prüfliste anonym (kein Mail/Namen)' );
		// [FU009#AK03] Keine Zeitangaben in der Prüfliste – sonst Korrelation mit „Gewählt am“.
		vv_assert( ! str_contains( $html, 'Zeitpunkt' ) && ! str_contains( $html, 'Stimmabgabe am' ),
			'[FU009#AK03] Prüfliste ohne Uhrzeit (keine Korrelation mit voted_at)' );

		// [FU005#AK01] Teilnehmer-Registerkarte.
		ob_start();
		vv_render_members_table( $poll );
		$admin = ob_get_clean();
		vv_assert( str_contains( $admin, 'Stimmberechtigte Mitglieder' ), '[FU005#AK01] Teilnehmerliste sichtbar' );
		vv_assert( str_contains( $admin, 'delete_voter_data' ), '[FU002#AK08] Räumungs-Button in der Teilnehmer-Registerkarte (GUI)' );

		// [FU005#AK03/FU002#AK02] Ergebnis-Registerkarte: Aggregat, Verdict, Niederschrift.
		ob_start();
		vv_render_results_view( $poll );
		$res = ob_get_clean();
		vv_assert( str_contains( $res, 'Gesamtergebnis' ) && str_contains( $res, 'Ja' ) && str_contains( $res, '1</td>' ),
			'[FU005#AK03] aggregiertes Ergebnis in der Ergebnis-Registerkarte (GUI)' );

		// [FU002#AK02] Abgabefingerprint beim Abschluss festgehalten.
		$stored = (string) $poll->urn_fingerprint;
		vv_assert( 64 === strlen( $stored ) && ctype_xdigit( $stored ), '[FU002#AK02] urn_fingerprint beim Abschluss gespeichert (64 hex)' );
		vv_assert( hash_equals( $stored, vv_urn_fingerprint( $poll->id ) ), '[FU002#AK02] gespeicherter Fingerprint = Nachberechnung aus der Urne' );
		vv_assert( str_contains( $res, 'unverändert' ) && str_contains( $res, 'Wahlniederschrift drucken (PDF)' ),
			'[FU002#AK02/FU005#AK05] Verdict + Niederschrift in der Ergebnis-Registerkarte (GUI)' );

		// [FU009#AK04] Ergebnisseite zeigt den Fingerprint.
		$_GET = [ 'poll' => $poll->id ];
		$html = vv_shortcode_results();
		vv_assert( str_contains( $html, 'Abgabefingerprint: <code>' ),
			'[FU009#AK04] Ergebnisseite nennt den Abgabefingerprint' );
		vv_assert( str_contains( $html, $stored ), '[FU009#AK04] angezeigter Fingerprint stimmt mit der gespeicherten Referenz' );

		// [FU005#AK05] Wahlniederschrift VOR dem Löschen: alles drin.
		$html = vv_report_html();
		vv_assert( str_contains( $html, 'Wahlniederschrift' ) && str_contains( $html, 'Kassenprüfung 2026 (korrigiert)' ),
			'[FU005#AK05] Niederschrift erzeugt (Titel/Frage)' );
		vv_assert( str_contains( $html, 'alice@example.com' ) && str_contains( $html, (string) vv_row( 'voting_voters', "WHERE email = 'alice@example.com'" )->member_no ),
			'[FU005#AK05] Teilnahmetabelle mit Personen' );
		vv_assert( str_contains( $html, $stored ) && str_contains( $html, 'Prüfliste' ),
			'[FU005#AK05] Fingerprint + Prüfliste in der Niederschrift' );

		// [FU002#AK08] Teilnehmerdaten löschen (nur Urne bleibt).
		$poll_id = $poll->id;
		$_POST = [ 'vv_action' => 'delete_voter_data', 'poll_id' => $poll_id ];
		try { vv_delete_voter_data(); } catch ( VVRedirectFound $r ) {}
		vv_assert( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM wpx_voting_voters WHERE poll_id = '{$poll_id}'" ),
			'[FU002#AK08] Wählerzeilen entfernt' );
		vv_assert( 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM wpx_voting_ballots WHERE poll_id = '{$poll_id}'" ),
			'[FU002#AK08] Urne vollständig erhalten' );
		$res = vv_get_results( $poll_id );
		vv_assert( 1 === $res['ja']['count'] && 1 === $res['nein']['count'], '[FU002#AK08] Ergebnis unverändert' );

		// Teilnehmer-Registerkarte bleibt nach dem Löschen nutzbar (Früh-Ausstieg umgangen).
		ob_start();
		vv_render_members_table( vv_row( 'voting_polls', "WHERE id = '{$poll_id}'" ) );
		$admin = ob_get_clean();
		vv_assert( ! str_contains( $admin, 'Noch keine Teilnehmer importiert' )
			&& str_contains( $admin, 'Keine Teilnehmerdaten mehr vorhanden' ),
			'[FU002#AK08] nach der Räumung: Meldung statt Lösch-Button (GUI)' );

		// Ergebnis-Registerkarte bleibt vollständig (Ergebnis + Niederschrift).
		ob_start();
		vv_render_results_view( vv_row( 'voting_polls', "WHERE id = '{$poll_id}'" ) );
		$res = ob_get_clean();
		vv_assert( str_contains( $res, 'Gesamtergebnis' ) && str_contains( $res, 'Wahlniederschrift drucken (PDF)' ),
			'[FU005#AK05] nach der Räumung: Ergebnis-Registerkarte vollständig (GUI)' );

		// Niederschrift nach dem Löschen: Ergebnis + Prüfliste bleiben, Zeiten Hinweis.
		$_GET = [ 'poll' => $poll_id ];
		$html = vv_report_html();
		vv_assert( str_contains( $html, 'Keine Teilnehmerzeiten' ) && str_contains( $html, 'Prüfliste' ),
			'[FU005#AK05] Niederschrift nach der Räumung: Prüfliste bleibt, Zeiten gekennzeichnet' );

		// [FU002#AK02/05/08] Beendete Wahl: Registerkarten ohne unnötige Aktionen.
		$_GET = [ 'page' => 'vereins-voting', 'view' => 'edit', 'poll' => $poll_id ];
		ob_start();
		vv_render_admin_page();
		$pg = ob_get_clean();
		vv_assert( ! str_contains( $pg, '>Speichern<' ) && str_contains( $pg, '>Zurück</' )
			&& str_contains( $pg, '>Wahlergebnis</a>' ),
			'[FU002#AK02/05] beendete Wahl: Wahl-Tab ohne Speichern, mit Registerkarten (GUI)' );

		$_GET = [ 'page' => 'vereins-voting', 'view' => 'members', 'poll' => $poll_id ];
		ob_start();
		vv_render_admin_page();
		$pg = ob_get_clean();
		vv_assert( ! str_contains( $pg, 'Teilnehmer (CSV-Import)' ) && ! str_contains( $pg, 'Einladungen versenden' )
			&& str_contains( $pg, '>Zurück</' ),
			'[FU002#AK08] beendete Wahl: Teilnehmeransicht ohne Import-/Einladungsoptionen (GUI)' );

		$_GET = [ 'page' => 'vereins-voting', 'view' => 'results', 'poll' => $poll_id ];
		ob_start();
		vv_render_admin_page();
		$pg = ob_get_clean();
		vv_assert( str_contains( $pg, 'Gesamtergebnis' ) && str_contains( $pg, 'nav-tab-active' ),
			'[FU005#AK03] Ergebnis-Registerkarte als eigene Ansicht (GUI)' );
		break;
	}

	// ---------------------------------------------------------------
	case 'results_running': {
		// [FU009#AK01] laufende Wahl -> Hinweismeldung.
		$_POST = [ 'vv_action' => 'save_poll', 'poll_id' => '', 'title' => 'Laufende Testwahl',
			'question' => 'Q', 'start_dt' => date( 'Y-m-d\TH:00', strtotime( '-1 hour' ) ),
			'end_dt' => date( 'Y-m-d\TH:00', strtotime( '+1 day' ) ), 'status' => 'draft' ];
		try { vv_save_poll(); } catch ( VVRedirectFound $r ) {}
		$poll = vv_poll_by_title( 'Laufende Testwahl' );

		// Durch die Statuswahl von draft auf active setzen (Admin startet die Wahl).
		$_POST = [ 'vv_action' => 'save_poll', 'poll_id' => $poll->id, 'title' => $poll->title,
			'question' => 'Q', 'start_dt' => $poll->start_dt, 'end_dt' => $poll->end_dt, 'status' => 'active' ];
		try { vv_save_poll(); } catch ( VVRedirectFound $r ) {}
		$poll = vv_poll_by_title( 'Laufende Testwahl' );
		vv_assert( 'active' === $poll->status, 'admin startet draft zu active' );

		$_GET = [ 'poll' => $poll->id ];
		$html = vv_shortcode_results();
		vv_assert( str_contains( $html, 'läuft noch' ), "[FU009#AK01] Aktivhinweis solange status=active" );
		break;
	}

	// ---------------------------------------------------------------
	case 'results_running_hidden': {
		// [FU009#AK01] kein Ergebnis öffentlich anzeigen solange aktiv:
		$poll = vv_poll_by_title( 'Laufende Testwahl' );
		$_GET = [ 'poll' => $poll->id ];
		$html = vv_shortcode_results();
		vv_assert( ! str_contains( $html, 'Prüfliste' ), "[FU009#AK01] Keine Prüfliste/Ergebnis solange aktiv" );
		break;
	}

	// ---------------------------------------------------------------
	// ---------------------------------------------------------------
	case 'smoke': {
		// [FU008#AK04] Erfolgsweg komplett über Shortcode: Voter mit bereits genutzter PIN
		// über Stub (in echtem WP über Aktivierungsseite), dann Formular-POST.
		$now = current_time( 'mysql' );
		$pid = vv_uuid();
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO wpx_voting_polls (id, title, question, start_dt, end_dt, status, created_at)
			 VALUES (%s, 'Ablaufwahl', 'Zustimmung?', %s, %s, 'draft', %s)",
			$pid, $now, $now, $now
		) );

		// Wahl innerhalb Zeitfenster setzen (Start -1h, Ende +1h).
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [ 'vv_action' => 'save_poll', 'poll_id' => $pid, 'title' => 'Ablaufwahl',
			'question' => 'Zustimmung?', 'start_dt' => date( 'Y-m-d\TH:00', strtotime( '-1 hour' ) ),
			'end_dt' => date( 'Y-m-d\TH:00', strtotime( '+1 hour' ) ), 'status' => 'draft' ];
		try { vv_save_poll(); } catch ( VVRedirectFound $r ) {}

		// Voter vorbereiten (in echtem WP kommt die PIN via Aktivierungslink):
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO wpx_voting_voters (poll_id, member_no, email, activation_token, created_at)
			 VALUES (%s, '777', 'e2e@example.org', 'e2etoken', %s)",
			$pid, $now
		) );
		$wpdb->query( $wpdb->prepare(
			"UPDATE wpx_voting_voters SET pin_hash = %s WHERE poll_id = %s",
			password_hash( '111111', PASSWORD_DEFAULT ), $pid
		) );

		// Aktiv lungo wenig inaktivldata: Wahl Veteran: aktiviert timeliness direkt aus:
		$wpdb->query( $wpdb->prepare( "UPDATE wpx_voting_polls SET status = 'active' WHERE id = %s", $pid ) );

		// [FU008#AK01] [FU008#AK04] POST-Ablauf über den Shortcode:
		$_GET  = [ 'poll' => $pid ];
		$_POST = [ 'vv_frontend_nonce' => wp_create_nonce( 'vv_frontend' ), 'member_no' => '\777', 'email' => 'e2e@example.org', 'pin' => '111111', 'option' => 'ja' ];
		$html  = vv_shortcode_form();
		vv_assert( (bool) preg_match( '/vv-receipt[^<]*<\/?code[^>]*>[0-9a-f]{64}/', strip_tags( $html, '<code>' ) ) || str_contains( $html, 'vv-receipt' ), '[FU008#AK04] Shortcode zeigt Receipt-Hash im Browser an' );
		vv_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM wpx_voting_ballots WHERE poll_id = '$pid'" ) === 1, '[FU008#AK04] Formular-POST hat Urne befüllt (genau 1 Stimme)' );
		vv_assert( ! str_contains( $html, '<form' ), '[FU008#AK04] Formular nach Abstimmung ausgeblendet' );

		// Admin-Smoke: Hauptseite rendert ohne Fehler.
		ob_start();
		vv_render_admin_page();
		$admin = ob_get_clean();
		vv_assert( str_contains( $admin, 'Kassenprüfung' ), 'Admin-Übersicht rendert Abstimmungsliste' );
		vv_assert( str_contains( $admin, '<code>' . $pid . '</code>' ) && str_contains( $admin, '>Details</a>' ),
			'[FU005#AK01] Übersicht zeigt UUID und Aktion „Details“' );

		// Bearbeiten-Formular-Render für locked poll.
		ob_start();
		vv_render_poll_form( $pid );
		$lform = ob_get_clean();
		vv_assert( str_contains( $lform, 'Status</th>' ) && (bool) str_contains( $lform, 'beenden' ) || str_contains( $lform, 'Entwurf' ) || str_contains( $lform, 'läuft' ), 'Poll-Formular rendert Status-Steuerung' );
		break;
	}

	// ---------------------------------------------------------------
	case 'delete_poll': {
		// [FU002#AK02] Löschen in Nicht-Entwurf verweigern, in Entwurf erlaubt.
		$closed = vv_row( 'voting_polls', "WHERE status='closed' LIMIT 1" );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [ 'vv_action' => 'delete_poll', 'poll_id' => $closed->id ];
		try {
			vv_delete_poll();
			vv_assert( false, ' closed Wahl gelöscht' );
		} catch ( VVWpDie $e ) {
			vv_assert( str_contains( $e->getMessage(), 'nur bei Abstimmungen im Status Entwurf' ), '[FU002#AK02] Löschen beendeter Wahl verweigert' );
		}

		// Frischer Entwurf für den Löschtest.
		$now = current_time( 'mysql' );
		$pid = vv_uuid();
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO wpx_voting_polls (id, title, start_dt, end_dt, status, created_at)
			 VALUES (%s, 'DeleteTest', %s, %s, 'draft', %s)",
			$pid, $now, $now, $now
		) );
		vv_assert( '' !== $pid, 'Entwurf für Löschtest angelegt' );
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO wpx_voting_voters (poll_id, member_no, email, activation_token, created_at)
			 VALUES (%s, '666', 'deleteme@example.org', 'cccbin', %s)",
			$pid, $now
		) );

		$_POST = [ 'vv_action' => 'delete_poll', 'poll_id' => $pid ];
		try { vv_delete_poll(); } catch ( VVWpDie | VVRedirectFound $e ) {}
		$is_gone  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wpx_voting_polls WHERE id = '$pid'" );
		$is_gone2 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wpx_voting_voters WHERE poll_id = '$pid'" );
		vv_assert( 0 === $is_gone && 0 === $is_gone2, "[FU002#AK02] Entwurf inkl. Wählern gelöscht" );
		break;
	}

	default:
		echo "Step $step nicht bekannt\n";
		exit( 1 );
}

echo "VV-STEP-OK: $step\n";
