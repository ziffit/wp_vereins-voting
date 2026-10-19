<?php
/**
 * Admin-Backend: Abstimmungs-CRUD [FU002], CSV-Import [FU003],
 * Einladungsversand [FU004], Audit-/Beteiligungs-Übersicht [FU005].
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
		vv_render_poll_form( 'new' === $view ? 0 : ( isset( $_GET['poll'] ) ? absint( $_GET['poll'] ) : 0 ) );
	} elseif ( 'members' === $view) {
		$poll_id = isset( $_GET['poll'] ) ? absint( $_GET['poll'] ) : 0;
		$poll    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}voting_polls WHERE id = %d", $poll_id ) );
		if ( ! $poll ) {
			echo '<p>Abstimmung nicht gefunden.</p>';
		} else {
			vv_render_import_form( $poll );
			vv_render_invite_form( $poll );
			vv_render_members_table( $poll );
		}
	} else {
		// Standardansicht: Abstimmungsliste.
		echo '<a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=vereins-voting&view=new' ) ) . '">Neue Abstimmung</a>';

		if ( ! $polls ) {
			echo '<p>Noch keine Abstimmung angelegt.</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>Titel</th><th>Status</th><th>Zeitraum</th><th>Teilnehmer</th><th>Eingegangene Stimmen</th><th>Aktionen</th>'
			. '</tr></thead><tbody>';
		foreach ( $polls as $poll ) {
			$member_count = (int) $poll->member_count;
			$ballot_count = (int) $poll->ballot_count;

			$edit_url = admin_url( 'admin.php?page=vereins-voting&view=edit&poll=' . (int) $poll->id );
			$mem_url  = admin_url( 'admin.php?page=vereins-voting&view=members&poll=' . (int) $poll->id );

			echo '<tr>'
				. '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html( $poll->title ) . '</a></td>'
				. '<td>' . vv_status_label( $poll ) . '</td>'
				. '<td>' . esc_html( vv_fmt( $poll->start_dt ) . ' – ' . vv_fmt( $poll->end_dt ) ) . '</td>'
				. '<td>' . $member_count . '</td>'
				. '<td>' . $ballot_count . '</td>'
				. '<td><a href="' . esc_url( $mem_url ) . '">Teilnehmer & Einladung</a></td>'
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
		case 'delete_poll':
			vv_delete_poll();
			break;
	}
}
add_action( 'admin_init', 'vv_handle_admin_actions' );

/** [FU002#AK02] Löschen nur im Entwurf; Daten werden nie heimlich entsorgt. */
function vv_delete_poll() {
	global $wpdb;

	$poll_id = isset( $_POST['poll_id'] ) ? absint( $_POST['poll_id'] ) : 0;
	$status  = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}voting_polls WHERE id = %d", $poll_id ) );

	if ( 'draft' !== $status ) {
		wp_die( 'Löschen ist nur bei Abstimmungen im Status Entwurf möglich.' );
	}

	$wpdb->query( 'START TRANSACTION' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}voting_ballots WHERE poll_id = %d", $poll_id ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}voting_voters WHERE poll_id = %d", $poll_id ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}voting_polls WHERE id = %d", $poll_id ) );
	$wpdb->query( 'COMMIT' );

	wp_safe_redirect( admin_url( 'admin.php?page=vereins-voting&deleted=' . $poll_id ) );
	exit;
}

/** [FU002] Anlegen und Bearbeiten. [FU002#AK02] Zielstatus nur draft↔active↔closed in einer Richtung. */
function vv_save_poll() {
	global $wpdb;

	$poll_id = isset( $_POST['poll_id'] ) ? absint( $_POST['poll_id'] ) : 0;
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
	// [FU002#AK02] Bei aktiven/geschlossenen Abstimmungen sind die Zeitfelder
	// gesperrt und werden ignoriert – dann gilt die Prüfung nicht.
	$existing = null;
	if ( $poll_id && '' === $error ) {
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}voting_polls WHERE id = %d", $poll_id ) );
		if ( ! $existing ) {
			wp_die( 'Abstimmung nicht gefunden.' );
		}
	}
	if ( '' === $error && ( ! $existing || 'draft' === $existing->status ) && $end <= $start ) {
		$error = 'Das Ende muss nach dem Start liegen.';
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
		$inserted = $wpdb->insert( "{$wpdb->prefix}voting_polls", [
			'title'    => $title,
			'question' => $question,
			'start_dt' => $start,
			'end_dt'   => $end,
			'status'   => 'draft',
		] );
		if ( ! $inserted ) {
			wp_die( 'Abstimmung konnte nicht gespeichert werden.' );
		}
		$poll_id = (int) $wpdb->insert_id;
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
				$wpdb->update( "{$wpdb->prefix}voting_polls", [ 'status' => 'closed' ], [ 'id' => $poll_id ] );
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

	$poll_id = isset( $_POST['poll'] ) ? absint( $_POST['poll'] ) : 0;
	$poll    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}voting_polls WHERE id = %d", $poll_id ) );
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

	// Trennzeichen erkennen (Komma oder Semikolon) und Überschriftszeile überspringen.
	$delim   = ',';
	$preview = fgets( $handle );
	if ( is_string( $preview ) && strpos( $preview, ';' ) !== false ) {
		$delim = ';';
	}
	rewind( $handle );

	$process_row = function ( $row ) use ( $wpdb, $poll_id, &$imported, &$skipped ) {
		if ( ! is_array( $row ) || count( $row ) < 2 ) {
			return;
		}
		$member_no = sanitize_text_field( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $row[0] ) );
		$email     = sanitize_email( $row[1] );

		// Überschriftszeile (keine Ziffern in der Mitgliedsnummer) nicht als Fehler zählen.
		if ( '' === $member_no || '' === $email ) {
			return;
		}
		if ( ! is_email( $email ) ) {
			++$skipped;
			return;
		}

		// Duplikatschutz pro Abstimmung, E-Mail-Fall fällt hier normalisiert zusammen.
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}voting_voters WHERE poll_id = %d AND LOWER(email) = %s",
			$poll_id,
			strtolower( $email )
		) );
		if ( $exists ) {
			++$skipped;
			return;
		}

		$wpdb->insert( "{$wpdb->prefix}voting_voters", [
			'poll_id'          => $poll_id,
			'member_no'        => $member_no,
			'email'            => $email,
			'activation_token' => bin2hex( random_bytes( 16 ) ),
		] );
		++$imported;
	};

	$is_header_row = null;
	$wpdb->query( 'START TRANSACTION' );
	while ( ( $row = fgetcsv( $handle, 0, $delim ) ) !== false ) {
		if ( null === $is_header_row ) {
			$is_header_row = is_array( $row ) && count( $row ) >= 2
				&& preg_match( '/[a-zA-Z]/', (string) $row[0] )
				&& ! preg_match( '/\d/', (string) $row[0] )
				&& ! is_email( (string) $row[1] );
			if ( $is_header_row ) {
				continue;
			}
		}
		$process_row( $row );
	}
	fclose( $handle );
	$wpdb->query( 'COMMIT' );

	wp_safe_redirect( admin_url( 'admin.php?page=vereins-voting&view=members&poll=' . $poll_id
		. '&imported=' . $imported . '&skipped=' . $skipped ) );
	exit;
}

/** [FU004] Massenversand der Einladungen über wp_mail(). */
function vv_send_invites() {
	global $wpdb;

	$poll_id = isset( $_POST['poll'] ) ? absint( $_POST['poll'] ) : 0;

	// [FU006#AK01] Betreff/Text mit Platzhaltern; Vorlage wird beim Versand gespeichert.
	$defaults = vv_invite_body_defaults();

	$subject = sanitize_text_field( wp_unslash( $_POST['subject'] ?? $defaults['subject'] ) );
	$body    = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? $defaults['body'] ) );

	// Basis-URL der Aktivierungsseite muss der Admin mitgeben (Seite mit Shortcode [verein_voting_activate]).
	$activate_url = esc_url_raw( wp_unslash( $_POST['activate_url'] ?? '' ) );
	if ( ! $activate_url ) {
		wp_die( 'Bitte zuerst die URL der Aktivierungsseite angeben (Seite mit Shortcode [verein_voting_activate]).' );
	}

	$poll = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}voting_polls WHERE id = %d", $poll_id ) );
	if ( ! $poll ) {
		wp_die( 'Abstimmung nicht gefunden.' );
	}

	// [FU006#AK03] Zuletzt verwendete Vorlage bleibt erhalten.
	update_option( 'vv_invite_subject', $subject );
	update_option( 'vv_invite_body', $body );

	$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
	$sent    = 0;

	// [FU006#AK01] Vereinsname, Titel, Fristen, personalisierter Link.
	foreach ( (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT id, member_no, email, activation_token FROM {$wpdb->prefix}voting_voters
		 WHERE poll_id = %d AND activation_token IS NOT NULL",
		$poll_id
	) ) as $voter ) {
		$link = add_query_arg(
			[
				'poll'  => $poll_id,
				'token' => $voter->activation_token,
			],
			$activate_url
		);

		$find = [ '{site_name}', '{poll_title}', '{start}', '{end}', '{link}', '{member_no}' ];
		$replace = [
			get_bloginfo( 'name' ),
			$poll->title,
			vv_fmt( $poll->start_dt ),
			vv_fmt( $poll->end_dt ),
			$link,
			$voter->member_no,
		];
		$mail_subject = str_replace( $find, $replace, $subject );
		$mail_body    = str_replace( $find, $replace, $body );

		if ( wp_mail( $voter->email, $mail_subject, $mail_body, $headers ) ) {
			++$sent;
		}
	}

	wp_safe_redirect( admin_url( 'admin.php?page=vereins-voting&view=members&poll=' . $poll_id . '&sent=' . $sent ) );
	exit;
}

// ---------------------------------------------------------------------------
// Formulare
// ---------------------------------------------------------------------------

/** [FU002#AK01/#AK02] Anlegen/Bearbeiten; aktive/geschlossene Wahl = Felder gesperrt. */
function vv_render_poll_form( $poll_id = 0 ) {
	global $wpdb;

	$poll = $poll_id
		? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}voting_polls WHERE id = %d", $poll_id ) )
		: null;
	$locked = $poll && 'draft' !== $poll->status;

	// [FU002#AK05] Nach einem Eingabefehler die nicht gespeicherten Werte zeigen.
	$flash = $GLOBALS['vv_poll_form_flash'] ?? null;
	unset( $GLOBALS['vv_poll_form_flash'] );
	if ( $flash && (int) $flash['data']['poll_id'] !== (int) $poll_id ) {
		$flash = null;
	}
	$title_val    = $flash['data']['title'] ?? ( $poll->title ?? '' );
	$question_val = $flash['data']['question'] ?? ( $poll->question ?? '' );
	$start_val    = str_replace( ' ', 'T', $flash['data']['start_dt'] ?? ( $poll ? substr( (string) $poll->start_dt, 0, 16 ) : '' ) );
	$end_val      = str_replace( ' ', 'T', $flash['data']['end_dt'] ?? ( $poll ? substr( (string) $poll->end_dt, 0, 16 ) : '' ) );

	echo '<form method="post">';
	wp_nonce_field( 'vv_admin', 'vv_nonce' );
	echo '<input type="hidden" name="vv_action" value="save_poll">';
	echo '<input type="hidden" name="poll_id" value="' . (int) $poll_id . '">';
	if ( $flash ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $flash['error'] ) . '</p></div>';
	}
	if ( $locked ) {
		echo '<div class="notice notice-warning"><p>Diese Abstimmung läuft oder ist beendet – Titel/Fristen sind gesperrt.</p></div>';
	}
	echo '<table class="form-table">';
	echo '<tr><th><label for="vv-title">Titel</label></th><td><input name="title" id="vv-title" type="text" class="regular-text" maxlength="190" value="' . esc_attr( $title_val ) . '" ' . ( $locked ? 'readonly' : '' ) . ' required></td></tr>';
	echo '<tr><th><label for="vv-question">Frage / Beschreibung</label></th><td><textarea name="question" id="vv-question" rows="4" class="large-text" ' . ( $locked ? 'readonly' : '' ) . '>' . esc_textarea( $question_val ) . '</textarea></td></tr>';
	echo '<tr><th><label for="vv-start">Start</label></th><td><input name="start_dt" id="vv-start" type="datetime-local" value="' . esc_attr( $start_val ) . '" ' . ( $locked ? 'readonly' : '' ) . ' required></td></tr>';
	echo '<tr><th><label for="vv-end">Ende</label></th><td><input name="end_dt" id="vv-end" type="datetime-local" value="' . esc_attr( $end_val ) . '" ' . ( $locked ? 'readonly' : '' ) . ' required></td></tr>';

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
	echo '</table><p><button class="button button-primary" type="submit">Speichern</button> '
		. '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=vereins-voting' ) ) . '">Abbrechen</a></p></form>';

	// Löschen nur für Entwürfe.
	if ( $poll && 'draft' === $poll->status ) {
		echo '<form method="post" onsubmit="return confirm(\'Abstimmung samt aller Teilnehmerdaten wirklich löschen?\');" style="margin-top:1em">';
		wp_nonce_field( 'vv_admin', 'vv_nonce' );
		echo '<input type="hidden" name="vv_action" value="delete_poll">';
		echo '<input type="hidden" name="poll_id" value="' . (int) $poll_id . '">';
		echo '<button type="submit" class="button button-secondary" >Abstimmung löschen</button></form>';
	}
}

function vv_render_import_form( $poll ) {
	echo '<h2>Teilnehmer (CSV-Import)</h2>';
	echo '<p>CSV mit zwei Spalten: <code>Mitgliedsnummer;E-Mail</code> — eine Person pro Zeile, erste Zeile = Überschrift wird übersprungen.</p>';
	echo '<form method="post" enctype="multipart/form-data">';
	wp_nonce_field( 'vv_admin', 'vv_nonce' );
	echo '<input type="hidden" name="vv_action" value="import_csv">';
	echo '<input type="hidden" name="poll" value="' . (int) $poll->id . '">';
	echo '<p><input type="file" name="csv_file" accept=".csv,text/csv" required></p>';
	echo '<p><button type="submit" class="button">CSV importieren</button> '
		. '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=vereins-voting' ) ) . '">Abbrechen</a></p>';
	echo '</form>';
}

function vv_render_invite_form( $poll ) {
	global $wpdb;

	// Empfängerzahl für die Bestätigung: alle ohne PIN (activation_token noch vorhanden).
	$pending = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}voting_voters WHERE poll_id = %d AND activation_token IS NOT NULL",
		$poll->id
	) );

	// Aus einer persistierten Einstellung lesen (z. B. nach dem ersten Versand) – sonst Standard.
	$defaults = vv_invite_body_defaults();
	$subject  = get_option( 'vv_invite_subject', $defaults['subject'] );
	$body     = get_option( 'vv_invite_body', $defaults['body'] );
	?>
	<h2>Einladungen versenden</h2>
	<p>Link-Templates im Text: <code>{site_name} {poll_title} {start} {end} {member_no} {link}</code>.
		{link} ist der persönliche Aktivierungslink.</p>
	<form method="post" onsubmit="return confirm('Massenversand starten? Empfänger ohne PIN: <?php echo (int) $pending; ?>');">
		<?php wp_nonce_field( 'vv_admin', 'vv_nonce' ); ?>
		<input type="hidden" name="vv_action" value="send_invites">
		<input type="hidden" name="poll" value="<?php echo (int) $poll->id; ?>">
		<table class="form-table">
			<tr>
				<th><label for="vv-activate-url">URL der Aktivierungsseite</label></th>
				<td><input name="activate_url" id="vv-activate-url" type="url" class="large-text"
					placeholder="<?php echo esc_attr( home_url( '/pin-setzen/' ) ); ?>" required></td>
			</tr>
			<tr>
				<th><label for="vv-subject">Betreff</label></th>
				<td><input name="subject" id="vv-subject" type="text" class="large-text" value="<?php echo esc_attr( $subject ); ?>"></td>
			</tr>
			<tr>
				<th><label for="vv-body">E-Mail-Text</label></th>
				<td><textarea name="body" id="vv-body" rows="8" class="large-text"><?php echo esc_textarea( $body ); ?></textarea></td>
			</tr>
		</table>
		<p><button type="submit" class="button button-primary">Einladungen an alle ohne PIN versenden</button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=vereins-voting' ) ); ?>">Abbrechen</a></p>
	</form>
	<?php
}

/** [FU005#AK01] Beteiligung pro Person – ohne Blick auf den Inhalt der Stimme. */
function vv_render_members_table( $poll ) {
	global $wpdb;

	$per_page = 100;
	$total    = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}voting_voters WHERE poll_id = %d",
		$poll->id
	) );
	$pages = max( 1, (int) ceil( $total / $per_page ) );
	$page  = min( $pages, max( 1, isset( $_GET['vv_page'] ) ? absint( $_GET['vv_page'] ) : 1 ) );

	$voters = $wpdb->get_results( $wpdb->prepare(
		"SELECT member_no, email, has_voted, voted_at FROM {$wpdb->prefix}voting_voters
		 WHERE poll_id = %d ORDER BY member_no LIMIT %d OFFSET %d",
		$poll->id,
		$per_page,
		( $page - 1 ) * $per_page
	) );

	echo '<h2>Beteiligung</h2>';
	if ( isset( $_GET['saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Abstimmung gespeichert.</p></div>';
	}
	if ( isset( $_GET['imported'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>'
			. (int) $_GET['imported'] . ' importiert, ' . (int) ( $_GET['skipped'] ?? 0 ) . ' übersprungen.</p></div>';
	}
	if ( isset( $_GET['sent'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . (int) $_GET['sent'] . ' Einladungen versendet.</p></div>';
	}

	/** [FU005#AK02] Liste zeigt nur has_voted/voted_at – niemals eine Stimme. */
	if ( ! $voters ) {
		echo '<p>Noch keine Teilnehmer importiert.</p>';
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th>Mitgliedsnummer</th><th>E-Mail</th><th>Hat gewählt</th><th>Gewählt am</th></tr></thead><tbody>';
	foreach ( $voters as $v ) {
		echo '<tr>'
			. '<td>' . esc_html( $v->member_no ) . '</td>'
			. '<td>' . esc_html( $v->email ) . '</td>'
			. '<td>' . ( (int) $v->has_voted ? 'Ja' : 'Nein' ) . '</td>'
			. '<td>' . ( $v->voted_at ? esc_html( vv_fmt( $v->voted_at ) ) : '–' ) . '</td>'
			. '</tr>';
	}
	echo '</tbody></table>';

	// [FU005#AK01] Seitenweise Anzeige, damit große Vereine nicht alles auf einmal laden.
	if ( $pages > 1 ) {
		$base = admin_url( 'admin.php?page=vereins-voting&view=members&poll=' . (int) $poll->id );
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

	// [FU005#AK03] Aggregiertes Ergebnis nach Wahlende.
	if ( 'closed' === $poll->status ) {
		$results = vv_get_results( $poll->id );
		echo '<h2>Gesamtergebnis</h2><table class="widefat striped"><thead><tr><th>Option</th><th>Stimmen</th><th>Anteil</th></tr></thead><tbody>';
		foreach ( $results as $option => $row ) {
			echo '<tr><td>' . esc_html( ucfirst( $option ) ) . '</td><td>' . (int) $row['count'] . '</td><td>' . esc_html( number_format( $row['percent'], 1 ) ) . '%</td></tr>';
		}
		echo '</tbody></table>';
	}
}
