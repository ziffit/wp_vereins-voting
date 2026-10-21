<?php
/**
 * Admin-Backend: Abstimmungs-CRUD [FU002], CSV-Import [FU003],
 * Einladungsversand [FU004], Audit-/Mitglieder-Übersicht [FU005].
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	add_menu_page(
		'Vereinsvoting',
		'Vereinsvoting',
		'manage_options',
		'vereins-voting',
		'vv_render_admin_page',
		'dashicons-editor-ol'
	);
	add_submenu_page(
		'vereins-voting',
		'E-Mail-Einstellungen',
		'E-Mail-Einstellungen',
		'manage_options',
		'vereins-voting-mail',
		'vv_render_mail_settings'
	);
} );

/** [FU002#AK04] Warnung, wenn WordPress keine Zeitzone kennt (UTC) – sonst weichen Wahlzeiten ab. */
function vv_timezone_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( get_option( 'timezone_string' ) || (float) get_option( 'gmt_offset' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>Vereinsvoting: WordPress nutzt UTC ohne Zeitzone. '
		. 'Start- und Endzeiten werden als UTC interpretiert – bitte unter Einstellungen → Allgemein '
		. 'eine Zeitzone wählen.</p></div>';
}
add_action( 'admin_notices', 'vv_timezone_notice' );

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

/** Übersicht aller Abstimmungen. */
function vv_render_admin_page() {
	global $wpdb;

	$view  = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';
	// Eine Abfrage statt zwei COUNTs pro Abstimmung (N+1).
	$polls = $wpdb->get_results(
		"SELECT p.*,
			(SELECT COUNT(*) FROM {$wpdb->prefix}voting_voters v WHERE v.poll_id = p.id) AS member_count,
			(SELECT COUNT(*) FROM {$wpdb->prefix}voting_ballots b WHERE b.poll_id = p.id) AS ballot_count
		 FROM {$wpdb->prefix}voting_polls p
		 ORDER BY p.created_at DESC"
	);

	echo '<div class="wrap"><h1>Vereinsvoting</h1>';

	if ( isset( $_GET['deleted'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Abstimmung gelöscht.</p></div>';
	}

	if ( 'edit' === $view || 'new' === $view ) {
		vv_render_poll_form( 'new' === $view ? '' : vv_poll_id( wp_unslash( $_GET['poll'] ?? '' ) ) );
	} elseif ( 'members' === $view) {
		$poll_id = vv_poll_id( wp_unslash( $_GET['poll'] ?? '' ) );
		$poll    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}voting_polls WHERE id = %s", $poll_id ) );
		if ( ! $poll ) {
			echo '<p>Abstimmung nicht gefunden.</p>';
		} else {
			// [FU002#AK08] Beendet: Import und Einladung entfallen (keine Wirkung mehr möglich).
			if ( 'closed' !== $poll->status ) {
				vv_render_import_form( $poll );
				vv_render_invite_form( $poll );
			}
			vv_render_members_table( $poll );
			// [FU002#AK08] Beendete Wahl: keine Import-/Einladungsoptionen – Zurück am Seitenende.
			if ( 'closed' === $poll->status ) {
				echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=vereins-voting' ) ) . '">Zurück</a></p>';
			}
		}
	} else {
		// Standardansicht: Abstimmungsliste.
		echo '<a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=vereins-voting&view=new' ) ) . '">Neue Abstimmung</a>';

		if ( ! $polls ) {
			echo '<p>Noch keine Abstimmung angelegt.</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>Titel</th><th>UUID</th><th>Status</th><th>Zeitraum</th><th>Teilnehmer</th><th>Eingegangene Stimmen</th><th>Aktionen</th>'
			. '</tr></thead><tbody>';
		foreach ( $polls as $poll ) {
			$member_count = (int) $poll->member_count;
			$ballot_count = (int) $poll->ballot_count;

			$edit_url = admin_url( 'admin.php?page=vereins-voting&view=edit&poll=' . rawurlencode( $poll->id ) );
			$mem_url  = admin_url( 'admin.php?page=vereins-voting&view=members&poll=' . rawurlencode( $poll->id ) );

			echo '<tr>'
				. '<td>' . esc_html( $poll->title ) . '</td>'
				. '<td><code>' . esc_html( $poll->id ) . '</code></td>'
				. '<td>' . vv_status_label( $poll ) . '</td>'
				. '<td>' . esc_html( vv_fmt( $poll->start_dt ) . ' – ' . vv_fmt( $poll->end_dt ) ) . '</td>'
				. '<td>' . $member_count . '</td>'
				. '<td>' . $ballot_count . '</td>'
				. '<td><a href="' . esc_url( $edit_url ) . '">Bearbeiten</a> | '
				. '<a href="' . esc_url( $mem_url ) . '">Teilnehmer & Einladung</a></td>'
				. '</tr>';
		}
		echo '</tbody></table>';
	}

	echo '</div>';
}

function vv_status_label( $poll ) {
	$labels = [ 'draft' => 'Entwurf', 'active' => 'läuft', 'closed' => 'beendet' ];
	return isset( $labels[ $poll->status ] )
		? esc_html( $labels[ $poll->status ] )
		: esc_html( $poll->status );
}

// ---------------------------------------------------------------------------
// Aktionen (immer POST + Nonce + Capability)
// ---------------------------------------------------------------------------

function vv_handle_admin_actions() {
	// [FU002#AK06] Nur eigene Formulare: fremde Admin-POSTs (Heartbeat, andere Plugins)
	// dürfen nicht am Nonce-Check dieses Plugins scheitern.
	$action = isset( $_POST['vv_action'] ) ? sanitize_key( wp_unslash( $_POST['vv_action'] ) ) : '';
	if ( '' === $action ) {
		return;
	}

	check_admin_referer( 'vv_admin', 'vv_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	switch ( $action ) {
		case 'save_poll':
			vv_save_poll();
			break;
		case 'import_csv':
			vv_import_csv();
			break;
		case 'send_invites':
			vv_send_invites();
			break;
		case 'save_mail_settings':
			vv_save_mail_settings();
			break;
		case 'save_poll_mail':
			vv_save_poll_mail();
			break;
		case 'test_mail':
			vv_test_mail();
			break;
		case 'pause_send':
			vv_pause_send();
			break;
		case 'resume_send':
			vv_resume_send();
			break;
		case 'retry_send':
			vv_retry_send();
			break;
		case 'delete_poll':
			vv_delete_poll();
			break;
		case 'reset_pin':
			vv_reset_pin();
			break;
		case 'delete_voter_data':
			vv_delete_voter_data();
			break;
	}
}
add_action( 'admin_init', 'vv_handle_admin_actions' );

/** [FU002#AK02] Löschen nur im Entwurf; Daten werden nie heimlich entsorgt. */
/**
 * [FU002#AK08] Teilnehmerdaten einer beendeten Wahl löschen: Identität,
 * PIN-Hashes und Zeitstempel der Wählerliste; Urne und Ergebnis bleiben.
 * Vorher die Wahlniederschrift erstellen – danach fehlen die Zeitangaben.
 */
function vv_delete_voter_data() {
	global $wpdb;

	$poll_id = vv_poll_id( wp_unslash( $_POST['poll_id'] ?? '' ) );
	$status  = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}voting_polls WHERE id = %s", $poll_id ) );

	if ( 'closed' !== $status ) {
		wp_die( 'Teilnehmerdaten löschen ist nur bei beendeten Abstimmungen möglich.' );
	}

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}voting_voters WHERE poll_id = %s", $poll_id ) );

	wp_safe_redirect( admin_url( 'admin.php?page=vereins-voting&view=members&poll=' . rawurlencode( $poll_id ) . '&voters_deleted=1' ) );
	exit;
}

/**
 * [FU002#AK02] Gesamt-Wahl löschen: nur im Status Entwurf, zusammen mit
 * Wählern und leeren Urnenzeilen.
 */
function vv_delete_poll() {
	global $wpdb;

	$poll_id = vv_poll_id( wp_unslash( $_POST['poll_id'] ?? '' ) );
	$status  = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}voting_polls WHERE id = %s", $poll_id ) );

	if ( 'draft' !== $status ) {
		wp_die( 'Löschen ist nur bei Abstimmungen im Status Entwurf möglich.' );
	}

	$wpdb->query( 'START TRANSACTION' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}voting_ballots WHERE poll_id = %s", $poll_id ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}voting_voters WHERE poll_id = %s", $poll_id ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}voting_polls WHERE id = %s", $poll_id ) );
	$wpdb->query( 'COMMIT' );

	delete_option( vv_queue_active_key( $poll_id ) );
	delete_option( vv_queue_paused_key( $poll_id ) );

	wp_safe_redirect( admin_url( 'admin.php?page=vereins-voting&deleted=' . $poll_id ) );
	exit;
}

/**
 * [FU005#AK04] Wahl-PIN zurücksetzen (z. B. vergessen): Der PIN-Hash wird
 * gelöscht und ein neuer Aktivierungstoken erzeugt, damit die Person über
 * eine neue Einladung einen neuen PIN setzen kann. Die Urne wird nicht
 * berührt; welches Mitglied wie gewählt hat, bleibt weiterhin unsichtbar.
 * Nur solange die Person noch nicht abgestimmt hat und die Wahl nicht
 * geschlossen ist – sonst wäre der Reset sinnlos oder irreführend.
 */
function vv_reset_pin() {
	global $wpdb;

	$poll_id  = vv_poll_id( wp_unslash( $_POST['poll'] ?? '' ) );
	$voter_id = absint( $_POST['voter_id'] ?? 0 );
	$page     = max( 1, absint( $_POST['vv_page'] ?? 1 ) );

	$poll = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$wpdb->prefix}voting_polls WHERE id = %s", $poll_id ) );
	if ( ! $poll || ! in_array( $poll->status, [ 'draft', 'active' ], true ) ) {
		wp_die( 'PIN-Reset ist nur vor dem Schließen der Abstimmung möglich.' );
	}

	$voter = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, member_no, pin_hash, has_voted FROM {$wpdb->prefix}voting_voters WHERE id = %d AND poll_id = %s",
		$voter_id,
		$poll_id
	) );
	if ( ! $voter || empty( $voter->pin_hash ) || (int) $voter->has_voted ) {
		wp_die( 'Für diese Person kann keine PIN zurückgesetzt werden.' );
	}

	$wpdb->update(
		"{$wpdb->prefix}voting_voters",
		[
			'pin_hash'         => null,
			'pin_set_at'       => null,
			'activation_token' => bin2hex( random_bytes( 16 ) ),
			'invited_at'       => null,
			'invite_attempts'  => 0,
		],
		[ 'id' => $voter->id ]
	);

	// Eine vergessene PIN hat oft schon die Brute-Force-Sperre ausgelöst.
	vv_rate_clear( 'vote', $poll->id . '|' . strtolower( trim( (string) $voter->member_no ) ) );

	wp_safe_redirect( admin_url(
		'admin.php?page=vereins-voting&view=members&poll=' . rawurlencode( $poll->id ) . '&vv_page=' . $page . '&reset=1'
	) );
	exit;
}

/** [FU002] Anlegen und Bearbeiten. [FU002#AK02] Zielstatus nur draft↔active↔closed in einer Richtung. */
function vv_save_poll() {
	global $wpdb;

	$poll_id = vv_poll_id( wp_unslash( $_POST['poll_id'] ?? '' ) );
	$title   = mb_substr( sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ), 0, 190 ); // DB-Spalte VARCHAR(190).
	$question= sanitize_textarea_field( wp_unslash( $_POST['question'] ?? '' ) );
	$start   = sanitize_text_field( wp_unslash( $_POST['start_dt'] ?? '' ) );
	$end     = sanitize_text_field( wp_unslash( $_POST['end_dt'] ?? '' ) );
	$status  = sanitize_key( $_POST['status'] ?? 'draft' );
	$start_raw = $start; // Unnormalisiert für das erneute Rendern der Maske.
	$end_raw   = $end;

	// [FU002#AK05] Eingabefehler beenden die Seite nicht (wp_die): Die Maske
	// rendert gleich erneut – mit Meldung und den hier eingegebenen Werten.
	$error = '';
	if ( '' === $title || '' === $start || '' === $end ) {
		$error = 'Titel, Start und Ende sind Pflichtfelder.';
	}

	// datetime-local ('YYYY-MM-DDTHH:MM') auf MySQL-Format normalisieren,
	// damit String-Vergleiche in vv_cast_vote/vv_shortcode_results konsistent sind.
	$start = vv_normalize_dt( $start );
	$end   = vv_normalize_dt( $end );

	// [FU002#AK01] Ein Ende vor dem Start ergäbe eine Wahl, die nie starten kann.
	// [FU002#AK01] Start/Ende liegen immer auf vollen Stunden (z. B. 18:00).
	// [FU002#AK02] Bei aktiven/geschlossenen Abstimmungen sind die Zeitfelder
	// gesperrt und werden ignoriert – dann gelten beide Prüfungen nicht.
	$existing = null;
	if ( $poll_id && '' === $error ) {
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}voting_polls WHERE id = %s", $poll_id ) );
		if ( ! $existing ) {
			wp_die( 'Abstimmung nicht gefunden.' );
		}
	}
	if ( '' === $error && ( ! $existing || 'draft' === $existing->status ) ) {
		if ( '00:00' !== substr( $start, 14, 5 ) || '00:00' !== substr( $end, 14, 5 ) ) {
			$error = 'Start und Ende müssen auf eine volle Stunde fallen (z. B. 18:00).';
		} elseif ( $end <= $start ) {
			$error = 'Das Ende muss nach dem Start liegen.';
		}
	}

	if ( '' !== $error ) {
		$GLOBALS['vv_poll_form_flash'] = [
			'error' => $error,
			'data'  => [
				'poll_id'  => $poll_id,
				'title'    => $title,
				'question' => $question,
				'start_dt' => $start_raw,
				'end_dt'   => $end_raw,
			],
		];
		return;
	}

	if ( ! $poll_id ) {
		// Neu anlegen – Status beginnt als Entwurf; aktiv wird er beim Start.
		// [FU002#AK07] Die ID ist eine zufällige UUID, keine fortlaufende Zahl.
		$new_id   = vv_uuid();
		$inserted = $wpdb->insert( "{$wpdb->prefix}voting_polls", [
			'id'       => $new_id,
			'title'    => $title,
			'question' => $question,
			'start_dt' => $start,
			'end_dt'   => $end,
			'status'   => 'draft',
		] );
		if ( ! $inserted ) {
			wp_die( 'Abstimmung konnte nicht gespeichert werden.' );
		}
		$poll_id = $new_id;
	// [FU002#AK02] Bearbeiten aktiver/geschlossener Wahl: sicherheitsrelevante Felder ignorieren, nur Statusübergang erlauben.
	} else {
		if ( 'draft' === $existing->status ) {
			$wpdb->update( "{$wpdb->prefix}voting_polls", [
				'title'    => $title,
				'question' => $question,
				'start_dt' => $start,
				'end_dt'   => $end,
			], [ 'id' => $poll_id ] );
			// Aktivierung (draft -> active) nur innerhalb des Zeitraums; sonst
			// startet die Wahl automatisch beim ersten gültigen Votum.
			$now       = current_time( 'mysql' );
			$in_window = $start <= $now && $now < $end;
			if ( 'active' === $status && $in_window ) {
				$wpdb->update( "{$wpdb->prefix}voting_polls", [ 'status' => 'active' ], [ 'id' => $poll_id ] );
			}
		} else {
			// [FU002#AK02] Nur Verabschieden active -> closed; keine Änderung von Titel/Fristen.
			if ( 'active' === $existing->status && 'closed' === $status ) {
				// [FU002#AK02] Abgabefingerprint entsteht beim formellen Abschluss.
				$wpdb->update( "{$wpdb->prefix}voting_polls",
					[ 'status' => 'closed', 'urn_fingerprint' => vv_urn_fingerprint( $poll_id ) ],
					[ 'id' => $poll_id ]
				);
			}
		}
	}

	// [FU002#AK05] saved=1 signalisiert der Zielseite die Erfolgsmeldung.
	wp_safe_redirect( admin_url( 'admin.php?page=vereins-voting&view=members&poll=' . $poll_id . '&saved=1' ) );
	exit;
}

/** [FU003] CSV-Import mit Validierung, Duplikatschutz und Token-Erzeugung. */
function vv_import_csv() {
	global $wpdb;

	$poll_id = vv_poll_id( wp_unslash( $_POST['poll'] ?? '' ) );
	$poll    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}voting_polls WHERE id = %s", $poll_id ) );
	if ( ! $poll || 'draft' !== $poll->status ) {
		wp_die( 'Import nur bei Abstimmungen im Status Entwurf.' );
	}

	if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
		wp_die( 'Keine CSV-Datei hochgeladen.' );
	}

	$handle = fopen( $_FILES['csv_file']['tmp_name'], 'r' );
	if ( ! $handle ) {
		wp_die( 'CSV-Datei kann nicht gelesen werden.' );
	}

	$imported = 0;
	$skipped  = 0;

	// Trennzeichen aus der ersten Zeile ableiten (Komma oder Semikolon).
	$delim   = ',';
	$preview = fgets( $handle );
	if ( is_string( $preview ) && strpos( $preview, ';' ) !== false ) {
		$delim = ';';
	}
	rewind( $handle );

	// [FU003#AK01] Kopfzeile ist Pflicht: Spalten werden über ihre Namen
	// zugeordnet (Reihenfolge frei); weitere Spalten werden ignoriert.
	$header = fgetcsv( $handle, 0, $delim );
	if ( false === $header ) {
		fclose( $handle );
		wp_die( 'CSV-Datei ist leer.' );
	}
	$map = [];
	foreach ( (array) $header as $i => $label ) {
		$key = preg_replace( '/[^a-z0-9]/', '', strtolower( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $label ) ) );
		if ( in_array( $key, [ 'mitgliedsnummer', 'email', 'vorname', 'name', 'nachname' ], true ) && ! isset( $map[ $key ] ) ) {
			$map[ $key ] = $i;
		}
	}
	// „Name“ und „Nachname“ sind gleichwertig; bei beiden gilt Nachname.
	$map['nachname'] = $map['nachname'] ?? ( $map['name'] ?? null );
	$labels = [
		'mitgliedsnummer' => 'Mitgliedsnummer',
		'email'           => 'E-Mail',
		'vorname'         => 'Vorname',
		'nachname'        => 'Name (oder Nachname)',
	];
	$missing = [];
	foreach ( $labels as $key => $label ) {
		if ( ! isset( $map[ $key ] ) ) {
			$missing[] = $label;
		}
	}
	if ( $missing ) {
		fclose( $handle );
		wp_die(
			'CSV-Kopfzeile unvollständig. Fehlende Spalten: ' . implode( ', ', $missing )
			. '. Gefundene Kopfzeile: ' . implode( ', ', array_map( 'esc_html', array_map( 'trim', (array) $header ) ) )
			. '. Erwartet werden die Spalten Mitgliedsnummer, E-Mail, Vorname und Name (oder Nachname).'
		);
	}

	// Kurze Zeilen und Leerzeilen dürfen den Import nicht abbrechen.
	$cell = fn( $row, $i ) => isset( $row[ $i ] ) ? (string) $row[ $i ] : '';

	$process_row = function ( $row ) use ( $wpdb, $poll_id, $cell, $map, &$imported, &$skipped ) {
		if ( ! is_array( $row ) ) {
			return;
		}
		$member_no = sanitize_text_field( $cell( $row, $map['mitgliedsnummer'] ) );
		$email     = sanitize_email( $cell( $row, $map['email'] ) );
		$first     = mb_substr( sanitize_text_field( $cell( $row, $map['vorname'] ) ), 0, 100 ); // DB-Spalte VARCHAR(100).
		$last      = mb_substr( sanitize_text_field( $cell( $row, $map['nachname'] ) ), 0, 100 );

		if ( '' === $member_no && '' === $email && '' === $first && '' === $last ) {
			return; // Leerzeile: still überspringen, nicht als Fehler zählen.
		}
		// [FU003#AK03] Pflichtwerte (inkl. beider Namen) und E-Mail-Syntax prüfen.
		if ( '' === $member_no || '' === $first || '' === $last || ! is_email( $email ) ) {
			++$skipped;
			return;
		}

		// [FU003#AK02] Duplikatschutz pro Abstimmung über Mitgliedsnummer UND
		// E-Mail (E-Mail case-insensitiv). Dieselbe E-Mail für eine andere
		// Mitgliedsnummer ist zulässig (z. B. Ehepaare).
		// Bei Altdaten ohne Namen werden leere Namensfelder nachgetragen,
		// vorhandene Werte bleiben unangetastet.
		$exists = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, first_name, last_name FROM {$wpdb->prefix}voting_voters
			 WHERE poll_id = %s AND member_no = %s AND LOWER(email) = %s",
			$poll_id,
			$member_no,
			strtolower( $email )
		) );
		if ( $exists ) {
			$fill = [];
			if ( '' === (string) $exists->first_name ) {
				$fill['first_name'] = $first;
			}
			if ( '' === (string) $exists->last_name ) {
				$fill['last_name'] = $last;
			}
			if ( $fill ) {
				$wpdb->update( "{$wpdb->prefix}voting_voters", $fill, [ 'id' => (int) $exists->id ] );
			}
			++$skipped;
			return;
		}

		if ( ! $wpdb->insert( "{$wpdb->prefix}voting_voters", [
			'poll_id'          => $poll_id,
			'member_no'        => $member_no,
			'email'            => $email,
			'first_name'       => $first,
			'last_name'        => $last,
			'activation_token' => bin2hex( random_bytes( 16 ) ),
		] ) ) {
			++$skipped; // DB-Fehler (z. B. Länge/Zeichensatz) nicht als importiert melden.
			return;
		}
		++$imported;
	};

	$wpdb->query( 'START TRANSACTION' );
	while ( ( $row = fgetcsv( $handle, 0, $delim ) ) !== false ) {
		$process_row( $row );
	}
	fclose( $handle );
	$wpdb->query( 'COMMIT' );

	wp_safe_redirect( admin_url( 'admin.php?page=vereins-voting&view=members&poll=' . $poll_id
		. '&imported=' . $imported . '&skipped=' . $skipped ) );
	exit;
}

// ---------------------------------------------------------------------------
// Formulare
// ---------------------------------------------------------------------------

/** [FU002#AK01/#AK02] Anlegen/Bearbeiten; aktive/geschlossene Wahl = Felder gesperrt. */
function vv_render_poll_form( $poll_id = '' ) {
	global $wpdb;

	$poll = $poll_id
		? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}voting_polls WHERE id = %s", $poll_id ) )
		: null;
	$locked = $poll && 'draft' !== $poll->status;

	// [FU002#AK05] Nach einem Eingabefehler die nicht gespeicherten Werte zeigen.
	$flash = $GLOBALS['vv_poll_form_flash'] ?? null;
	unset( $GLOBALS['vv_poll_form_flash'] );
	if ( $flash && (string) $flash['data']['poll_id'] !== (string) $poll_id ) {
		$flash = null;
	}
	$title_val    = $flash['data']['title'] ?? ( $poll->title ?? '' );
	$question_val = $flash['data']['question'] ?? ( $poll->question ?? '' );
	$start_val    = str_replace( ' ', 'T', $flash['data']['start_dt'] ?? ( $poll ? substr( (string) $poll->start_dt, 0, 16 ) : '' ) );
	$end_val      = str_replace( ' ', 'T', $flash['data']['end_dt'] ?? ( $poll ? substr( (string) $poll->end_dt, 0, 16 ) : '' ) );

	$closed = $poll && 'closed' === $poll->status;

	echo $closed ? '' : '<form method="post">';
	if ( ! $closed ) {
		echo wp_nonce_field( 'vv_admin', 'vv_nonce' );
		echo '<input type="hidden" name="vv_action" value="save_poll">';
		echo '<input type="hidden" name="poll_id" value="' . esc_attr( $poll_id ) . '">';
	}
	if ( $flash ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $flash['error'] ) . '</p></div>';
	}
	if ( $locked ) {
		echo '<div class="notice notice-warning"><p>Diese Abstimmung läuft oder ist beendet – Titel/Fristen sind gesperrt.</p></div>';
	}
	echo '<table class="form-table">';
	echo '<tr><th><label for="vv-title">Titel</label></th><td><input name="title" id="vv-title" type="text" class="regular-text" maxlength="190" value="' . esc_attr( $title_val ) . '" ' . ( $locked ? 'readonly' : '' ) . ' required></td></tr>';
	echo '<tr><th><label for="vv-question">Frage / Beschreibung</label></th><td><textarea name="question" id="vv-question" rows="4" class="large-text" ' . ( $locked ? 'readonly' : '' ) . '>' . esc_textarea( $question_val ) . '</textarea></td></tr>';
	echo '<tr><th><label for="vv-start">Start</label></th><td><input name="start_dt" id="vv-start" type="datetime-local" step="3600" value="' . esc_attr( $start_val ) . '" ' . ( $locked ? 'readonly' : '' ) . ' required></td></tr>';
	echo '<tr><th><label for="vv-end">Ende</label></th><td><input name="end_dt" id="vv-end" type="datetime-local" step="3600" value="' . esc_attr( $end_val ) . '" ' . ( $locked ? 'readonly' : '' ) . ' required></td></tr>';

	if ( $poll ) {
		echo '<tr><th>Status</th><td>';
		if ( ! $locked ) {
			$now       = current_time( 'mysql' );
			$in_window = vv_normalize_dt( $poll->start_dt ) <= $now && $now < vv_normalize_dt( $poll->end_dt );
			if ( $in_window ) {
				echo '<label><input type="radio" name="status" value="draft" checked> Entwurf</label> ';
				echo '<label><input type="radio" name="status" value="active"> Läuft (startet die Wahl jetzt)</label>';
			} else {
				echo '<p>Die Abstimmung startet automatisch beim ersten gültigen Votum innerhalb des Zeitraums.</p>';
			}
		} else {
			echo 'Aktueller Status: <strong>' . esc_html( [ 'draft' => 'Entwurf', 'active' => 'läuft', 'closed' => 'beendet' ][ $poll->status ] ?? $poll->status ) . '</strong>';
			if ( 'active' === $poll->status ) {
				echo '<p><label><input type="checkbox" name="status" value="closed"> Abstimmung vorzeitig beenden</label></p>';
			}
		}
		echo '</td></tr>';
	}
	echo '</table>';
	if ( $closed ) {
		// [FU002#AK02] Beendet: keine Aktion mehr möglich – zurück zur Liste.
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=vereins-voting' ) ) . '">Zurück</a></p>';
	} else {
		echo '<p><button class="button button-primary" type="submit">Speichern</button> '
			. '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=vereins-voting' ) ) . '">Abbrechen</a></p></form>';
	}

	// Löschen nur für Entwürfe.
	if ( $poll && 'draft' === $poll->status ) {
		echo '<form method="post" onsubmit="return confirm(\'Abstimmung samt aller Teilnehmerdaten wirklich löschen?\');" style="margin-top:1em">';
		wp_nonce_field( 'vv_admin', 'vv_nonce' );
		echo '<input type="hidden" name="vv_action" value="delete_poll">';
		echo '<input type="hidden" name="poll_id" value="' . esc_attr( $poll_id ) . '">';
		echo '<button type="submit" class="button button-secondary" >Abstimmung löschen</button></form>';
	}
}

function vv_render_import_form( $poll ) {
	echo '<h2>Teilnehmer (CSV-Import)</h2>';
	echo '<p>CSV mit Kopfzeile, z. B. <code>Mitgliedsnummer;E-Mail;Vorname;Name</code> — Spaltenreihenfolge frei, '
		. '<code>Nachname</code> statt <code>Name</code> möglich, weitere Spalten werden ignoriert. '
		. 'Trennzeichen <code>,</code> oder <code>;</code>, eine Person pro Zeile.</p>';
	echo '<form method="post" enctype="multipart/form-data">';
	wp_nonce_field( 'vv_admin', 'vv_nonce' );
	echo '<input type="hidden" name="vv_action" value="import_csv">';
	echo '<input type="hidden" name="poll" value="' . esc_attr( $poll->id ) . '">';
	echo '<p><input type="file" name="csv_file" accept=".csv,text/csv" required></p>';
	echo '<p><button type="submit" class="button">CSV importieren</button> '
		. '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=vereins-voting' ) ) . '">Abbrechen</a></p>';
	echo '</form>';
}

function vv_render_invite_form( $poll ) {
	$counts         = vv_poll_mail_counts( $poll->id );
	$active         = vv_queue_is_active( $poll->id );
	$paused         = vv_queue_is_paused( $poll->id );
	$sendable       = vv_queue_poll_sendable( $poll );
	$settings_error = vv_mail_settings_error();
	$rate           = vv_mail_rate();
	$settings_url   = admin_url( 'admin.php?page=vereins-voting-mail' );

	$flash = get_transient( vv_mail_poll_flash_key( $poll->id ) );
	delete_transient( vv_mail_poll_flash_key( $poll->id ) );
	$mail_text = $flash['mail_text'] ?? ( '' !== trim( (string) $poll->mail_text ) ? (string) $poll->mail_text : vv_mail_poll_text_default() );
	$mail_url  = $flash['mail_url'] ?? (string) $poll->mail_url;

	$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
	$test_address = $user && isset( $user->user_email ) ? (string) $user->user_email : '';
	$test_error   = get_transient( vv_mail_test_error_key() );
	delete_transient( vv_mail_test_error_key() );

	$status = ! $sendable ? 'beendet' : ( ! $active ? 'nicht gestartet' : ( $paused ? 'pausiert' : 'läuft' ) );
	?>
	<h2>Einladungen versenden</h2>
	<p>Vorlage, Betreff und Footer gelten global und stehen unter
		<a href="<?php echo esc_url( $settings_url ); ?>">E-Mail-Einstellungen</a>.
		Hier steht nur der Text dieser Abstimmung und ein optionaler Website-Link.</p>
	<?php if ( $flash && ! empty( $flash['error'] ) ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $flash['error'] ); ?></p></div>
	<?php elseif ( isset( $_GET['mail_error'] ) ) : ?>
		<div class="notice notice-error"><p>Der Text wurde nicht gespeichert.</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['mail_saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>E-Mail-Text gespeichert.</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['tested'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Testmail wurde an das angemeldete WordPress-Konto versendet.</p></div>
	<?php elseif ( isset( $_GET['test_failed'] ) ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $test_error ?: 'Die Testmail konnte nicht versendet werden.' ); ?></p></div>
	<?php endif; ?>
	<?php if ( '' !== $settings_error ) : ?>
		<div class="notice notice-warning"><p><?php echo esc_html( $settings_error ); ?>
			<a href="<?php echo esc_url( $settings_url ); ?>">E-Mail-Einstellungen öffnen</a></p></div>
	<?php endif; ?>
	<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
		<div class="notice notice-warning"><p>WP-Cron ist deaktiviert. Der Versand läuft dann nur über
			„Weitere Einladungen jetzt senden“.</p></div>
	<?php endif; ?>

	<h3>E-Mail-Text dieser Abstimmung</h3>
	<p>Platzhalter: <code>{vorname} {nachname} {first_name} {last_name} {poll_title} {question} {start} {end} {member_no} {site_name} {site_url} {privacy_url}</code></p>
	<form method="post">
		<?php wp_nonce_field( 'vv_admin', 'vv_nonce' ); ?>
		<input type="hidden" name="vv_action" value="save_poll_mail">
		<input type="hidden" name="poll" value="<?php echo esc_attr( $poll->id ); ?>">
		<table class="form-table">
			<tr>
				<th><label for="vv-mail-text">Textblock</label></th>
				<td><textarea name="mail_text" id="vv-mail-text" rows="7" class="large-text"><?php echo esc_textarea( $mail_text ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="vv-mail-url">Link zur Website (optional)</label></th>
				<td><input name="mail_url" id="vv-mail-url" type="url" class="large-text"
					value="<?php echo esc_attr( $mail_url ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
					<p class="description">Wird als Hinweis „Mehr zum Ablauf der Wahl und der eingesetzten Software“ in die Mail gesetzt.</p></td>
			</tr>
		</table>
		<p><button type="submit" class="button">Text speichern</button></p>
	</form>

	<div><a class="button" href="<?php echo esc_url( vv_mail_preview_url( $poll->id ) ); ?>" target="_blank" rel="noopener">Vorschau dieser Einladung</a>
		<?php if ( '' === $settings_error && is_email( $test_address ) ) : ?>
			<form method="post" style="display:inline">
				<?php wp_nonce_field( 'vv_admin', 'vv_nonce' ); ?>
				<input type="hidden" name="vv_action" value="test_mail">
				<input type="hidden" name="poll" value="<?php echo esc_attr( $poll->id ); ?>">
				<button type="submit" class="button">Testmail an <?php echo esc_html( $test_address ); ?></button>
			</form>
		<?php endif; ?>
	</div>
	<p class="description">Vorschau und Test nutzen die gespeicherten Werte und erfundene Beispieldaten; der Testlink ist ungültig.</p>

	<h3>Versandstatus</h3>
	<?php if ( isset( $_GET['queued'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php if ( 0 === (int) ( $_GET['queued'] ?? 0 ) ) : ?>
				Keine offenen Einladungen.
			<?php else : ?>
				Versand gestartet. In diesem Lauf wurden insgesamt <?php echo (int) ( $_GET['sent'] ?? 0 ); ?> Einladungen versendet;
				der Rest folgt automatisch.
			<?php endif; ?>
		</p></div>
	<?php elseif ( isset( $_GET['paused'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Versand pausiert. Der aktuelle Lauf ist beendet.</p></div>
	<?php elseif ( isset( $_GET['resumed'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Versand fortgesetzt. In diesem Lauf insgesamt: <?php echo (int) ( $_GET['sent'] ?? 0 ); ?>.</p></div>
	<?php elseif ( isset( $_GET['retried'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Fehlgeschlagene Einladungen erneut eingeplant. In diesem Lauf insgesamt: <?php echo (int) ( $_GET['sent'] ?? 0 ); ?>.</p></div>
	<?php endif; ?>
	<p>Status: <strong><?php echo esc_html( $status ); ?></strong>.
		Offen: <strong><?php echo (int) $counts['pending']; ?></strong>,
		gesendet: <strong><?php echo (int) $counts['sent']; ?></strong>,
		fehlgeschlagen: <strong><?php echo (int) $counts['failed']; ?></strong>.</p>
	<p>Versandrate: <strong><?php echo (int) $rate; ?></strong> Mails pro 5 Minuten (global).
		<a href="<?php echo esc_url( $settings_url ); ?>">Ändern</a></p>

	<?php if ( $sendable && $counts['pending'] > 0 && ! $paused ) : ?>
		<form method="post" onsubmit="return confirm('Massenversand starten? Offene Einladungen: <?php echo (int) $counts['pending']; ?>');">
			<?php wp_nonce_field( 'vv_admin', 'vv_nonce' ); ?>
			<input type="hidden" name="vv_action" value="send_invites">
			<input type="hidden" name="poll" value="<?php echo esc_attr( $poll->id ); ?>">
			<p><button type="submit" class="button button-primary" <?php echo '' !== $settings_error ? 'disabled' : ''; ?>>
				<?php echo $active ? 'Weitere Einladungen jetzt senden' : 'Einladungen versenden'; ?>
			</button></p>
		</form>
	<?php endif; ?>

	<?php if ( $sendable && $active && ! $paused && $counts['pending'] > 0 ) : ?>
		<form method="post" style="display:inline">
			<?php wp_nonce_field( 'vv_admin', 'vv_nonce' ); ?>
			<input type="hidden" name="vv_action" value="pause_send">
			<input type="hidden" name="poll" value="<?php echo esc_attr( $poll->id ); ?>">
			<button type="submit" class="button">Versand pausieren</button>
		</form>
	<?php elseif ( $sendable && $paused && $counts['pending'] > 0 ) : ?>
		<form method="post" style="display:inline">
			<?php wp_nonce_field( 'vv_admin', 'vv_nonce' ); ?>
			<input type="hidden" name="vv_action" value="resume_send">
			<input type="hidden" name="poll" value="<?php echo esc_attr( $poll->id ); ?>">
			<button type="submit" class="button button-primary">Versand fortsetzen</button>
		</form>
	<?php endif; ?>

	<?php if ( $sendable && $counts['failed'] > 0 ) : ?>
		<form method="post" style="margin-top:1em" onsubmit="return confirm('Fehlgeschlagene Einladungen erneut versuchen?');">
			<?php wp_nonce_field( 'vv_admin', 'vv_nonce' ); ?>
			<input type="hidden" name="vv_action" value="retry_send">
			<input type="hidden" name="poll" value="<?php echo esc_attr( $poll->id ); ?>">
			<button type="submit" class="button">Fehlgeschlagene erneut versuchen (<?php echo (int) $counts['failed']; ?>)</button>
		</form>
	<?php endif; ?>

	<?php if ( ! $sendable && ( $counts['pending'] > 0 || $counts['failed'] > 0 ) ) : ?>
		<p>Der Versand ist für diese Abstimmung beendet; offene oder fehlgeschlagene Einladungen können nicht mehr gesendet werden.</p>
	<?php elseif ( 0 === $counts['pending'] && 0 === $counts['failed'] && $counts['sent'] > 0 ) : ?>
		<p>Alle Einladungen dieser Abstimmung sind versendet.</p>
	<?php elseif ( 0 === $counts['pending'] && 0 === $counts['failed'] ) : ?>
		<p>Noch keine Einladungen vorhanden oder versendet.</p>
	<?php endif; ?>
	<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=vereins-voting' ) ); ?>">Abbrechen</a></p>
	<?php
}

/** [FU005#AK01] Stimmberechtigte Mitglieder – ohne Blick auf den Inhalt der Stimme. */
function vv_render_members_table( $poll ) {
	global $wpdb;

	$per_page = 100;
	$total    = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}voting_voters WHERE poll_id = %s",
		$poll->id
	) );
	$pages = max( 1, (int) ceil( $total / $per_page ) );
	$page  = min( $pages, max( 1, isset( $_GET['vv_page'] ) ? absint( $_GET['vv_page'] ) : 1 ) );

	$voters = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, member_no, first_name, last_name, email, activation_token, pin_hash, has_voted, voted_at, invited_at, invite_attempts
		 FROM {$wpdb->prefix}voting_voters
		 WHERE poll_id = %s ORDER BY member_no LIMIT %d OFFSET %d",
		$poll->id,
		$per_page,
		( $page - 1 ) * $per_page
	) );

	echo '<h2>Stimmberechtigte Mitglieder</h2>';
	if ( isset( $_GET['saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Abstimmung gespeichert.</p></div>';
	}
	if ( isset( $_GET['imported'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>'
			. (int) $_GET['imported'] . ' importiert, ' . (int) ( $_GET['skipped'] ?? 0 ) . ' übersprungen.</p></div>';
	}
	if ( isset( $_GET['reset'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>PIN zurückgesetzt. Die Person ist wieder für den Einladungsversand vorgemerkt '
			. 'und erhält über „Einladungen versenden“ einen neuen Aktivierungslink.</p></div>';
	}
	if ( isset( $_GET['voters_deleted'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Teilnehmerdaten gelöscht. Urne und Ergebnis bleiben unverändert erhalten.</p></div>';
	}

	/** [FU005#AK02] Liste zeigt nur has_voted/voted_at – niemals eine Stimme. */
	// Abschluss-Block vor dem Früh-Ausstieg: nach dem Löschen der Teilnehmerdaten
	// (FU002#AK08) bleiben Ergebnis, Fingerprint und Niederschrift sichtbar.
	if ( 'closed' === $poll->status ) {
		$results = vv_get_results( $poll->id );
		echo '<h2>Gesamtergebnis</h2><table class="widefat striped"><thead><tr><th>Option</th><th>Stimmen</th><th>Anteil</th></tr></thead><tbody>';
		foreach ( $results as $option => $row ) {
			echo '<tr><td>' . esc_html( ucfirst( $option ) ) . '</td><td>' . (int) $row['count'] . '</td><td>' . esc_html( number_format( $row['percent'], 1 ) ) . '%</td></tr>';
		}
		echo '</tbody></table>';

		// [FU002#AK02] Abgabefingerprint: gespeicherter Abschlusswert vs. aktuelle Urne.
		$stored = (string) ( $poll->urn_fingerprint ?? '' );
		$live   = vv_urn_fingerprint( $poll->id );
		if ( '' === $stored ) {
			echo '<p class="vv-fingerprint-note">Kein Abgabefingerprint gespeichert (formeller Abschluss versäumt).</p>';
		} elseif ( hash_equals( $stored, $live ) ) {
			echo '<p class="vv-fingerprint-note">Abgabefingerprint: unverändert (<code>' . esc_html( substr( $stored, 0, 16 ) ) . '…</code>).</p>';
		} else {
			echo '<div class="notice notice-error"><p><strong>Abgabefingerprint abweichend!</strong> Die Urne wurde nach dem Abschluss verändert '
				. '(gespeichert <code>' . esc_html( substr( $stored, 0, 16 ) ) . '…</code>, jetzt <code>' . esc_html( substr( $live, 0, 16 ) ) . '…</code>). '
				. 'Wahlergebnis nicht als gültig behandeln.</p></div>';
		}

		// [FU005#AK05] Wahlniederschrift als druckbare Seite.
		$report_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=vereinsvoting_report&poll=' . rawurlencode( $poll->id ) ),
			'vv_report'
		);
		echo '<p><a class="button button-primary" href="' . esc_url( $report_url ) . '" target="_blank">Wahlniederschrift drucken (PDF)</a>';

		// [FU002#AK08] Identitätsdaten der Wählerliste entfernen – nach der Niederschrift.
		if ( $total > 0 ) {
			echo ' <form method="post" style="display:inline" onsubmit="return confirm(\'Teilnehmerdaten löschen? Die Wahlniederschrift muss vorher erstellt/archiviert sein – danach sind Einladungs-, PIN- und Abgabezeiten weg. Die Urne bleibt.\')">'
				. wp_nonce_field( 'vv_admin', 'vv_nonce', true, false )
				. '<input type="hidden" name="vv_action" value="delete_voter_data">'
				. '<input type="hidden" name="poll_id" value="' . esc_attr( $poll->id ) . '">'
				. '<button type="submit" class="button">Teilnehmerdaten löschen</button></form>';
		} else {
			echo ' <span class="description">Keine Teilnehmerdaten mehr vorhanden (bereits gelöscht oder nicht importiert).</span>';
		}
		echo '</p>';
	}

	if ( ! $voters ) {
		// [FU002#AK08] Beendet: der Abschluss-Block oben erklärt den Zustand selbst.
		if ( 'closed' !== $poll->status ) {
			echo '<p>Noch keine Teilnehmer importiert.</p>';
		}
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th>Mitgliedsnummer</th><th>Name</th><th>E-Mail</th><th>PIN gesetzt</th><th>Einladung</th><th>Hat gewählt</th><th>Gewählt am</th><th>Aktionen</th></tr></thead><tbody>';
	foreach ( $voters as $v ) {
		$name    = trim( (string) $v->first_name . ' ' . (string) $v->last_name );
		$pin_set = ! empty( $v->pin_hash );
		$invite  = $v->invited_at
			? 'gesendet'
			: ( empty( $v->activation_token ) ? '–' : ( (int) $v->invite_attempts >= 3 ? 'fehlgeschlagen' : 'offen' ) );
		$action  = '–';
		// [FU005#AK04] Reset nur vor der Stimmabgabe und solange die Wahl offen ist.
		if ( $pin_set && ! (int) $v->has_voted && 'closed' !== $poll->status ) {
			$action = '<form method="post" style="margin:0" onsubmit="return confirm(\'PIN zurücksetzen? Die Person benötigt danach eine neue Einladung.\');">'
				. wp_nonce_field( 'vv_admin', 'vv_nonce', true, false )
				. '<input type="hidden" name="vv_action" value="reset_pin">'
				. '<input type="hidden" name="poll" value="' . esc_attr( $poll->id ) . '">'
				. '<input type="hidden" name="voter_id" value="' . (int) $v->id . '">'
				. '<input type="hidden" name="vv_page" value="' . (int) $page . '">'
				. '<button type="submit" class="button button-small">PIN zurücksetzen</button></form>';
		}
		echo '<tr>'
			. '<td>' . esc_html( $v->member_no ) . '</td>'
			. '<td>' . esc_html( '' === $name ? '–' : $name ) . '</td>'
			. '<td>' . esc_html( $v->email ) . '</td>'
			. '<td>' . ( $pin_set ? 'Ja' : 'Nein' ) . '</td>'
			. '<td>' . esc_html( $invite ) . '</td>'
			. '<td>' . ( (int) $v->has_voted ? 'Ja' : 'Nein' ) . '</td>'
			. '<td>' . ( $v->voted_at ? esc_html( vv_fmt( $v->voted_at ) ) : '–' ) . '</td>'
			. '<td>' . $action . '</td>'
			. '</tr>';
	}
	echo '</tbody></table>';

	// [FU005#AK01] Seitenweise Anzeige, damit große Vereine nicht alles auf einmal laden.
	if ( $pages > 1 ) {
		$base = admin_url( 'admin.php?page=vereins-voting&view=members&poll=' . rawurlencode( $poll->id ) );
		echo '<p class="vv-pagination">';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( [ 'vv_page' => $page - 1 ], $base ) ) . '">« Zurück</a> ';
		}
		echo 'Seite ' . (int) $page . ' von ' . (int) $pages;
		if ( $page < $pages ) {
			echo ' <a class="button" href="' . esc_url( add_query_arg( [ 'vv_page' => $page + 1 ], $base ) ) . '">Weiter »</a>';
		}
		echo '</p>';
	}
}
// ---------------------------------------------------------------------------
// [FU005#AK05] Wahlniederschrift: druckbare Seite (Browser-Druck → PDF)
// ---------------------------------------------------------------------------

add_action( 'admin_post_vereinsvoting_report', 'vv_report_endpoint' );

/** [FU005#AK05] Endpoint: Capability + Nonce, danach Standalone-Ausgabe mit Exit. */
function vv_report_endpoint() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Keine Berechtigung.' );
	}
	check_admin_referer( 'vv_report' );
	header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
	echo vv_report_html();
	exit;
}

/** [FU005#AK05] Niederschrift als vollständiges HTML-Dokument ohne Admin-Chrome (Tests rufen direkt). */
function vv_report_html() {
	global $wpdb;

	$poll_id = vv_poll_id( wp_unslash( $_GET['poll'] ?? '' ) );
	$poll    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}voting_polls WHERE id = %s", $poll_id ) );
	if ( ! $poll ) {
		return '<!DOCTYPE html><html lang="de"><body><p>Abstimmung nicht gefunden.</p></body></html>';
	}

	$results  = vv_get_results( $poll_id );
	$ballots  = $wpdb->get_results( $wpdb->prepare(
		"SELECT receipt_hash, vote_option FROM {$wpdb->prefix}voting_ballots WHERE poll_id = %s ORDER BY receipt_hash",
		$poll_id
	) );
	$voters   = $wpdb->get_results( $wpdb->prepare(
		"SELECT member_no, first_name, last_name, email, invited_at, pin_set_at, voted_at
		 FROM {$wpdb->prefix}voting_voters WHERE poll_id = %s ORDER BY member_no",
		$poll_id
	) );
	$statuses = [ 'draft' => 'Entwurf', 'active' => 'läuft', 'closed' => 'beendet' ];

	$stored = (string) ( $poll->urn_fingerprint ?? '' );
	$live   = vv_urn_fingerprint( $poll_id );
	$fp_txt = '' === $stored
		? esc_html( $live ) . ' (live berechnet – kein formeller Abschluss, kein Vergleichswert vorhanden)'
		: ( hash_equals( $stored, $live )
			? esc_html( $stored ) . ' stimmt mit der aktuellen Urne überein'
			: '<strong>Abweichung!</strong> gespeichert ' . esc_html( $stored ) . ', jetzt ' . esc_html( $live ) );

	ob_start(); ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="<?php echo esc_html( get_option( 'blog_charset' ) ?: 'UTF-8' ); ?>">
<title>Wahlniederschrift – <?php echo esc_html( $poll->title ); ?></title>
<style>
	body { font-family: -apple-system, 'Segoe UI', sans-serif; font-size: 12pt; line-height: 1.4; margin: 15mm; color: #111; }
	h1 { font-size: 1.4em; margin: 0 0 .2em; } h2 { font-size: 1.1em; margin: 1.4em 0 .4em; }
	table { border-collapse: collapse; width: 100%; margin: .4em 0 1em; }
	th, td { border: 1px solid #999; padding: 4px 7px; text-align: left; }
	th { background: #f2f2f2; }
	code { word-break: break-all; font-size: .85em; }
	.vv-noprint { margin: 1em 0; }
	@media print { .vv-noprint { display: none; } @page { margin: 15mm; } }
</style>
</head>
<body>
	<p class="vv-noprint"><button onclick="window.print()">Drucken / als PDF speichern</button></p>
	<h1>Wahlniederschrift: <?php echo esc_html( $poll->title ); ?></h1>
	<p>Frage: <?php echo esc_html( (string) $poll->question !== '' ? (string) $poll->question : '–' ); ?><br>
	Wahlzeitraum: <?php echo esc_html( vv_fmt( $poll->start_dt ) ); ?> – <?php echo esc_html( vv_fmt( $poll->end_dt ) ); ?><br>
	Status: <?php echo esc_html( $statuses[ $poll->status ] ?? $poll->status ); ?> ·
	Niederschrift erstellt am: <?php echo esc_html( vv_fmt( current_time( 'mysql' ) ) ); ?></p>

	<h2>1. Ergebnis</h2>
	<table>
		<thead><tr><th>Option</th><th>Stimmen</th><th>Anteil</th></tr></thead>
		<tbody>
		<?php foreach ( $results as $option => $row ) : ?>
			<tr><td><?php echo esc_html( ucfirst( $option ) ); ?></td>
			<td><?php echo (int) $row['count']; ?></td><td><?php echo esc_html( number_format( $row['percent'], 1 ) ); ?>%</td></tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2>2. Abgabefingerprint (Integrität der Urne)</h2>
	<p>Rezept: SHA-256 über alle Prüfcodes in aufsteigender Sortierung und die Zählungen (ja,nein,enthaltung).<br>
	Fingerprint: <code><?php // Output escaped in $fp_txt
		echo $fp_txt; // phpcs:ignore WordPress.Security.EscapeOutput -- dynamisch zusammengebaut und dort escaped ?></code></p>

	<h2>3. Prüfliste (<?php echo count( $ballots ); ?> Stimmen)</h2>
	<table>
		<thead><tr><th>Prüfcode</th><th>Stimme</th></tr></thead>
		<tbody>
		<?php foreach ( $ballots as $b ) : ?>
			<tr><td><code><?php echo esc_html( $b->receipt_hash ); ?></code></td><td><?php echo esc_html( ucfirst( $b->vote_option ) ); ?></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2>4. Teilnahmekette</h2>
	<?php if ( ! $voters ) : ?>
	<p>Keine Teilnehmerzeiten vorhanden (bereits gelöscht oder keine Personen importiert). Ergebnis und Prüfliste oben bleiben unverändert gültig.</p>
	<?php else : ?>
	<table>
		<thead><tr><th>Mitgliedsnr.</th><th>Name</th><th>E-Mail</th><th>Einladung</th><th>PIN gesetzt</th><th>Stimmabgabe</th></tr></thead>
		<tbody>
		<?php foreach ( $voters as $v ) : ?>
			<?php $name = trim( (string) $v->first_name . ' ' . (string) $v->last_name ); ?>
			<tr>
				<td><?php echo esc_html( $v->member_no ); ?></td>
				<td><?php echo esc_html( '' === $name ? '–' : $name ); ?></td>
				<td><?php echo esc_html( $v->email ); ?></td>
				<td><?php echo esc_html( vv_fmt( $v->invited_at ) ); ?></td>
				<td><?php echo esc_html( vv_fmt( $v->pin_set_at ) ); ?></td>
				<td><?php echo esc_html( vv_fmt( $v->voted_at ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

	<p style="margin-top:1.4em; font-size:.8em; color:#555">Erzeugt mit vereins-voting <?php echo esc_html( VEREINS_VOTING_VERSION ); ?>. Die Urne enthält ausschließlich Prüfcode und Stimme – ohne Personenbezug, ohne Zeitstempel.</p>
</body>
</html>
	<?php
	return (string) ob_get_clean();
}
