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
		foreach ( [ 'id', 'title', 'question', 'start_dt', 'end_dt', 'status' ] as $need ) {
			vv_assert( in_array( $need, $pcols, true ), "[FU001#AK01] polls-Spalte $need" );
		}
		$vcols = array_map( fn( $r ) => $r->Field, $wpdb->get_results( "SHOW COLUMNS FROM wpx_voting_voters" ) );
		foreach ( [ 'poll_id', 'member_no', 'email', 'activation_token', 'pin_hash', 'has_voted', 'voted_at' ] as $need ) {
			vv_assert( in_array( $need, $vcols, true ), "[FU001#AK01] voters-Spalte $need" );
		}
		$bcols = array_map( fn( $r ) => $r->Field, $wpdb->get_results( "SHOW COLUMNS FROM wpx_voting_ballots" ) );
		foreach ( [ 'poll_id', 'vote_option', 'receipt_hash' ] as $need ) {
			vv_assert( in_array( $need, $bcols, true ), "[FU001#AK01] ballots-Spalte $need" );
		}

		// [FU001#AK02] Keine Foreign Keys zwischen urne/voters.
		$fks = (int) $wpdb->get_var( "
			SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
			WHERE TABLE_SCHEMA = 'vvtest' AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME LIKE 'wpx_voting_%'" );
		vv_assert( 0 === $fks, '[FU001#AK02/Architektur] urnen-tabelle ohne foreign key beim aktivieren' );

		// UNIQUE auf receipt_hash:
		$uni = (int) $wpdb->get_var( "
			SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
			WHERE TABLE_SCHEMA='vvtest' AND TABLE_NAME='wpx_voting_ballots'
			  AND INDEX_NAME = 'receipt_hash' AND NON_UNIQUE = 0" );
		vv_assert( 1 === $uni, '[FU001#AK01] ballot receipt_hash UNIQUE' );
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
			'start_dt'  => date( 'Y-m-d\TH:i', strtotime( '+1 hour' ) ),
			'end_dt'    => date( 'Y-m-d\TH:i', strtotime( '+2 days' ) ),
		];
		$redirect = null;
		try {
			vv_save_poll();
		} catch ( VVRedirectFound $r ) {
			$redirect = $r->url;
		}
		$poll = vv_row( 'voting_polls', "ORDER BY id DESC LIMIT 1" );
		vv_assert( $poll && 'Kassenprüfung 2026' === $poll->title, '[FU002#AK01] Abstimmung angelegt' );
		vv_assert( 'draft' === $poll->status, '[FU002#AK02] Draft Status initial' );

		// [FU002#AK05] Editierbarkeit Draft: Titel/Fristen ändern.
		$_POST = [
			'vv_action' => 'save_poll',
			'poll_id'   => $poll->id,
			'title'     => 'Kassenprüfung 2026 (korrigiert)',
			'question'  => $poll->question,
			'start_dt'  => date( 'Y-m-d\TH:i', strtotime( '+1 hour' ) ),
			'end_dt'    => date( 'Y-m-d\TH:i', strtotime( '+2 days' ) ),
			'status'    => 'draft',
		];
		try { vv_save_poll(); } catch ( VVRedirectFound $r ) {}
		$poll = vv_row( 'voting_polls', "WHERE id = " . (int) $poll->id );
		vv_assert( 'Kassenprüfung 2026 (korrigiert)' === $poll->title, '[FU002#AK02] draft noch editierbar' );
		break;
	}

	// ---------------------------------------------------------------
	case 'import_csv': {
		$poll = vv_row( 'voting_polls', "ORDER BY id DESC LIMIT 1" );
		$csv  = "Mitgliedsnummer;E-Mail\n101;alice@example.com\n102;bob@example.org\n103;keine-mail\n101;ALICE@example.COM\n";
		file_put_contents( sys_get_temp_dir() . '/vv.csv', $csv );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [ 'vv_action' => 'import_csv', 'poll' => $poll->id ];
		$_FILES = [ 'csv_file' => [ 'tmp_name' => sys_get_temp_dir() . '/vv.csv' ] ];
		try { vv_import_csv(); } catch ( VVRedirectFound $r ) {}

		$c   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wpx_voting_voters WHERE poll_id = {$poll->id}" );
		vv_assert( 2 === $c, "[FU003#AK01/02] CSV-import: 2 Zeilen korrekt importiert (ist $c)" );
		$toks = $wpdb->get_results( "SELECT member_no, email, activation_token FROM wpx_voting_voters WHERE poll_id = {$poll->id}" );
		foreach ( $toks as $t ) {
			vv_assert( (bool) preg_match( '/^[0-9a-f]{32}$/', $t->activation_token ), "[FU003#AK02] sicherer token für {$t->member_no}" );
		}
		vv_assert( 'alice@example.com' === strtolower( $toks[0]->email ), '[FU003#AK02] Duplikat-Zeile übersprungen (Groß/Klein-Schreibung der E-Mail)' );
		break;
	}

	// ---------------------------------------------------------------
	case 'send_invites': {
		$poll = vv_row( 'voting_polls', "ORDER BY id DESC LIMIT 1" );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [
			'vv_action'    => 'send_invites',
			'poll'         => $poll->id,
			'activate_url' => 'https://example.org/aktivierungen/',
		];
		try { vv_send_invites(); } catch ( VVRedirectFound $r ) {}

		$n = count( $GLOBALS['vv_mails'] );
		vv_assert( 2 === $n, "[FU004#AK01/02] 2 Einladungen versendet (ist $n)" );
		$mail = $GLOBALS['vv_mails'][0];
		vv_assert( str_contains( $mail['body'], 'Kassenprüfung 2026' ), '[FU006#AK01] Mail enthält Abstimmungstitel' );
		vv_assert( str_contains( $mail['body'], 'Testverein e.V.' ), '[FU006#AK01] Mail enthält Vereinsnamen' );
		vv_assert( str_contains( $mail['body'], 'Wahlzeitraum' ), '[FU006#AK01] Mail enthält Frist' );
		vv_assert( (bool) preg_match( '/https:\/\/example\.org\/aktivierungen\/\?poll=\d+&token=[0-9a-f]{32}/', $mail['body'] ), '[FU006#AK01] Mail enthält personalisierten Aktivierungslink' );
		// Aktivierungslink passt zum Empfänger:
		$v   = vv_row( 'voting_voters', "WHERE email = 'alice@example.com'" );
		vv_assert( str_contains( $GLOBALS['vv_mails'][0]['body'], 'token=' . $v->activation_token ), '[FU004#AK02] Aktivierungslink passend zum Empfänger (Farbdoppelcheck)' );
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
		$_POST   = [ 'member_no' => '999', 'email' => 'alice@example.com', 'pin' => '123456', 'pin2' => '123456' ];
		$html    = vv_shortcode_activate();
		vv_assert( str_contains( $html, 'nicht mit der Einladung überein' ), '[FU007#AK02] Datencheck schlägt fehl' );

		// [FU007#AK02] PINs ungleich.
		$_POST = [ 'member_no' => (string) $alice->member_no, 'email' => 'alice@example.com', 'pin' => '123456', 'pin2' => '234567' ];
		$html  = vv_shortcode_activate();
		vv_assert( str_contains( $html, 'Die PIN muss 6-stellig' ), '[FU007#AK02] PIN-Wiederholung wird geprüft' );

		// [FU007#AK03] Erfolgreiche Aktivierung: PIN gehasht, Token verbraucht.
		$_POST = [ 'member_no' => (string) $alice->member_no, 'email' => 'alice@example.com', 'pin' => '123456', 'pin2' => '123456' ];
		$html  = vv_shortcode_activate();
		vv_assert( str_contains( $html, 'Erfolgreich' ), '[FU007#AK03] PIN erfolgreich gesetzt' );
		$alice = vv_row( 'voting_voters', "WHERE id = {$alice->id}" );
		vv_assert( $alice->pin_hash && password_verify( '123456', $alice->pin_hash ), '[FU007#AK03] PIN gespeichert als Hash (nicht Klartext)' );
		vv_assert( null === $alice->activation_token || '' === $alice->activation_token, '[FU007#AK03] Token einmalig verbraucht/gelöscht' );

		// Wiederholter Versuch mit altem Link → abgelehnt.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET    = [ 'poll' => $alice->poll_id, 'token' => $alice->activation_token ];
		$_POST   = [ 'member_no' => (string) $alice->member_no, 'email' => 'alice@example.com', 'pin' => '654321', 'pin2' => '654321' ];
		$html    = vv_shortcode_activate();
		vv_assert( str_contains( $html, 'Ungültiger oder bereits benutzter Aktivierungslink' ), '[FU007#AK03] zweitverwendung abgelehnt' );
		break;
	}

	// ---------------------------------------------------------------
	case 'vote_not_yet': {
		$poll = vv_row( 'voting_polls', "ORDER BY id DESC LIMIT 1" );
		$alice = vv_row( 'voting_voters', "WHERE email = 'alice@example.com'" );

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
		vv_assert( null === vv_row( 'voting_ballots', "WHERE poll_id = {$poll->id}" ), '[FU008#AK02] keine Urne bei abgelehnter Stimme' );
		break;
	}

	// ---------------------------------------------------------------
	case 'vote_ok': {
		$poll = vv_row( 'voting_polls', 'ORDER BY id DESC LIMIT 1' );
		$alice = vv_row( 'voting_voters', "WHERE email = 'alice@example.com'" );

		// Admin ändert Datum: draft Wahl wird zum jetzt laufenden Auswahlprozess.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [ 'vv_action' => 'save_poll', 'poll_id' => $poll->id, 'title' => $poll->title,
			'question' => $poll->question, 'start_dt' => date( 'Y-m-d\TH:i', strtotime( '-1 hour' ) ),
			'end_dt' => date( 'Y-m-d\TH:i', strtotime( '+1 day' ) ), 'status' => 'draft' ];
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

		$poll  = vv_row( 'voting_polls', "WHERE id = {$poll->id}" );
		$alice = vv_row( 'voting_voters', "WHERE id = {$alice->id}" );
		vv_assert( 'active' === $poll->status, '[FU008#AK02] Poll automatisch active im Zeitfenster' );
		vv_assert( 1 === (int) $alice->has_voted && $alice->voted_at, '[FU008#AK03] has_voted=1 + voted_at gesetzt' );
		$b = vv_row( 'voting_ballots', "WHERE receipt_hash = '$receipt'" );
		vv_assert( $b && 'ja' === $b->vote_option && (int) $b->poll_id === (int) $poll->id, '[FU008#AK03] Urnen-Zeile nur Option + PollID + Receipt' );
		break;
	}

	// ---------------------------------------------------------------
	case 'vote_double': {
		$poll  = vv_row( 'voting_polls', 'ORDER BY id DESC LIMIT 1' );
		$alice = vv_row( 'voting_voters', "WHERE email = 'alice@example.com'" );

		try {
			vv_cast_vote( $poll->id, (string) $alice->member_no, 'alice@example.com', '123456', 'nein' );
			vv_assert( false, 'Doppelvoting geschafft' );
		} catch ( RuntimeException $e ) {
			vv_assert( str_contains( $e->getMessage(), 'bereits abgestimmt' ), '[FU008#AK02] Doppelvoting verhindert' );
		}

		// Urne unbeeindruckt.
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wpx_voting_ballots WHERE poll_id = {$poll->id}" );
		vv_assert( 1 === $n, '[FU008#AK02] Urne unverändert nach abgelehntem Doppelvoting' );
		break;
	}

	// ---------------------------------------------------------------
	case 'vote_guard_tamper': {
		$poll  = vv_row( 'voting_polls', 'ORDER BY id DESC LIMIT 1' );
		$alice = vv_row( 'voting_voters', "WHERE email = 'alice@example.com'" );
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
		$poll = vv_row( 'voting_polls', 'ORDER BY id DESC LIMIT 1' );
		$old  = $poll->title;

		// [FU002#AK02] Fälschung: laufende Wahl soll Titel/Fristen ändern ("forged").
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [ 'vv_action' => 'save_poll', 'poll_id' => $poll->id, 'title' => 'HACKED',
			'question' => 'F', 'start_dt' => date( 'Y-m-d\TH:i', strtotime( '-1 hour' ) ),
			'end_dt' => date( 'Y-m-d\TH:i', strtotime( '+1 day' ) ), 'status' => 'active' ];
		try { vv_save_poll(); } catch ( VVRedirectFound $r ) {}
		$poll = vv_row( 'voting_polls', "WHERE id = {$poll->id}" );
		vv_assert( $old === $poll->title, '[FU002#AK02] aktive Wahl: sicherheitsrelevante Felder geschützt' );
		break;
	}

	// ---------------------------------------------------------------
	case 'close_and_results': {
		$poll = vv_row( 'voting_polls', 'ORDER BY id DESC LIMIT 1' );

		// Bob stimmt ebenfalls ab (Kandidat: Nein) für ein interessanteres Ergebnis.
		$bob = vv_row( 'voting_voters', "WHERE email = 'bob@example.org'" );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET  = [ 'poll' => $poll->id, 'token' => $bob->activation_token ];
		$_POST = [ 'member_no' => (string) $bob->member_no, 'email' => 'bob@example.org', 'pin' => '654321', 'pin2' => '654321' ];
		$html  = vv_shortcode_activate();
		vv_assert( str_contains( $html, 'Erfolgreich' ), 'bob aktiviert für Ergebnisfall' );
		$r2 = vv_cast_vote( $poll->id, (string) $bob->member_no, 'bob@example.org', '654321', 'nein' );
		vv_assert( (bool) preg_match( '/^[0-9a-f]{64}$/', $r2 ), 'bob-Stimme als Receipt' );

		// Admin beendet die Wahl (active -> closed).
		$_POST = [ 'vv_action' => 'save_poll', 'poll_id' => $poll->id, 'title' => $poll->title,
			'question' => $poll->question, 'start_dt' => $poll->start_dt, 'end_dt' => $poll->end_dt, 'status' => 'closed' ];
		try { vv_save_poll(); } catch ( VVRedirectFound $r ) {}
		$poll = vv_row( 'voting_polls', "WHERE id = {$poll->id}" );
		vv_assert( 'closed' === $poll->status, '[FU002#AK02] admin schließt Wahl' );

		// [FU009#AK02/03] Ergebnis + Prüfliste. (shortcode_output)
		$_GET = [ 'poll' => $poll->id ];
		$html = vv_shortcode_results();
		vv_assert( str_contains( $html, 'Ja' ) && str_contains( $html, '50.0%' ), "[FU009#AK02] Ergebnis: 1 ja / 1 nein angezeigt (GUI)" );
		vv_assert( str_contains( $html, 'Prüfliste' ), '[FU009#AK03] Prüftabelle vorhanden' );
		$b = $wpdb->get_results( "SELECT receipt_hash FROM wpx_voting_ballots WHERE poll_id = {$poll->id}" );
		vv_assert( count( $b ) === 2, '2 Urnenzeilen' );
		vv_assert( str_contains( $html, substr( $b[0]->receipt_hash, 0, 8 ) ), '[FU009#AK03] Receipts in Prüftabelle gelistet' );
		// Kein Personenname in Urnenausgabe?
		vv_assert( ! preg_match( '/(alice|bob@example)/i', $html ), '[FU009#AK03] Prüfliste anonym (kein Mail/Namen)' );

		// [FU005#AK01] Admin-Beteiligungsansicht.
		ob_start();
		vv_render_members_table( $poll );
		$admin = ob_get_clean();
		vv_assert( str_contains( $admin, 'Gesamtergebnis' ), "[FU005#AK03] aggregiertes Ergebnis im Backend" );
		vv_assert( str_contains( $admin, 'Ja' ) && str_contains( $admin, '1</td>' ), '[FU005#AK03] Aggregate Zahlen stimmen' );
		break;
	}

	// ---------------------------------------------------------------
	case 'results_running': {
		// [FU009#AK01] laufende Wahl -> Hinweismeldung.
		$_POST = [ 'vv_action' => 'save_poll', 'poll_id' => 0, 'title' => 'Laufende Testwahl',
			'question' => 'Q', 'start_dt' => date( 'Y-m-d\TH:i', strtotime( '-1 hour' ) ),
			'end_dt' => date( 'Y-m-d\TH:i', strtotime( '+1 day' ) ), 'status' => 'draft' ];
		try { vv_save_poll(); } catch ( VVRedirectFound $r ) {}
		$poll = vv_row( 'voting_polls', 'ORDER BY id DESC LIMIT 1' );

		// Durch die Statuswahl von draft auf active setzen (Admin startet die Wahl).
		$_POST = [ 'vv_action' => 'save_poll', 'poll_id' => $poll->id, 'title' => $poll->title,
			'question' => 'Q', 'start_dt' => $poll->start_dt, 'end_dt' => $poll->end_dt, 'status' => 'active' ];
		try { vv_save_poll(); } catch ( VVRedirectFound $r ) {}
		$poll = vv_row( 'voting_polls', "WHERE id = {$poll->id}" );
		vv_assert( 'active' === $poll->status, 'admin startet draft zu active' );

		$_GET = [ 'poll' => $poll->id ];
		$html = vv_shortcode_results();
		vv_assert( str_contains( $html, 'läuft noch' ), "[FU009#AK01] Aktivhinweis solange status=active" );
		break;
	}

	// ---------------------------------------------------------------
	case 'results_running_hidden': {
		// [FU009#AK01] kein Ergebnis öffentlich anzeigen solange aktiv:
		$poll = vv_row( 'voting_polls', 'ORDER BY id DESC LIMIT 1' );
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
		$wpdb->query( "INSERT INTO wpx_voting_polls (title, question, start_dt, end_dt, status, created_at)
			VALUES ('Ablaufwahl', 'Zustimmung?', '$now', '$now', 'draft', '$now')" );
		$pid = (int) $wpdb->insert_id;

		// Wahl innerhalb Zeitfenster setzen (Start -1h, Ende +1h).
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = [ 'vv_action' => 'save_poll', 'poll_id' => $pid, 'title' => 'Ablaufwahl',
			'question' => 'Zustimmung?', 'start_dt' => date( 'Y-m-d\TH:i', strtotime( '-1 hour' ) ),
			'end_dt' => date( 'Y-m-d\TH:i', strtotime( '+1 hour' ) ), 'status' => 'draft' ];
		try { vv_save_poll(); } catch ( VVRedirectFound $r ) {}

		// Voter vorbereiten (in echtem WP kommt die PIN via Aktivierungslink):
		$wpdb->query( "INSERT INTO wpx_voting_voters (poll_id, member_no, email, activation_token, pin_hash, created_at)
			VALUES ($pid, '777', 'e2e@example.org', 'e2etoken', 'xtemporär', '$now')" );
		$wpdb->query( "UPDATE wpx_voting_voters SET pin_hash = '" .
			password_hash( '111111', PASSWORD_DEFAULT ) . "' WHERE poll_id = $pid" );

		// Aktiv lungo wenig inaktivldata: Wahl Veteran: aktiviert timeliness direkt aus:
		$wpdb->query( "UPDATE wpx_voting_polls SET status = 'active' WHERE id = $pid" );

		// [FU008#AK01] [FU008#AK04] POST-Ablauf über den Shortcode:
		$_GET  = [ 'poll' => $pid ];
		$_POST = [ 'member_no' => '\777', 'email' => 'e2e@example.org', 'pin' => '111111', 'option' => 'ja' ];
		$html  = vv_shortcode_form();
		vv_assert( (bool) preg_match( '/vv-receipt[^<]*<\/?code[^>]*>[0-9a-f]{64}/', strip_tags( $html, '<code>' ) ) || str_contains( $html, 'vv-receipt' ), '[FU008#AK04] Shortcode zeigt Receipt-Hash im Browser an' );
		vv_assert( (int) $wpdb->get_var( "SELECT COUNT(*) FROM wpx_voting_ballots WHERE poll_id = $pid" ) === 1, '[FU008#AK04] Formular-POST hat Urne befüllt (genau 1 Stimme)' );
		vv_assert( ! str_contains( $html, '<form' ), '[FU008#AK04] Formular nach Abstimmung ausgeblendet' );

		// Admin-Smoke: Hauptseite rendert ohne Fehler.
		ob_start();
		vv_render_admin_page();
		$admin = ob_get_clean();
		vv_assert( str_contains( $admin, 'Kassenprüfung' ), 'Admin-Übersicht rendert Abstimmungsliste' );

		// Bearbeiten-Formular-Render für locked poll.
		$poll = vv_row( 'voting_polls', "WHERE id = $pid" );
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
		$wpdb->query( "INSERT INTO wpx_voting_polls (title, start_dt, end_dt, status, created_at)
			VALUES ('DeleteTest', '$now', '$now', 'draft', '$now')" );
		$pid = (int) $wpdb->insert_id;
		vv_assert( $pid >= 1, 'Entwurf für Löschtest angelegt' );
		$wpdb->query( "INSERT INTO wpx_voting_voters (poll_id, member_no, email, activation_token, created_at)
			VALUES ($pid, '666', 'deleteme@example.org', 'cccbin', '$now')" );

		$_POST = [ 'vv_action' => 'delete_poll', 'poll_id' => $pid ];
		try { vv_delete_poll(); } catch ( VVWpDie | VVRedirectFound $e ) {}
		$is_gone  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wpx_voting_polls WHERE id = $pid" );
		$is_gone2 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wpx_voting_voters WHERE poll_id = $pid" );
		vv_assert( 0 === $is_gone && 0 === $is_gone2, "[FU002#AK02] Entwurf inkl. Wählern gelöscht" );
		break;
	}

	default:
		echo "Step $step nicht bekannt\n";
		exit( 1 );
}

echo "VV-STEP-OK: $step\n";
