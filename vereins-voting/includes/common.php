<?php
/**
 * Gemeinsame Helfer: Datenzugriff, Validierung, Kein DB-Code in den Modulen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Falsche PIN als eigener Fehlertyp: Der Fehlversuch wird erst nach dem
 * ROLLBACK gezählt, weil Transienten in der Options-Tabelle liegen und
 * sonst mit der Stimm-Transaktion zurückgerollt würden.
 */
class VV_PinFail extends RuntimeException {}

/** Gemeinsame Helfer: Datenzugriff und Validierung; einheitliche Meldungen. */
function vv_get_results( $poll_id ) {
	global $wpdb;

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT vote_option, COUNT(*) AS n FROM {$wpdb->prefix}voting_ballots WHERE poll_id = %s GROUP BY vote_option",
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
			// Rohanteil; die Anzeige rundet einmalig mit number_format(x, 1).
			$results[ $option ]['percent'] = $data['count'] / $total * 100;
		}
	}
	return $results;
}

/**
 * [FU002#AK02] Manipulier-Sicherung (Abgabefingerprint): sha256 über alle
 * Receipt-Hashes in aufsteigender Sortierung und die Option-Zählungen.
 * Rezept (nachrechenbar aus der Prüfliste):
 *   sha256( hash1+hash2+…+ | ja=C,nein=C,enthaltung=C )
 * Beim Abschluss festgehalten; jede spätere Urnen-Änderung weicht ab.
 */
function vv_urn_fingerprint( $poll_id ) {
	global $wpdb;

	$hashes = (array) $wpdb->get_col( $wpdb->prepare(
		"SELECT receipt_hash FROM {$wpdb->prefix}voting_ballots WHERE poll_id = %s ORDER BY receipt_hash",
		$poll_id
	) );
	$results = vv_get_results( $poll_id );
	$counts  = $results['ja']['count'] . ',' . $results['nein']['count'] . ',' . $results['enthaltung']['count'];
	return hash( 'sha256', implode( '', $hashes ) . '|' . $counts );
}

/** Normalisiert datetime-local/ISO-Eingaben auf das MySQL-Format 'YYYY-MM-DD HH:MM:SS'. */
function vv_normalize_dt( $dt ) {
	$dt = trim( (string) $dt );
	if ( '' === $dt ) {
		return '';
	}
	$dt = str_replace( 'T', ' ', $dt );
	if ( 16 === strlen( $dt ) ) { // 'YYYY-MM-DD HH:MM' ohne Sekunden
		$dt .= ':00';
	}
	return $dt;
}

/** [FU002#AK07] Poll-ID als zufällige UUID v4 – nicht erratbar, keine Reihenfolge. */
function vv_uuid() {
	$b    = random_bytes( 16 );
	$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 ); // Version 4.
	$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 ); // Variante RFC 4122.
	return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
}

/** Poll-ID aus GET/POST lesen. Nur UUID-artige Strings (auch Altzahlen), sonst ''. */
function vv_poll_id( $raw ) {
	$raw = strtolower( trim( (string) $raw ) );
	return preg_match( '/^[a-z0-9-]{1,36}$/', $raw ) && '0' !== $raw ? $raw : '';
}

/**
 * Einfache Brute-Force-Bremse: höchstens 5 Fehlversuche je 15 Minuten.
 * Schlüssel sind gehasht, damit keine E-Mail/Token in Optionsnamen landet.
 */
function vv_rate_key( $scope, $id ) {
	return 'vv_rl_' . $scope . '_' . md5( (string) $id );
}

function vv_rate_blocked( $scope, $id ) {
	return (int) get_transient( vv_rate_key( $scope, $id ) ) >= 5;
}

function vv_rate_fail( $scope, $id ) {
	$key = vv_rate_key( $scope, $id );
	set_transient( $key, (int) get_transient( $key ) + 1, 15 * MINUTE_IN_SECONDS );
}

function vv_rate_clear( $scope, $id ) {
	delete_transient( vv_rate_key( $scope, $id ) );
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

	// [FU008#AK05] Brute-Force-Bremse vor der PIN-Prüfung.
	$rate_id = $poll_id . '|' . strtolower( trim( (string) $member_no ) );
	if ( vv_rate_blocked( 'vote', $rate_id ) ) {
		throw new RuntimeException( 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.' );
	}

	$wpdb->query( 'START TRANSACTION' );
	try {
		$now  = current_time( 'mysql' );
		$poll = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}voting_polls WHERE id = %s", $poll_id ) );

		if ( ! $poll ) {
			throw new RuntimeException( 'Abstimmung nicht gefunden.' );
		}

		// [FU008#AK02] Entwurf wird beim ersten gültigen Versuch innerhalb des
		// Zeitraums automatisch „active“ (atomare bedingte Aktualisierung).
		if ( 'draft' === $poll->status ) {
			$ns = vv_normalize_dt( $poll->start_dt );
			$ne = vv_normalize_dt( $poll->end_dt );
			if ( '' !== $ns && '' !== $ne && $ns <= $now && $now < $ne ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->prefix}voting_polls SET status = 'active' WHERE id = %s AND status = 'draft'",
					$poll_id
				) );
			}
			$poll = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}voting_polls WHERE id = %s", $poll_id ) );
		}

		$ns = vv_normalize_dt( $poll->start_dt );
		$ne = vv_normalize_dt( $poll->end_dt );
		// Status „active“ gilt nur innerhalb des Zeitraums – egal wie er gesetzt wurde.
		if ( 'active' !== $poll->status || '' === $ns || '' === $ne || $ns > $now || $now >= $ne ) {
			throw new RuntimeException( 'Die Abstimmung ist derzeit nicht aktiv (Status oder Zeitraum).' );
		}

		// Zeile atomar sperren FOR UPDATE, damit Doppelvoting ausgeschlossen ist.
		$voter = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, pin_hash, has_voted FROM {$wpdb->prefix}voting_voters
			 WHERE poll_id = %s AND member_no = %s AND email = %s FOR UPDATE",
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
			throw new VV_PinFail( 'Wahl-PIN ist falsch.' );
		}

		// [FU008#AK03] Nur Urnen-Spalten befüllen – keine Personenbeziehung in der Urne.
		$receipt = hash( 'sha256', 'vvreceipt|' . $poll_id . '|' . microtime( true ) . '|' . bin2hex( random_bytes( 16 ) ) );
		$affected = $wpdb->update(
			"{$wpdb->prefix}voting_voters",
			[ 'has_voted' => 1, 'voted_at' => $now ],
			[ 'id' => $voter->id ]
		);
		$ok1 = ( false !== $affected ) && ( (int) $affected >= 1 );
		$ok2 = false !== $wpdb->insert(
			"{$wpdb->prefix}voting_ballots",
			[ 'poll_id' => $poll_id, 'vote_option' => $option, 'receipt_hash' => $receipt ]
		);
		if ( ! $ok1 || ! $ok2 ) {
			throw new RuntimeException( 'Stimmabgabe fehlgeschlagen (Datenbank). Bitte später erneut versuchen.' );
		}

		$wpdb->query( 'COMMIT' );
		vv_rate_clear( 'vote', $rate_id );
		return $receipt;
	} catch ( \Throwable $e ) {
		$wpdb->query( 'ROLLBACK' );
		if ( $e instanceof VV_PinFail ) {
			vv_rate_fail( 'vote', $rate_id );
		}
		if ( $e instanceof RuntimeException ) {
			throw $e;
		}
		throw new RuntimeException( 'Stimmabgabe fehlgeschlagen (Datenbank). Bitte später erneut versuchen.' );
	}
}
