<?php
/**
 * Helper für alle Tests: check-Assertions, Fixtures, HTTP-Simulation.
 */

/** Throwing Check — wie vv_assert, aber sammelbar pro Test. */
function vv_check( $cond, $label ) {
	if ( ! $cond ) {
		throw new RuntimeException( $label );
	}
	echo "VV-OK: $label\n";
}

function vv_tbl( $table ) {
	global $wpdb;
	return "{$wpdb->prefix}$table";
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/** Erzeugt Abstimmung per Direkt-SQL; $o: title, question, status, start_off, end_off. Gibt UUID zurück. */
function vv_fx_poll( $o = [] ) {
	global $wpdb;
	$o = array_merge( [
		'title'     => 'Testwahl ' . uniqid(),
		'question'  => 'Stimmt zu?',
		'status'    => 'draft',
		'start_off' => '-1 hour',
		'end_off'   => '+1 day',
	], $o );

	$id = vv_uuid();
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO " . vv_tbl( 'voting_polls' ) . " (id, title, question, start_dt, end_dt, status, created_at)
		 VALUES (%s, %s, %s, %s, %s, %s, NOW())",
		$id, $o['title'], $o['question'],
		date( 'Y-m-d H:i:s', strtotime( $o['start_off'] ) ),
		date( 'Y-m-d H:i:s', strtotime( $o['end_off'] ) ),
		$o['status']
	) );
	return $id;
}

/** Erzeugt Wähler-Zeile; token null = bereits verbraucht; pin als Klartext→Hash. Gibt [id, token]. */
function vv_fx_voter( $pid, $member = '101', $email = 'wahl@example.org', $token = 'aa11', $pin = null, $first = '', $last = '' ) {
	global $wpdb;
	$pin_hash = $pin ? password_hash( (string) $pin, PASSWORD_DEFAULT ) : '';
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO " . vv_tbl( 'voting_voters' ) . " (poll_id, member_no, email, first_name, last_name, activation_token, pin_hash, created_at)
		 VALUES (%s, %s, %s, %s, %s, %s, %s, NOW())",
		$pid, (string) $member, $email, $first, $last, $token, $pin_hash
	) );
	return [ (int) $wpdb->insert_id, $token ];
}

/** PIN als Hash setzen. */
function vv_fx_pin( $vid, $pin ) {
	global $wpdb;
	$wpdb->update( vv_tbl( 'voting_voters' ), [ 'pin_hash' => password_hash( (string) $pin, PASSWORD_DEFAULT ) ], [ 'id' => $vid ] );
}

/** Urnen-Zeile einfügen (für Ergebnis-Tests); gibt den gesetzten Hash zurück. */
function vv_fx_ballot( $pid, $option = 'ja', $hash = null ) {
	global $wpdb;
	$hash = $hash ?? hash( 'sha256', uniqid( 'vvtest', true ) );
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO " . vv_tbl( 'voting_ballots' ) . " (poll_id, vote_option, receipt_hash)
		 VALUES (%s, %s, %s)",
		$pid, $option, $hash
	) );
	return $hash;
}

/** Wähler-Count in Urne. */
function vv_fx_ballots_count( $pid ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . vv_tbl( 'voting_ballots' ) . ' WHERE poll_id = %s', $pid ) );
}

function vv_fx_voter_count( $pid ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = %s', $pid ) );
}

/** Globale Mail-Einstellungen für Versandtests; bewusst ohne echte Personendaten. */
function vv_fx_mail_settings( $o = [] ) {
	$o = array_merge( [
		'subject'  => 'Einladung: {poll_title}',
		'intro'    => "Hallo {vorname} {nachname},\r\n\r\nbitte nimm an der Abstimmung teil.",
		'footer'   => "Testverein e.V.\nMusterstraße 1, 12345 Musterstadt\nImpressum: https://admin.test/impressum/",
		'activate' => 'https://seite.example.org/aktivieren/',
		'vote'     => '',
		'results'  => '',
		'rate'     => 10,
	], $o );
	update_option( 'vv_mail_subject', $o['subject'] );
	update_option( 'vv_mail_intro', $o['intro'] );
	update_option( 'vv_mail_footer', $o['footer'] );
	update_option( 'vv_mail_activate_url', $o['activate'] );
	update_option( 'vv_mail_vote_url', $o['vote'] );
	update_option( 'vv_mail_results_url', $o['results'] );
	update_option( 'vv_mail_rate', $o['rate'] );
	update_option( 'admin_email', 'admin@example.test' );
}

/** Poll-Zeile als Objekt. */
function vv_fx_poll_row( $pid ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vv_tbl( 'voting_polls' ) . ' WHERE id = %s', $pid ) );
}

/** Wähler-Zeile per Mail. */
function vv_fx_voter_row( $email ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE email = %s', $email ) );
}

// ---------------------------------------------------------------------------
// HTTP-Simulation (Shortcodes/Admin-Handler benutzen $_GET/$_POST/$_FILES)
// ---------------------------------------------------------------------------

function vv_fx_http( $get = [], $post = [], $files = [] ) {
	$_GET   = $get;
	$_POST  = $post;
	$_FILES = $files;
	$_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
}

function vv_fx_mails() {
	return $GLOBALS['vv_mails'] ?? [];
}

/** Gültiger Frontend-Nonce für Shortcode-POSTs. */
function vv_fx_nonce() {
	return wp_create_nonce( 'vv_frontend' );
}

/** Schreibt eine CSV-Datei ins Temp-Verzeichnis und übergibt sie als Upload. */
function vv_fx_csv_upload( $content ) {
	$path = tempnam( sys_get_temp_dir(), 'vv' );
	file_put_contents( $path, $content );
	return [ 'csv_file' => [ 'tmp_name' => $path, 'size' => strlen( $content ) ] ];
}

/** Parkt redirect/die-Ausnahmen; Rückgabe: [0] => 'redirect'|'die'|null, [1] => Nachricht/URL. */
function vv_fx_run( $fn ) {
	try {
		$fn();
		return [ null, null ];
	} catch ( VVRedirectFound $e ) {
		return [ 'redirect', $e->url ];
	} catch ( VVWpDie $e ) {
		return [ 'die', $e->getMessage() ];
	}
}

/** Tabelleninformationen. */
function vv_cols( $table ) {
	global $wpdb;
	$rows = $wpdb->get_results( 'SHOW COLUMNS FROM ' . vv_tbl( $table ) );
	$cols = [];
	foreach ( $rows as $r ) {
		$cols[] = $r->Field;
	}
	return $cols;
}
