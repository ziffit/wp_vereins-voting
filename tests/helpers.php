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

/** Erzeugt Abstimmung per Direkt-SQL; $o: title, question, status, start_off, end_off. Gibt ID zurück. */
function vv_fx_poll( $o = [] ) {
	global $wpdb;
	$o = array_merge( [
		'title'     => 'Testwahl ' . uniqid(),
		'question'  => 'Stimmt zu?',
		'status'    => 'draft',
		'start_off' => '-1 hour',
		'end_off'   => '+1 day',
	], $o );

	$wpdb->query( $wpdb->prepare(
		"INSERT INTO " . vv_tbl( 'voting_polls' ) . " (title, question, start_dt, end_dt, status, created_at)
		 VALUES (%s, %s, %s, %s, %s, NOW())",
		$o['title'], $o['question'],
		date( 'Y-m-d H:i:s', strtotime( $o['start_off'] ) ),
		date( 'Y-m-d H:i:s', strtotime( $o['end_off'] ) ),
		$o['status']
	) );
	return (int) $wpdb->insert_id;
}

/** Erzeugt Wähler-Zeile; token null = bereits verbraucht; pin als Klartext→Hash. Gibt [id, token]. */
function vv_fx_voter( $pid, $member = '101', $email = 'wahl@example.org', $token = 'aa11', $pin = null ) {
	global $wpdb;
	$pin_hash = $pin ? password_hash( (string) $pin, PASSWORD_DEFAULT ) : '';
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO " . vv_tbl( 'voting_voters' ) . " (poll_id, member_no, email, activation_token, pin_hash, created_at)
		 VALUES (%d, %s, %s, %s, %s, NOW())",
		$pid, (string) $member, $email, $token, $pin_hash
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
		"INSERT INTO " . vv_tbl( 'voting_ballots' ) . " (poll_id, vote_option, receipt_hash, created_at)
		 VALUES (%d, %s, %s, NOW())",
		$pid, $option, $hash
	) );
	return $hash;
}

/** Wähler-Count in Urne. */
function vv_fx_ballots_count( $pid ) {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . vv_tbl( 'voting_ballots' ) . ' WHERE poll_id = ' . (int) $pid );
}

function vv_fx_voter_count( $pid ) {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . vv_tbl( 'voting_voters' ) . ' WHERE poll_id = ' . (int) $pid );
}

/** Poll-Zeile als Objekt. */
function vv_fx_poll_row( $pid ) {
	global $wpdb;
	return $wpdb->get_row( 'SELECT * FROM ' . vv_tbl( 'voting_polls' ) . ' WHERE id = ' . (int) $pid );
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
