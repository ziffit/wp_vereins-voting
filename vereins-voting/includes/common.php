<?php
/**
 * Gemeinsame Helfer: Datenzugriff, Validierung, Kein DB-Code in den Modulen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Gemeinsame Helfer: Datenzugriff und Validierung; einheitliche Meldungen. */
function vv_get_results( $poll_id ) {
	global $wpdb;

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT vote_option, COUNT(*) AS n FROM {$wpdb->prefix}voting_ballots WHERE poll_id = %d GROUP BY vote_option",
		$poll_id
	) );

	$results = [
		'ja'         => [ 'count' => 0, 'percent' => 0.0 ],
		'nein'       => [ 'count' => 0, 'percent' => 0.0 ],
		'enthaltung' => [ 'count' => 0, 'percent' => 0.0 ],
	];
	$total = 0;
	foreach ( (array) $rows as $row ) {
		if ( ! isset( $results[ $row->vote_option ] ) ) {
			continue; // Unbekannte Optionen ignorieren, nicht lesen.
		}
		$results[ $row->vote_option ]['count'] = (int) $row->n;
		$total                                += (int) $row->n;
	}
	if ( $total > 0 ) {
		foreach ( $results as $option => $data ) {
			$results[ $option ]['percent'] = round( $data['count'] / $total * 100, 1 );
		}
	}
	return $results;
}

/** [FU006#AK01] Einladungstext-Vorlage mit Platzhalter-Syntax. */
function vv_invite_body_defaults() {
	return [
		'subject' => 'Einladung zur Abstimmung: {poll_title}',
		'body'    => "Hallo,\r\n\r\n"
			. "{site_name} bittet zur Abstimmung „{poll_title}“.\r\n"
			. "Wahlzeitraum: {start} – {end}.\r\n\r\n"
			. "Rufe den folgenden Link auf, um deine persönliche 6-stellige Wahl-PIN zu setzen:\r\n"
			. "{link}\r\n\r\n"
			. "Halte Mitgliedsnummer ({member_no}) und E-Mail-Adresse bereit.\r\n\r\n"
			. "Viele Grüße\r\n{site_name}",
	];
}

/**
 * [FU008#AK03] Stimmabgabe in einer Transaktion.
 * Gibt den Receipt-Hash zurück oder wirft RuntimeException mit verständlicher Meldung.
 */
function vv_cast_vote( $poll_id, $member_no, $email, $pin, $option ) {
	global $wpdb;

	$option = strtolower( trim( (string) $option ) );
	if ( ! in_array( $option, [ 'ja', 'nein', 'enthaltung' ], true ) ) {
		throw new RuntimeException( 'Bitte wähle Ja, Nein oder Enthaltung.' );
	}

	$wpdb->query( 'START TRANSACTION' );
	try {
		// [FU008#AK02] Zeitraum prüfen; Entwurf wird beim ersten gültigen Versuch automatisch „active“.
		$now = current_time( 'mysql' );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}voting_polls SET status = 'active'
			 WHERE id = %d AND status = 'draft' AND start_dt <= %s AND end_dt > %s",
			$poll_id,
			$now,
			$now
		) );
		$poll = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}voting_polls WHERE id = %d", $poll_id ) );

		if ( ! $poll ) {
			throw new RuntimeException( 'Abstimmung nicht gefunden.' );
		}
		// Status „active“ gilt nur innerhalb des Zeitraums – egal wie er gesetzt wurde.
		if ( 'active' !== $poll->status || $poll->start_dt > $now || $now >= $poll->end_dt ) {
			throw new RuntimeException( 'Die Abstimmung ist derzeit nicht aktiv (Status oder Zeitraum).' );
		}

		// Zeile atomar sperren FOR UPDATE, damit Doppelvoting ausgeschlossen ist.
		$voter = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, pin_hash, has_voted FROM {$wpdb->prefix}voting_voters
			 WHERE poll_id = %d AND member_no = %s AND email = %s FOR UPDATE",
			$poll_id,
			$member_no,
			$email
		) );

		if ( ! $voter ) {
			throw new RuntimeException( 'Mitgliedsnummer und E-Mail passen nicht zusammen.' );
		}

		if ( (int) $voter->has_voted !== 0 ) {
			throw new RuntimeException( 'Du hast für diese Abstimmung bereits abgestimmt.' );
		}

		if ( empty( $voter->pin_hash ) || ! password_verify( (string) $pin, $voter->pin_hash ) ) {
			throw new RuntimeException( 'Wahl-PIN ist falsch.' );
		}

		// [FU008#AK03] Nur Urnen-Spalten befüllen – keine Personenbeziehung in der Urne.
		$receipt = hash( 'sha256', 'vvreceipt|' . $poll_id . '|' . microtime( true ) . '|' . bin2hex( random_bytes( 16 ) ) );
		$ok1     = false !== $wpdb->update(
			"{$wpdb->prefix}voting_voters",
			[ 'has_voted' => 1, 'voted_at' => $now ],
			[ 'id' => $voter->id ]
		);
		$ok2     = false !== $wpdb->insert(
			"{$wpdb->prefix}voting_ballots",
			[ 'poll_id' => $poll_id, 'vote_option' => $option, 'receipt_hash' => $receipt ]
		);
		if ( ! $ok1 || ! $ok2 ) {
			throw new RuntimeException( 'Stimmabgabe fehlgeschlagen (Datenbank). Bitte später erneut versuchen.' );
		}

		$wpdb->query( 'COMMIT' );
		return $receipt;
	} catch ( RuntimeException $e ) {
		$wpdb->query( 'ROLLBACK' );
		throw $e;
	}
}
