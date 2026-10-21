<?php
/**
 * Frontend-Shortcodes:
 * [verein_voting_activate] – PIN-Registrierung per E-Mail-Link   [FU007]
 * [verein_voting_form]     – Stimmabgabe                        [FU008]
 * [verein_voting_results]  – Ergebnis + Prüf-Hash-Liste         [FU009]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ein Stylesheet genügt: erst registrieren (main-Plugin), danach pro Shortcode enqueued.
function vv_enqueue_frontend_style() {
	wp_enqueue_style( 'vv-frontend' );
}

add_shortcode( 'verein_voting_activate', 'vv_shortcode_activate' );
add_shortcode( 'verein_voting_form', 'vv_shortcode_form' );
add_shortcode( 'verein_voting_results', 'vv_shortcode_results' );

/** Erkennt unsere Wahl-Shortcodes im Seiteninhalt (auch zusammengeführte Blöcke). */
function vv_page_has_voting_shortcode( $content ) {
	return (bool) preg_match( '/\[verein_voting_(activate|form|results)\b/', (string) $content );
}

/**
 * [FU008#AK05] Shortcode-Seiten niemals aus einem Seiten-Cache ausliefern:
 * URLs enthalten Token bzw. Poll-ID, eine zwischengespeicherte Aktivierungsseite
 * könnte ansonsten fremde Personen mit einem unbenutzten Token erreichen.
 */
function vv_maybe_nocache_page() {
	if ( is_singular() ) {
		$post = get_post();
		if ( $post && vv_page_has_voting_shortcode( $post->post_content ) ) {
			nocache_headers();
		}
	}
}
add_action( 'wp', 'vv_maybe_nocache_page' );

/** [FU007#AK05] Versionszeile – jede Shortcode-Ausgabe nennt die Plugin-Version. */
function vv_version_note() {
	return '<p class="vv-version">vereins-voting ' . esc_html( VEREINS_VOTING_VERSION ) . '</p>';
}

/** Eingegebene Mitglieds-Nr/E-Mail/PIN nach Submit wieder anzeigen. */
function vv_re( $existing, $fallback ) {
	return isset( $_POST[ $existing ] ) ? sanitize_text_field( wp_unslash( $_POST[ $existing ] ) ) : $fallback;
}

// ---------------------------------------------------------------------------
// [FU007] PIN-Registrierung
// ---------------------------------------------------------------------------

function vv_shortcode_activate() {
	return vv_activate_html() . vv_version_note();
}

function vv_activate_html() {
	global $wpdb;

	vv_enqueue_frontend_style();

	$poll_id = vv_poll_id( wp_unslash( $_GET['poll'] ?? '' ) );
	$token   = isset( $_GET['token'] ) ? preg_replace( '/[^a-zA-Z0-9]/', '', wp_unslash( $_GET['token'] ) ) : '';
	$error   = '';

	$voter = null;
	if ( $poll_id && $token ) {
		$voter = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}voting_voters WHERE poll_id = %s AND activation_token = %s",
			$poll_id,
			$token
		) );
	}

	// [FU007#AK02] Formular-Proposal: Mitgliedsnummer + E-Mail müssen zum Token passen.
	if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) === 'POST' && $voter ) {
		$rate_id = 'activate|' . $token;
		if ( vv_rate_blocked( 'activate', $rate_id ) ) {
			$error = 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.';
		} elseif ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['vv_frontend_nonce'] ?? '' ) ), 'vv_frontend' ) ) {
			// [FU007#AK04] CSRF-Schutz.
			$error = 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden und erneut versuchen.';
		} else {
			$member_no = sanitize_text_field( wp_unslash( $_POST['member_no'] ?? '' ) );
			$email     = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$pin       = preg_replace( '/\D/', '', wp_unslash( $_POST['pin'] ?? '' ) );
			$pin2      = preg_replace( '/\D/', '', wp_unslash( $_POST['pin2'] ?? '' ) );

			if ( ! hash_equals( (string) $voter->member_no, $member_no ) || ! hash_equals( strtolower( $voter->email ), strtolower( $email ) ) ) {
				vv_rate_fail( 'activate', $rate_id );
				$error = 'Mitgliedsnummer oder E-Mail stimmen nicht mit der Einladung überein.';
			} elseif ( strlen( $pin ) !== 6 || $pin !== $pin2 ) {
				vv_rate_fail( 'activate', $rate_id );
				$error = 'Die PIN muss 6-stellig sein und in beiden Feldern gleich lauten.';
			} elseif ( ! empty( $voter->pin_hash ) ) {
				$error = 'Für diese Einladung wurde bereits eine PIN gesetzt. Nutze direkt das Stimmformular.';
			} else {
				$ok = $wpdb->update(
					"{$wpdb->prefix}voting_voters",
					[
						'pin_hash'         => password_hash( $pin, PASSWORD_BCRYPT, [ 'cost' => 12 ] ),
						'pin_set_at'       => current_time( 'mysql' ),
						'activation_token' => null,
					],
					[ 'id' => $voter->id ]
				);
				if ( $ok ) {
					vv_rate_clear( 'activate', $rate_id );
					// [FU007#AK03] Erfolg + Token einmalig verbraucht.
					return '<div class="vv-box vv-success"><strong>Erfolgreich – deine Wahl-PIN ist gesetzt.</strong><br>'
						. 'Nutze während des Wahlzeitraums die Seite mit dem Stimmformular, um abzustimmen. '
						. 'Halte Mitgliedsnummer, E-Mail und deine 6-stellige PIN bereit.</div>';
				}
				$error = 'Speichern fehlgeschlagen. Bitte erneut versuchen.';
			}
		}
	}

	if ( ! $poll_id || ! $token || ! $voter ) {
		return '<div class="vv-box vv-error"><strong>Ungültiger oder bereits benutzter Aktivierungslink.</strong><br>'
			. 'Bitte nutze den Link aus deiner Einladungs-E-Mail.</div>';
	}

	// Formular: Mitgliedsdaten vorbefüllen (E-Mail aus Einladung = Prefill).
	ob_start();
	?>
	<form method="post" class="vv-form">
		<?php wp_nonce_field( 'vv_frontend', 'vv_frontend_nonce' ); ?>
		<p><strong>Abstimmung:</strong> <?php echo esc_html( vv_poll_title( $poll_id ) ); ?></p>
		<label for="vv-member-no">Mitgliedsnummer
			<input type="text" id="vv-member-no" name="member_no" required value="">
		</label>
		<label for="vv-email">E-Mail
			<input type="email" id="vv-email" name="email" required value="">
		</label>
		<label for="vv-pin">Neue Wahl-PIN (6 Ziffern)
			<input type="password" id="vv-pin" name="pin" pattern="[0-9]{6}" maxlength="6" minlength="6" inputmode="numeric" autocomplete="new-password" required>
		</label>
		<label for="vv-pin2">PIN bestätigen
			<input type="password" id="vv-pin2" name="pin2" pattern="[0-9]{6}" maxlength="6" minlength="6" inputmode="numeric" autocomplete="new-password" required>
		</label>
		<button type="submit" class="vv-btn">Wahl-PIN setzen</button>
	</form>
	<?php
	return ob_get_clean() . ( $error ? '<div class="vv-box vv-error">' . esc_html( $error ) . '</div>' : '' );
}

// ---------------------------------------------------------------------------
// [FU008] Stimmabgabe
// ---------------------------------------------------------------------------

function vv_shortcode_form() {
	return vv_form_html() . vv_version_note();
}

function vv_form_html() {
	global $wpdb;

	vv_enqueue_frontend_style();

	$poll_id = vv_poll_id( wp_unslash( $_GET['poll'] ?? '' ) );
	$error   = '';

	if ( ! $poll_id ) {
		return '<div class="vv-box vv-error">Keine Abstimmung ausgewählt (URL-Parameter ?poll= fehlt).</div>';
	}

	if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) === 'POST' ) {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['vv_frontend_nonce'] ?? '' ) ), 'vv_frontend' ) ) {
			// [FU008#AK05] CSRF-Schutz.
			$error = 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden und erneut versuchen.';
		} else {
			try {
				$receipt = vv_cast_vote(
					$poll_id,
					sanitize_text_field( wp_unslash( $_POST['member_no'] ?? '' ) ),
					sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
					preg_replace( '/\D/', '', wp_unslash( $_POST['pin'] ?? '' ) ),
					sanitize_key( wp_unslash( $_POST['option'] ?? '' ) )
				);

				// [FU008#AK04] Formular ausblenden, ReceiptHash anzeigen.
				return '<div class="vv-box vv-success"><strong>Deine Stimme wurde gezählt. Danke!</strong><br>'
					. 'Dein persönlicher Prüfcode (Receipt-Hash):<br><code class="vv-receipt">' . esc_html( $receipt ) . '</code><br>'
					. '<small>Notiere dir die ersten 5 Stellen des Codes – deine Stimme ist damit normalerweise eindeutig auffindbar. '
					. 'Damit kannst du nach Wahlende prüfen, dass deine Stimme in der Urne liegt.</small></div>';
			} catch ( \Throwable $e ) {
				$error = $e->getMessage();
			}
		}
	}

	$poll = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}voting_polls WHERE id = %s", $poll_id ) );
	if ( ! $poll ) {
		return '<div class="vv-box vv-error">Abstimmung nicht gefunden.</div>';
	}

	// [FU008#AK06] Das Formular erscheint nur im offenen Zeitraum. Vorher/
	// nachher steht dort, wann die Abstimmung aktiv ist bzw. war.
	$now  = current_time( 'mysql' );
	$from = vv_normalize_dt( $poll->start_dt );
	$to   = vv_normalize_dt( $poll->end_dt );
	if ( 'closed' === $poll->status || '' === $from || '' === $to || $now < $from || $now >= $to ) {
		$msg = ( '' !== $from && $now < $from )
			? 'Die Abstimmung ist noch nicht aktiv. Sie ist vom ' . vv_fmt( $from ) . ' bis ' . vv_fmt( $to ) . ' geöffnet.'
			: 'Die Abstimmung ist beendet (Zeitraum: ' . vv_fmt( $from ) . ' – ' . vv_fmt( $to ) . ').';
		return '<div class="vv-box vv-info"><strong>Abstimmung „' . esc_html( $poll->title ) . '“</strong><br>'
			. esc_html( $msg ) . '</div>'
			. ( $error ? '<div class="vv-box vv-error">' . esc_html( $error ) . '</div>' : '' );
	}

	ob_start();
	?>
	<form method="post" class="vv-form">
		<?php wp_nonce_field( 'vv_frontend', 'vv_frontend_nonce' ); ?>
		<p><strong>Abstimmung:</strong> <?php echo esc_html( $poll->title ); ?></p>
		<?php if ( ! empty( $poll->question ) ) { ?>
			<p><?php echo esc_html( $poll->question ); ?></p>
		<?php } ?>
		<p class="vv-window">Wahlzeitraum: <?php echo esc_html( vv_fmt( $poll->start_dt ) ); ?> – <?php echo esc_html( vv_fmt( $poll->end_dt ) ); ?></p>

		<label for="vv-member-no">Mitgliedsnummer
			<input type="text" id="vv-member-no" name="member_no" required value="<?php echo esc_attr( vv_re( 'member_no', '' ) ); ?>">
		</label>
		<label for="vv-email">E-Mail
			<input type="email" id="vv-email" name="email" required value="<?php echo esc_attr( vv_re( 'email', '' ) ); ?>">
		</label>
		<label for="vv-pin">Wahl-PIN
			<input type="password" id="vv-pin" name="pin" pattern="[0-9]{6}" maxlength="6" inputmode="numeric" required>
		</label>

		<fieldset>
			<legend>Deine Stimme</legend>
			<label class="vv-option"><input type="radio" name="option" value="ja" required> Ja</label>
			<label class="vv-option"><input type="radio" name="option" value="nein"> Nein</label>
			<label class="vv-option"><input type="radio" name="option" value="enthaltung"> Enthaltung</label>
		</fieldset>

		<button type="submit" class="vv-btn">Stimme abgeben</button>
	</form>
	<?php
	return ob_get_clean() . ( $error ? '<div class="vv-box vv-error">' . esc_html( $error ) . '</div>' : '' );
}

// ---------------------------------------------------------------------------
// [FU009] Ergebnisse & Prüfliste
// ---------------------------------------------------------------------------

function vv_shortcode_results() {
	return vv_results_html() . vv_version_note();
}

function vv_results_html() {
	global $wpdb;

	vv_enqueue_frontend_style();

	$poll_id = vv_poll_id( wp_unslash( $_GET['poll'] ?? '' ) );
	if ( ! $poll_id ) {
		return '<div class="vv-box vv-error">Keine Abstimmung ausgewählt (URL-Parameter ?poll= fehlt).</div>';
	}

	$poll = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}voting_polls WHERE id = %s", $poll_id ) );
	if ( ! $poll ) {
		return '<div class="vv-box vv-error">Abstimmung nicht gefunden.</div>';
	}

	// Wirkt geschlossen, sobald der Zeitraum vorbei ist (auch wenn der Admin
	// vergessen hatte, den Status zu setzen).
	$expired = vv_normalize_dt( $poll->end_dt ) <= current_time( 'mysql' );
	$show_results = ( 'closed' === $poll->status ) || ( 'active' === $poll->status && $expired );

	if ( $show_results ) {
		// [FU009#AK02] Endgültiges Ergebnis.
		$results = vv_get_results( $poll_id );
		$label   = 'closed' === $poll->status ? 'Ergebnis' : 'Ergebnis (Wahlzeitraum abgelaufen)';
		$html = '<h3>' . esc_html( $label ) . ': ' . esc_html( $poll->title ) . '</h3>';
		if ( ! empty( $poll->question ) ) {
			$html .= '<p>' . esc_html( $poll->question ) . '</p>';
		}
		$html .= '<table class="vv-table"><thead><tr><th>Option</th><th>Stimmen</th><th>Anteil</th></tr></thead><tbody>';
		foreach ( $results as $option => $row ) {
			$html .= '<tr><td>' . esc_html( ucfirst( $option ) ) . '</td><td>' . (int) $row['count'] . '</td><td>' . esc_html( number_format( $row['percent'], 1 ) ) . '%</td></tr>';
		}
		$html .= '</tbody></table>';

		// [FU009#AK04] Abgabefingerprint: mit dem Wert aus einem früheren Abruf
		// vergleichen – Abweichung heißt: Urne wurde nachträglich verändert.
		$html .= '<p class="vv-fingerprint">Abgabefingerprint: <code>' . esc_html( vv_urn_fingerprint( $poll_id ) ) . '</code><br>'
			. '<span class="vv-version">Referenzwert = SHA-256 aus allen Prüfcodes (sortiert) und den Zählungen; er muss mit dem Wert übereinstimmen, den das Backend beim formellen Abschluss festhält.</span></p>';

		// [FU009#AK03] Vollständige anonyme Prüftabelle – ohne Uhrzeit und in
		// Prüfcode-Reihenfolge, damit sie keine Rückschlüsse auf Zeitpunkt oder
		// Person erlaubt (Korrelation mit „Gewählt am“ im Backend ausschließen).
		$ballots = $wpdb->get_results( $wpdb->prepare(
			"SELECT receipt_hash, vote_option FROM {$wpdb->prefix}voting_ballots WHERE poll_id = %s ORDER BY receipt_hash",
			$poll_id
		) );
		$html .= '<h3>Prüfliste (anonym)</h3>';
		$html .= '<p>Suche in der Liste deinen Prüfcode aus der Bestätigung – die ersten fünf Zeichen sind fett hervorgehoben. '
			. 'Dann siehst du, ob deine Stimme korrekt gezählt wurde. Die Liste enthält keine Personen.</p>';
		$html .= '<table class="vv-table"><thead><tr><th>Prüfcode</th><th>Stimme</th></tr></thead><tbody>';
		foreach ( $ballots as $b ) {
			$html .= '<tr><td><code><strong>' . esc_html( substr( $b->receipt_hash, 0, 5 ) ) . '</strong>'
				. esc_html( substr( $b->receipt_hash, 5 ) ) . '</code></td><td>' . esc_html( ucfirst( $b->vote_option ) ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		return $html;
	}

	if ( 'active' === $poll->status ) {
		// [FU009#AK01] Hinweis, solange die Wahl läuft.
		return '<div class="vv-box vv-info"><strong>Die Abstimmung „' . esc_html( $poll->title ) . '“ läuft noch.</strong><br>'
			. 'Das Ergebnis wird erst nach Wahlende (' . esc_html( vv_fmt( $poll->end_dt ) ) . ') veröffentlicht.</div>';
	}

	return '<div class="vv-box vv-info">Diese Abstimmung hat noch nicht begonnen.</div>';
}

// ---------------------------------------------------------------------------
// Kleinkram
// ---------------------------------------------------------------------------

/** Datumsformat für Frontend. $dt = 'YYYY-MM-DD HH:MM:SS'. */
function vv_fmt( $dt ) {
	$dt = vv_normalize_dt( $dt );
	if ( '' === $dt ) {
		return '–';
	}
	$ts = mysql2date( 'U', $dt );
	if ( ! $ts ) {
		return '–';
	}
	return date_i18n( 'd.m.Y, H:i', $ts );
}

/** Titel einer Abstimmung. */
function vv_poll_title( $poll_id ) {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare(
		"SELECT title FROM {$wpdb->prefix}voting_polls WHERE id = %s",
		$poll_id
	) );
}
