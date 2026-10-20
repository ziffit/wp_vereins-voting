<?php
/**
 * [FU004] Gedrosselter Einladungsversand.
 *
 * Der Versand läuft in Batches über WP-Cron. Ein globales 5-Minuten-Fenster
 * begrenzt die Zahl der Versuche; ein Lock verhindert parallele Läufe. Jede
 * erfolgreiche Mail bekommt invited_at, ein Fehlversuch zählt invite_attempts.
 * Pausieren wirkt auf den nächsten Batch; ein bereits gestarteter Batch läuft zu
 * Ende. Nach drei Fehlversuchen sendet der Automat nicht weiter; der Admin kann
 * manuell erneut starten. Es werden niemals Mitgliedsdaten geloggt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Queue-Status-Optionen je Abstimmung (Poll-IDs sind keine Personendaten). */
function vv_queue_active_key( $poll_id ) {
	return 'vv_send_active_' . $poll_id;
}

function vv_queue_paused_key( $poll_id ) {
	return 'vv_send_paused_' . $poll_id;
}

function vv_queue_is_active( $poll_id ) {
	return (bool) get_option( vv_queue_active_key( $poll_id ), false );
}

function vv_queue_is_paused( $poll_id ) {
	return (bool) get_option( vv_queue_paused_key( $poll_id ), false );
}

/** Nur nicht geschlossene Abstimmungen innerhalb ihres Zeitraums erhalten Mail. */
function vv_queue_poll_sendable( $poll ) {
	return $poll
		&& 'closed' !== $poll->status
		&& vv_normalize_dt( $poll->end_dt ) > current_time( 'mysql' );
}

/** Zählt offene, gesendete und endgültig fehlgeschlagene Einladungen. */
function vv_poll_mail_counts( $poll_id ) {
	global $wpdb;
	$base = "FROM {$wpdb->prefix}voting_voters WHERE poll_id = %s AND activation_token IS NOT NULL AND activation_token <> ''";
	$pending = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) $base AND invited_at IS NULL AND invite_attempts < 3",
		$poll_id
	) );
	$sent = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}voting_voters WHERE poll_id = %s AND invited_at IS NOT NULL",
		$poll_id
	) );
	$failed = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) $base AND invited_at IS NULL AND invite_attempts >= 3",
		$poll_id
	) );
	return [ 'pending' => $pending, 'sent' => $sent, 'failed' => $failed ];
}

/** Alle aktiven, nicht pausierten Abstimmungen mit offenen Einladungen. */
function vv_queue_pending_polls() {
	global $wpdb;

	$polls = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.* FROM {$wpdb->prefix}voting_polls p
		 WHERE p.status <> 'closed'
		   AND p.end_dt IS NOT NULL
		   AND p.end_dt > %s
		   AND EXISTS (
			 SELECT 1 FROM {$wpdb->prefix}voting_voters v
			 WHERE v.poll_id = p.id AND v.activation_token IS NOT NULL AND v.activation_token <> ''
			   AND v.invited_at IS NULL AND v.invite_attempts < 3
		   )",
		current_time( 'mysql' )
	) );

	$active = [];
	foreach ( (array) $polls as $poll ) {
		if ( vv_queue_is_active( $poll->id ) && ! vv_queue_is_paused( $poll->id ) ) {
			$active[] = $poll;
		}
	}
	return $active;
}

/** Räumt aktive Markierungen ab, wenn nichts Offenes mehr da ist. */
function vv_queue_cleanup_active() {
	global $wpdb;

	$polls = $wpdb->get_results( "SELECT id, status, end_dt FROM {$wpdb->prefix}voting_polls" );
	foreach ( (array) $polls as $poll ) {
		if ( ! vv_queue_is_active( $poll->id ) ) {
			continue;
		}
		if ( ! vv_queue_poll_sendable( $poll ) ) {
			delete_option( vv_queue_active_key( $poll->id ) );
			delete_option( vv_queue_paused_key( $poll->id ) );
			continue;
		}
		$pending = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}voting_voters
			 WHERE poll_id = %s AND activation_token IS NOT NULL AND activation_token <> ''
			   AND invited_at IS NULL AND invite_attempts < 3",
			$poll->id
		) );
		if ( 0 === $pending ) {
			delete_option( vv_queue_active_key( $poll->id ) );
			delete_option( vv_queue_paused_key( $poll->id ) );
		}
	}
}

/** Mitglieder-Seite einer Abstimmung; $args sind Query-Argumente wie [ 'queued' => 1 ]. */
function vv_members_url( $poll_id, $args = [] ) {
	return add_query_arg(
		array_merge( [ 'page' => 'vereins-voting', 'view' => 'members', 'poll' => (string) $poll_id ], $args ),
		admin_url( 'admin.php' )
	);
}

/** Liest die POST-Poll-ID; nur vorhandene, sendbare Abstimmungen dürfen weiter. */
function vv_queue_poll_from_post() {
	global $wpdb;

	$poll_id = vv_poll_id( wp_unslash( $_POST['poll'] ?? '' ) );
	$poll    = $poll_id ? $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}voting_polls WHERE id = %s",
		$poll_id
	) ) : null;
	if ( ! $poll ) {
		wp_die( 'Abstimmung nicht gefunden.' );
	}
	if ( ! vv_queue_poll_sendable( $poll ) ) {
		wp_die( 'Der Versand ist für diese Abstimmung nicht mehr möglich.' );
	}
	return [ $poll_id, $poll ];
}

/** Nächstes WP-Cron-Ereignis; force räumt einen alten Termin weg. */
function vv_queue_schedule( $delay = 1, $force = false ) {
	if ( ! function_exists( 'wp_schedule_single_event' ) ) {
		return;
	}
	if ( $force && function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'vv_send_queue_tick' );
	}
	if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( 'vv_send_queue_tick' ) ) {
		return;
	}
	wp_schedule_single_event( time() + max( 1, (int) $delay ), 'vv_send_queue_tick' );
}

function vv_queue_window() {
	$window = get_option( 'vv_send_window', [] );
	if ( ! is_array( $window ) || empty( $window['start'] ) ) {
		$window = [ 'start' => time(), 'count' => 0 ];
	}
	if ( time() >= (int) $window['start'] + 5 * MINUTE_IN_SECONDS ) {
		$window = [ 'start' => time(), 'count' => 0 ];
	}
	return $window;
}

/** Ein Versandversuch verbraucht einen Platz im globalen Fenster. */
function vv_queue_take_slot() {
	$window = vv_queue_window();
	if ( (int) $window['count'] >= vv_mail_rate() ) {
		update_option( 'vv_send_window', $window );
		return false;
	}
	++$window['count'];
	update_option( 'vv_send_window', $window );
	return true;
}

function vv_queue_slot_available() {
	$window = vv_queue_window();
	return (int) $window['count'] < vv_mail_rate();
}

function vv_queue_window_delay() {
	$window = vv_queue_window();
	return max( 1, (int) $window['start'] + 5 * MINUTE_IN_SECONDS - time() );
}

/** Plant den nächsten Lauf, wenn noch aktive, offene Einladungen existieren. */
function vv_queue_reschedule() {
	if ( ! vv_queue_pending_polls() ) {
		return;
	}
	vv_queue_schedule( vv_queue_slot_available() ? 1 : vv_queue_window_delay() );
}

/**
 * [FU004] Einen Batch senden. Gibt die Zahl der in diesem Lauf erfolgreich
 * versendeten Mails zurück. Fehler werden nur als Zähler gespeichert.
 */
function vv_run_send_queue() {
	global $wpdb;

	if ( get_transient( 'vv_send_lock' ) ) {
		vv_queue_schedule( 1 ); // Ein laufender Batch räumt selbst auf; trotzdem Termin halten.
		return 0;
	}
	set_transient( 'vv_send_lock', 1, 10 * MINUTE_IN_SECONDS );

	$sent = 0;
	try {
		if ( '' !== vv_mail_settings_error() ) {
			return 0;
		}

		$polls = vv_queue_pending_polls();
		if ( ! $polls ) {
			vv_queue_cleanup_active();
			return 0;
		}

		$poll_by_id = [];
		$ids         = [];
		foreach ( $polls as $poll ) {
			$poll_by_id[ $poll->id ] = $poll;
			$ids[]                   = $poll->id;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$limit        = vv_mail_rate();
		$args         = array_merge( $ids, [ $limit ] );
		$voters       = $wpdb->get_results( $wpdb->prepare(
			"SELECT v.* FROM {$wpdb->prefix}voting_voters v
			 WHERE v.poll_id IN ($placeholders)
			   AND v.activation_token IS NOT NULL AND v.activation_token <> ''
			   AND v.invited_at IS NULL
			   AND v.invite_attempts < 3
			 ORDER BY v.id ASC
			 LIMIT %d",
			...$args
		) );

		foreach ( (array) $voters as $voter ) {
			$poll = $poll_by_id[ $voter->poll_id ] ?? null;
			if ( ! $poll ) {
				continue;
			}
			if ( ! vv_queue_poll_sendable( $poll ) ) {
				delete_option( vv_queue_active_key( $poll->id ) );
				delete_option( vv_queue_paused_key( $poll->id ) );
				continue;
			}
			if ( ! vv_queue_take_slot() ) {
				break;
			}

			$parts = vv_mail_parts( $poll, $voter );
			if ( vv_mail_send_parts( $voter->email, $parts ) ) {
				$wpdb->update(
					"{$wpdb->prefix}voting_voters",
					[ 'invited_at' => current_time( 'mysql' ), 'invite_attempts' => 0 ],
					[ 'id' => (int) $voter->id ]
				);
				++$sent;
			} else {
				// Nur der Zähler; keine Mailadresse, kein Token im Log.
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->prefix}voting_voters SET invite_attempts = invite_attempts + 1 WHERE id = %d",
					(int) $voter->id
				) );
			}
		}

		vv_queue_cleanup_active();
		vv_queue_reschedule();
	} finally {
		delete_transient( 'vv_send_lock' );
	}

	return $sent;
}
add_action( 'vv_send_queue_tick', 'vv_run_send_queue' );

/** [FU004] Versand starten: markieren, planen und einen ersten Batch senden. */
function vv_send_invites() {
	[ $poll_id ] = vv_queue_poll_from_post();

	$error = vv_mail_settings_error();
	if ( '' !== $error ) {
		wp_die( $error . ' Bitte unter Vereinsvoting → E-Mail-Einstellungen ergänzen.' );
	}

	$counts = vv_poll_mail_counts( $poll_id );
	if ( 0 === $counts['pending'] ) {
		delete_option( vv_queue_active_key( $poll_id ) );
		delete_option( vv_queue_paused_key( $poll_id ) );
		wp_safe_redirect( vv_members_url( $poll_id, [ 'queued' => 0 ] ) );
		exit;
	}

	update_option( vv_queue_active_key( $poll_id ), 1 );
	update_option( vv_queue_paused_key( $poll_id ), 0 );
	vv_queue_schedule( 1, true );
	$sent = vv_run_send_queue();

	wp_safe_redirect( vv_members_url( $poll_id, [ 'queued' => 1, 'sent' => (int) $sent ] ) );
	exit;
}

/** [FU004] Pause: Der aktuelle Batch ist beendet, der nächste wartet. */
function vv_pause_send() {
	[ $poll_id ] = vv_queue_poll_from_post();

	if ( ! vv_queue_is_active( $poll_id ) ) {
		wp_die( 'Der Versand läuft für diese Abstimmung nicht.' );
	}
	update_option( vv_queue_paused_key( $poll_id ), 1 );
	wp_safe_redirect( vv_members_url( $poll_id, [ 'paused' => 1 ] ) );
	exit;
}

/** [FU004] Fortsetzen: Pause weg, sofort ein Batch, danach Cron. */
function vv_resume_send() {
	[ $poll_id ] = vv_queue_poll_from_post();

	$error = vv_mail_settings_error();
	if ( '' !== $error ) {
		wp_die( $error . ' Bitte unter Vereinsvoting → E-Mail-Einstellungen ergänzen.' );
	}
	update_option( vv_queue_active_key( $poll_id ), 1 );
	update_option( vv_queue_paused_key( $poll_id ), 0 );
	vv_queue_schedule( 1, true );
	$sent = vv_run_send_queue();

	wp_safe_redirect( vv_members_url( $poll_id, [ 'resumed' => 1, 'sent' => (int) $sent ] ) );
	exit;
}

/** [FU004] Manueller Retry nach drei Fehlversuchen. */
function vv_retry_send() {
	global $wpdb;

	[ $poll_id ] = vv_queue_poll_from_post();

	$error = vv_mail_settings_error();
	if ( '' !== $error ) {
		wp_die( $error . ' Bitte unter Vereinsvoting → E-Mail-Einstellungen ergänzen.' );
	}

	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}voting_voters
		 SET invite_attempts = 0
		 WHERE poll_id = %s AND activation_token IS NOT NULL AND activation_token <> ''
		   AND invited_at IS NULL AND invite_attempts >= 3",
		$poll_id
	) );

	update_option( vv_queue_active_key( $poll_id ), 1 );
	update_option( vv_queue_paused_key( $poll_id ), 0 );
	vv_queue_schedule( 1, true );
	$sent = vv_run_send_queue();

	wp_safe_redirect( vv_members_url( $poll_id, [ 'retried' => 1, 'sent' => (int) $sent ] ) );
	exit;
}
