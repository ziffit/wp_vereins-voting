<?php
/**
 * [FU006] E-Mail-Vorlage, HTML-Layout, Vorschau und Testversand.
 *
 * Das Layout ist bewusst fest im Plugin: Ein HTML-Textfeld wäre der einzige Weg,
 * beliebiges Markup in jede Mail zu bringen. Editierbar sind Betreff, Einleitung
 * und Footer; der Text je Abstimmung steht in voting_polls.mail_text.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** [FU006] Standardwerte der globalen Vorlage. */
function vv_mail_defaults() {
	return [
		'subject'      => 'Einladung zur Abstimmung: {poll_title}',
		'intro'        => "Hallo {vorname} {nachname},\r\n\r\n"
			. 'du bist eingeladen, an der folgenden Abstimmung teilzunehmen. '
			. 'Deine Stimme ist anonym – niemand kann sehen, wie du wählst.',
		'footer'       => '',
		'activate_url' => '',
		'rate'         => 10,
		'poll_text'    => "{question}\r\n\r\nWahlzeitraum: {start} – {end}\r\nBitte halte deine Mitgliedsnummer und E-Mail-Adresse bereit.",
	];
}

/** [FU006#AK03] Betreff global; alter Betreff bleibt als Fallback lesbar. */
function vv_mail_subject_template() {
	$defaults = vv_mail_defaults();
	$subject  = (string) get_option( 'vv_mail_subject', '' );
	if ( '' === $subject ) {
		$subject = (string) get_option( 'vv_invite_subject', $defaults['subject'] );
	}
	return '' === $subject ? $defaults['subject'] : $subject;
}

function vv_mail_intro_template() {
	$defaults = vv_mail_defaults();
	return (string) get_option( 'vv_mail_intro', $defaults['intro'] );
}

function vv_mail_footer() {
	return (string) get_option( 'vv_mail_footer', '' );
}

function vv_mail_activate_url() {
	return trim( (string) get_option( 'vv_mail_activate_url', '' ) );
}

/** [FU006] Optionale URL der Seite mit dem Stimmformular (Schritt 2 der Mail). */
function vv_mail_vote_url() {
	$url = trim( (string) get_option( 'vv_mail_vote_url', '' ) );
	return vv_mail_url_valid( $url ) ? $url : '';
}

/** [FU006] Optionale URL der Ergebnisseite (Schritt 3 der Mail). */
function vv_mail_results_url() {
	$url = trim( (string) get_option( 'vv_mail_results_url', '' ) );
	return vv_mail_url_valid( $url ) ? $url : '';
}

/** [FU004] Globales Versandfenster: 1–500 Mails pro 5 Minuten. */
function vv_mail_rate() {
	return max( 1, min( 500, (int) get_option( 'vv_mail_rate', 10 ) ) );
}

function vv_mail_poll_text_default() {
	$defaults = vv_mail_defaults();
	return $defaults['poll_text'];
}

/** Textblock einer Abstimmung; leere Eingabe fällt auf den Standard zurück. */
function vv_mail_poll_text_template( $poll ) {
	$text = $poll && isset( $poll->mail_text ) ? trim( (string) $poll->mail_text ) : '';
	return '' === $text ? vv_mail_poll_text_default() : $text;
}

function vv_mail_poll_url( $poll ) {
	return $poll && isset( $poll->mail_url ) ? trim( (string) $poll->mail_url ) : '';
}

/** Nur http(s)-Adressen; verhindert javascript:-Links in Mails. */
function vv_mail_url_valid( $url ) {
	$parts = parse_url( trim( (string) $url ) );
	return is_array( $parts )
		&& ! empty( $parts['host'] )
		&& in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true );
}

/** [FU006#AK04] Erlaubte Platzhalter; alte Namen bleiben als Aliasse. */
function vv_mail_allowed_placeholders() {
	return [
		'{vorname}',
		'{nachname}',
		'{first_name}',
		'{last_name}',
		'{poll_title}',
		'{question}',
		'{start}',
		'{end}',
		'{site_name}',
		'{site_url}',
		'{privacy_url}',
	];
}

/** Unbekannte Platzhalter werden vor dem Speichern abgewiesen. */
function vv_mail_unknown_placeholders( $text ) {
	preg_match_all( '/\{[A-Za-z0-9_]+\}/', (string) $text, $matches );
	$found = array_values( array_unique( $matches[0] ?? [] ) );
	return array_values( array_diff( $found, vv_mail_allowed_placeholders() ) );
}

/** Ersetzt die Platzhalter je Empfänger. */
function vv_mail_replace( $text, $poll, $voter = null ) {
	$first = $voter ? (string) ( $voter->first_name ?? '' ) : '';
	$last  = $voter ? (string) ( $voter->last_name ?? '' ) : '';
	$privacy = function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '';

	$replace = [
		'{vorname}'     => $first,
		'{nachname}'    => $last,
		'{first_name}'  => $first,
		'{last_name}'   => $last,
		'{poll_title}'  => $poll ? (string) ( $poll->title ?? '' ) : '',
		'{question}'    => $poll ? (string) ( $poll->question ?? '' ) : '',
		'{start}'       => $poll ? vv_fmt( $poll->start_dt ?? '' ) : '',
		'{end}'         => $poll ? vv_fmt( $poll->end_dt ?? '' ) : '',
		'{site_name}'   => get_bloginfo( 'name' ),
		'{site_url}'    => home_url( '/' ),
		'{privacy_url}' => $privacy,
	];

	return strtr( (string) $text, $replace );
}

/**
 * Baut Betreff, HTML und Text einer Einladung.
 *
 * Der persönliche Aktivierungslink ist immer ein Button; der Website-Link der
 * Abstimmung ist zusätzlich und optional. Beide werden nicht aus dem Freitext
 * geraten, damit kein Link verschwindet oder doppelt entsteht.
 */
function vv_mail_parts( $poll, $voter, $overrides = [] ) {
	$subject_tpl = $overrides['subject'] ?? vv_mail_subject_template();
	$intro_tpl   = $overrides['intro'] ?? vv_mail_intro_template();
	$footer_tpl  = $overrides['footer'] ?? vv_mail_footer();
	$poll_tpl    = $overrides['poll_text'] ?? vv_mail_poll_text_template( $poll );
	$activate    = $overrides['activate_url'] ?? vv_mail_activate_url();
	$vote        = $overrides['vote_url'] ?? vv_mail_vote_url();
	$results     = $overrides['results_url'] ?? vv_mail_results_url();
	$website     = $overrides['website_url'] ?? vv_mail_poll_url( $poll );

	$subject   = vv_mail_replace( $subject_tpl, $poll, $voter );
	$intro     = vv_mail_replace( $intro_tpl, $poll, $voter );
	$poll_text = vv_mail_replace( $poll_tpl, $poll, $voter );
	$footer    = vv_mail_replace( $footer_tpl, $poll, $voter );

	$link = '';
	if ( $activate && $voter && ! empty( $voter->activation_token ) ) {
		$link = add_query_arg(
			[
				'poll'  => (string) ( $poll->id ?? '' ),
				'token' => (string) $voter->activation_token,
			],
			$activate
		);
	}

	if ( ! vv_mail_url_valid( $website ) ) {
		$website = '';
	}

	return [
		'subject'     => $subject,
		'intro'       => $intro,
		'poll_text'   => $poll_text,
		'footer'      => $footer,
		'link'        => $link,
		'vote_url'    => $vote,
		'results_url' => $results,
		'website'     => $website,
		'html'        => vv_mail_html( $subject, $intro, $poll_text, $footer, $link, $website, $vote, $results ),
		'plain'       => vv_mail_plain( $intro, $poll_text, $footer, $link, $website, $vote, $results ),
	];
}

/** Text sicher escapen, URLs aber klickbar machen. */
function vv_mail_escaped_text( $text ) {
	$escaped = esc_html( (string) $text );
	return function_exists( 'make_clickable' ) ? make_clickable( $escaped ) : $escaped;
}

function vv_mail_paragraphs( $text ) {
	$html = '';
	foreach ( preg_split( '/\n\s*\n/', trim( (string) $text ) ) as $block ) {
		$lines = array_values( array_filter(
			array_map( 'trim', explode( "\n", $block ) ),
			static function ( $line ) {
				return '' !== $line;
			}
		) );
		if ( ! $lines ) {
			continue;
		}
		$html .= '<p style="font-size: 1em; margin: 1em 0">'
			. implode( '<br>', array_map( 'vv_mail_escaped_text', $lines ) )
			. "</p>\n";
	}
	return $html;
}

function vv_mail_button( $url, $label ) {
	$url = esc_url( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin: 24px 0">'
		. '<tr><td bgcolor="#1a82e2" style="border-radius: 3px">'
		. '<a href="' . $url . '" style="display: inline-block; padding: 12px 20px; color: #ffffff; text-decoration: none; font-weight: 700; font-size: 14px">'
		. esc_html( (string) $label ) . '</a></td></tr></table>';
}

/**
 * [FU006#AK01] Ablaufschritte: Schritt 1 (Wahl-Code) immer, Schritt 2 und 3 erst,
 * sobald die passenden URLs in den E-Mail-Einstellungen gesetzt sind.
 */
function vv_mail_steps( $link, $vote_url, $results_url ) {
	$p    = 'style="font-size: 1em; margin: 8px 0"';
	$step = 1;
	$html = '<p style="font-size: 1em; margin: 24px 0 0"><strong>So geht’s weiter:</strong></p>';
	$html .= '<p ' . $p . '>' . $step++ . '. Erzeuge deinen persönlichen Wahl-Code (6 Zeichen: Buchstaben und Ziffern).</p>';
	$html .= vv_mail_button( $link, 'Wahl-Code erzeugen' );
	if ( '' !== $vote_url ) {
		$html .= '<p ' . $p . '>' . $step++ . '. Stimme im Wahlzeitraum ab – Ja, Nein oder Enthaltung.</p>';
		$html .= vv_mail_button( $vote_url, 'Jetzt abstimmen' );
	}
	if ( '' !== $results_url ) {
		$html .= '<p ' . $p . '>' . $step++ . '. Prüfe nach Wahlende das Ergebnis. Die ersten fünf Zeichen '
			. 'deines Prüfcodes reichen, um deine Stimme wiederzufinden: '
			. '<a href="' . esc_url( $results_url ) . '" style="color: #1a82e2; text-decoration: underline">Ergebnisse ansehen</a></p>';
	}
	return $html;
}

function vv_mail_html( $subject, $intro, $poll_text, $footer, $link, $website, $vote_url = '', $results_url = '' ) {
	$content = vv_mail_paragraphs( $intro . "\n\n" . $poll_text );
	$content .= vv_mail_steps( $link, $vote_url, $results_url );
	if ( '' !== $website ) {
		$content .= '<p style="font-size: 14px; margin: 16px 0">Mehr zum Ablauf der Wahl und der eingesetzten Software: '
			. '<a href="' . esc_url( $website ) . '" style="color: #1a82e2; text-decoration: underline">Website des Vereins</a></p>';
	}

	$footer_html = '';
	if ( '' !== trim( (string) $footer ) ) {
		$footer_html = '<p style="font-size: 1em; margin: 0; padding: 0">'
			. nl2br( vv_mail_escaped_text( $footer ) )
			. "</p>\n";
	}

	return strtr( vv_mail_layout(), [
		'{{SUBJECT}}'  => esc_html( (string) $subject ),
		'{{HEADLINE}}' => esc_html( (string) $subject ),
		'{{LOGO}}'     => '',
		'{{CONTENT}}'  => $content,
		'{{FOOTER}}'   => $footer_html,
	] );
}

function vv_mail_plain( $intro, $poll_text, $footer, $link, $website, $vote_url = '', $results_url = '' ) {
	$text = rtrim( (string) $intro ) . "\n\n" . rtrim( (string) $poll_text );
	$text .= "\n\nSo geht’s weiter:\n1. Wahl-Code erzeugen (6 Zeichen: Buchstaben und Ziffern): " . $link;
	if ( '' !== $vote_url ) {
		$text .= "\n2. Im Wahlzeitraum abstimmen: " . $vote_url;
	}
	if ( '' !== $results_url ) {
		$text .= "\n3. Nach Wahlende Ergebnisse prüfen: " . $results_url;
	}
	if ( '' !== $website ) {
		$text .= "\n\nMehr zum Ablauf und zur eingesetzten Software: " . $website;
	}
	if ( '' !== trim( (string) $footer ) ) {
		$text .= "\n\n" . trim( (string) $footer );
	}
	return $text;
}

/** [FU006] Prüft, ob die globalen Pflichtangaben für den Versand da sind. */
function vv_mail_settings_error() {
	if ( '' === trim( vv_mail_footer() ) ) {
		return 'Bitte zuerst den Footer in den E-Mail-Einstellungen ausfüllen.';
	}
	if ( ! vv_mail_url_valid( vv_mail_activate_url() ) ) {
		return 'Bitte zuerst die URL der Aktivierungsseite in den E-Mail-Einstellungen angeben.';
	}
	if ( ! vv_mail_url_valid( vv_mail_vote_url() ) ) {
		return 'Bitte zuerst die URL der Stimmformular-Seite in den E-Mail-Einstellungen angeben.';
	}
	if ( ! vv_mail_url_valid( vv_mail_results_url() ) ) {
		return 'Bitte zuerst die URL der Ergebnisseite in den E-Mail-Einstellungen angeben.';
	}
	return '';
}

/** [FU006] Eine Mail mit HTML-Teil und Plain-Text-Alternative versenden. */
function vv_mail_send_parts( $to, $parts ) {
	$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
	$reply   = (string) get_option( 'admin_email', '' );
	if ( is_email( $reply ) ) {
		$headers[] = 'Reply-To: ' . $reply;
	}

	$alternative = static function ( $phpmailer ) use ( $parts ) {
		$phpmailer->AltBody = (string) $parts['plain'];
	};

	add_action( 'phpmailer_init', $alternative );
	try {
		return (bool) wp_mail( $to, (string) $parts['subject'], (string) $parts['html'], $headers );
	} finally {
		remove_action( 'phpmailer_init', $alternative );
	}
}

/** Beispieldaten für Vorschau und Testversand: nie echte Mitgliedsdaten. */
function vv_mail_preview_data( $poll_id = '' ) {
	global $wpdb;

	$poll = null;
	if ( $poll_id ) {
		$poll = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}voting_polls WHERE id = %s",
			$poll_id
		) );
	}
	if ( ! $poll ) {
		$poll = (object) [
			'id'         => '00000000-0000-4000-8000-000000000000',
			'title'      => 'Beispielabstimmung 2026',
			'question'   => 'Stimmt ihr der Beispielabstimmung zu?',
			'start_dt'   => date( 'Y-m-d H:i:s', strtotime( '+1 day' ) ),
			'end_dt'     => date( 'Y-m-d H:i:s', strtotime( '+8 days' ) ),
			'mail_text'  => '',
			'mail_url'   => home_url( '/' ),
			'status'     => 'draft',
		];
	}

	$voter = (object) [
		'first_name'       => 'Erika',
		'last_name'        => 'Muster',
		'email'            => 'erika@example.test',
		'activation_token' => '0123456789abcdef0123456789abcdef',
	];

	$overrides = [];
	if ( ! vv_mail_url_valid( vv_mail_activate_url() ) ) {
		$overrides['activate_url'] = home_url( '/' );
	}
	$parts = vv_mail_parts( $poll, $voter, $overrides );

	return [ $poll, $voter, $parts ];
}

/** [FU006] Vorschau-Seite: HTML und Text, ohne echte Personendaten. */
function vv_mail_preview_page( $poll_id = '' ) {
	list( $poll, $voter, $parts ) = vv_mail_preview_data( $poll_id );

	$warnings = [];
	if ( '' === trim( vv_mail_footer() ) ) {
		$warnings[] = 'Der Footer fehlt noch. Bitte in den E-Mail-Einstellungen ergänzen.';
	}
	if ( ! vv_mail_url_valid( vv_mail_activate_url() ) ) {
		$warnings[] = 'Die URL der Aktivierungsseite fehlt noch. In der Vorschau wird die Startseite als Beispiel verwendet.';
	}
	// [FU006#AK01/AK05] Die Ablaufschritte 2/3 hängen an den optionalen globalen URLs.
	if ( '' === vv_mail_vote_url() ) {
		$warnings[] = 'Die URL der Stimmformular-Seite ist nicht gesetzt. Schritt 2 („Jetzt abstimmen“) erscheint in der Mail erst, wenn sie in den E-Mail-Einstellungen hinterlegt ist.';
	}
	if ( '' === vv_mail_results_url() ) {
		$warnings[] = 'Die URL der Ergebnisseite ist nicht gesetzt. Schritt 3 („Ergebnisse ansehen“) erscheint in der Mail erst, wenn sie in den E-Mail-Einstellungen hinterlegt ist.';
	}

	$warning_html = '';
	foreach ( $warnings as $warning ) {
		$warning_html .= '<p style="padding: 10px 12px; border-left: 4px solid #a56a00; background: #fff8e5">' . esc_html( $warning ) . '</p>';
	}

	return '<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>E-Mail-Vorschau</title>
</head>
<body style="margin: 0; padding: 24px; background: #f0f0f1; color: #1f2933; font-family: system-ui, -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif; line-height: 1.5">
<main style="max-width: 900px; margin: 0 auto">
<h1 style="font-size: 20px">E-Mail-Vorschau</h1>
<p>Betreff: <code>' . esc_html( $parts['subject'] ) . '</code></p>
<p style="color: #555">Alle Namen und Adressen hier sind erfunden. Der persönliche Link ist ein Beispiel und funktioniert nicht. Die Vorschau nutzt die gespeicherten Werte.</p>
' . $warning_html . '
<h2 style="font-size: 16px">HTML-Teil</h2>
<iframe srcdoc="' . esc_attr( $parts['html'] ) . '" style="width: 100%; height: 800px; border: 1px solid #dcdcde; background: #fff"></iframe>
<h2 style="font-size: 16px">Text-Teil</h2>
<pre style="white-space: pre-wrap; padding: 16px; border: 1px solid #dcdcde; background: #fff; font-size: 13px">' . esc_html( $parts['plain'] ) . '</pre>
</main>
</body>
</html>';
}

/** Admin-POST-Handler der Vorschau. */
function vv_mail_preview_handler() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Keine Berechtigung.' );
	}
	check_admin_referer( 'vv_mail_preview' );

	$poll_id = vv_poll_id( wp_unslash( $_GET['poll'] ?? '' ) );
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}
	echo vv_mail_preview_page( $poll_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Layout mit escaped Werten.
	exit;
}
add_action( 'admin_post_vv_mail_preview', 'vv_mail_preview_handler' );

function vv_mail_preview_url( $poll_id = '' ) {
	$url = admin_url( 'admin-post.php?action=vv_mail_preview' );
	if ( $poll_id ) {
		$url = add_query_arg( [ 'poll' => $poll_id ], $url );
	}
	return function_exists( 'wp_nonce_url' ) ? wp_nonce_url( $url, 'vv_mail_preview' ) : $url;
}

/** [FU006] Testmail an das angemeldete WordPress-Konto, nur mit Beispieldaten. */
function vv_send_test_mail( $poll_id = '' ) {
	$error = vv_mail_settings_error();
	if ( '' !== $error ) {
		return $error;
	}

	$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
	$to   = $user && isset( $user->user_email ) ? (string) $user->user_email : '';
	if ( ! is_email( $to ) ) {
		return 'Das angemeldete WordPress-Konto hat keine gültige E-Mail-Adresse.';
	}

	list( $poll, $voter, $parts ) = vv_mail_preview_data( $poll_id );
	if ( ! vv_mail_send_parts( $to, $parts ) ) {
		return 'Die Testmail konnte nicht versendet werden. Bitte den Mailversand der Website prüfen.';
	}
	return true;
}

/** Flash-Schlüssel ohne personenbezogene Daten. */
function vv_mail_settings_flash_key() {
	return 'vv_mail_settings_flash_' . ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
}

function vv_mail_poll_flash_key( $poll_id ) {
	return 'vv_mail_poll_flash_' . ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 ) . '_' . md5( (string) $poll_id );
}

/** Fehler-Schlüssel des Testversands, ohne personenbezogene Daten. */
function vv_mail_test_error_key() {
	return 'vv_mail_test_error_' . ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
}

/** [FU006] Globale E-Mail-Einstellungen speichern. */
function vv_save_mail_settings() {
	$subject  = trim( sanitize_text_field( wp_unslash( $_POST['mail_subject'] ?? '' ) ) );
	$intro    = trim( sanitize_textarea_field( wp_unslash( $_POST['mail_intro'] ?? '' ) ) );
	$footer   = trim( sanitize_textarea_field( wp_unslash( $_POST['mail_footer'] ?? '' ) ) );
	$activate = esc_url_raw( wp_unslash( $_POST['mail_activate_url'] ?? '' ) );
	$vote     = esc_url_raw( wp_unslash( $_POST['mail_vote_url'] ?? '' ) );
	$results  = esc_url_raw( wp_unslash( $_POST['mail_results_url'] ?? '' ) );
	$rate     = (int) ( $_POST['mail_rate'] ?? 0 );

	$errors = [];
	if ( '' === $subject ) {
		$errors[] = 'Der Betreff ist ein Pflichtfeld.';
	}
	if ( '' === $intro ) {
		$errors[] = 'Die Einleitung ist ein Pflichtfeld.';
	}
	if ( '' === $footer ) {
		$errors[] = 'Der Footer ist ein Pflichtfeld.';
	}
	if ( ! vv_mail_url_valid( $activate ) ) {
		$errors[] = 'Bitte eine gültige http(s)-URL der Aktivierungsseite angeben.';
	}
	// [FU006#AK01] Stimmformular- und Ergebnis-Seite sind Pflichtfelder.
	if ( ! vv_mail_url_valid( $vote ) ) {
		$errors[] = 'Bitte eine gültige http(s)-URL der Stimmformular-Seite angeben.';
	}
	if ( ! vv_mail_url_valid( $results ) ) {
		$errors[] = 'Bitte eine gültige http(s)-URL der Ergebnisseite angeben.';
	}
	if ( $rate < 1 || $rate > 500 ) {
		$errors[] = 'Die Versandrate muss zwischen 1 und 500 Mails pro 5 Minuten liegen.';
	}

	$unknown = array_unique( array_merge(
		vv_mail_unknown_placeholders( $subject ),
		vv_mail_unknown_placeholders( $intro ),
		vv_mail_unknown_placeholders( $footer )
	) );
	if ( $unknown ) {
		$errors[] = 'Unbekannte Platzhalter: ' . implode( ', ', $unknown ) . '. Erlaubt: ' . implode( ', ', vv_mail_allowed_placeholders() ) . '.';
	}

	$page = admin_url( 'admin.php?page=vereins-voting-mail' );
	if ( $errors ) {
		set_transient( vv_mail_settings_flash_key(), [
			'mail_subject'      => $subject,
			'mail_intro'        => $intro,
			'mail_footer'       => $footer,
			'mail_activate_url' => $activate,
			'mail_vote_url'     => $vote,
			'mail_results_url'  => $results,
			'mail_rate'         => $rate,
			'error'             => implode( ' ', $errors ),
		], 60 );
		wp_safe_redirect( add_query_arg( [ 'mail_error' => 1 ], $page ) );
		exit;
	}

	update_option( 'vv_mail_subject', $subject );
	update_option( 'vv_mail_intro', $intro );
	update_option( 'vv_mail_footer', $footer );
	update_option( 'vv_mail_activate_url', $activate );
	update_option( 'vv_mail_vote_url', $vote );
	update_option( 'vv_mail_results_url', $results );
	update_option( 'vv_mail_rate', $rate );

	wp_safe_redirect( add_query_arg( [ 'mail_saved' => 1 ], $page ) );
	exit;
}

/** [FU006] Textblock und optionalen Website-Link einer Abstimmung speichern. */
function vv_save_poll_mail() {
	global $wpdb;

	$poll_id = vv_poll_id( wp_unslash( $_POST['poll'] ?? '' ) );
	$poll    = $wpdb->get_row( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}voting_polls WHERE id = %s",
		$poll_id
	) );
	if ( ! $poll ) {
		wp_die( 'Abstimmung nicht gefunden.' );
	}

	$text = sanitize_textarea_field( wp_unslash( $_POST['mail_text'] ?? '' ) );
	$url  = mb_substr( esc_url_raw( wp_unslash( $_POST['mail_url'] ?? '' ) ), 0, 500 );
	$page = admin_url( 'admin.php?page=vereins-voting&view=mail&poll=' . rawurlencode( $poll_id ) );

	$errors = [];
	$unknown = vv_mail_unknown_placeholders( $text );
	if ( $unknown ) {
		$errors[] = 'Unbekannte Platzhalter: ' . implode( ', ', $unknown ) . '.';
	}
	if ( '' !== $url && ! vv_mail_url_valid( $url ) ) {
		$errors[] = 'Bitte eine gültige http(s)-URL für den Website-Link angeben.';
	}

	if ( $errors ) {
		set_transient( vv_mail_poll_flash_key( $poll_id ), [
			'mail_text' => $text,
			'mail_url'  => $url,
			'error'     => implode( ' ', $errors ),
		], 60 );
		wp_safe_redirect( add_query_arg( [ 'mail_error' => 1 ], $page ) );
		exit;
	}

	$wpdb->update(
		"{$wpdb->prefix}voting_polls",
		[ 'mail_text' => $text, 'mail_url' => $url ],
		[ 'id' => $poll_id ]
	);

	wp_safe_redirect( add_query_arg( [ 'mail_saved' => 1 ], $page ) );
	exit;
}

/** [FU006] Testmail-Handler; nutzt gespeicherte Werte und Beispieldaten. */
function vv_test_mail() {
	$poll_id = vv_poll_id( wp_unslash( $_POST['poll'] ?? '' ) );
	$result  = vv_send_test_mail( $poll_id );

	$page = $poll_id
		? admin_url( 'admin.php?page=vereins-voting&view=mail&poll=' . rawurlencode( $poll_id ) )
		: admin_url( 'admin.php?page=vereins-voting-mail' );

	if ( true === $result ) {
		wp_safe_redirect( add_query_arg( [ 'tested' => 1 ], $page ) );
		exit;
	}

	set_transient( vv_mail_test_error_key(), (string) $result, 60 );
	wp_safe_redirect( add_query_arg( [ 'test_failed' => 1 ], $page ) );
	exit;
}

/** [FU006] Globale E-Mail-Einstellungen. */
function vv_render_mail_settings() {
	$flash = get_transient( vv_mail_settings_flash_key() );
	delete_transient( vv_mail_settings_flash_key() );

	$subject  = $flash['mail_subject'] ?? vv_mail_subject_template();
	$intro    = $flash['mail_intro'] ?? vv_mail_intro_template();
	$footer   = $flash['mail_footer'] ?? vv_mail_footer();
	$activate = $flash['mail_activate_url'] ?? vv_mail_activate_url();
	$vote     = $flash['mail_vote_url'] ?? vv_mail_vote_url();
	$results  = $flash['mail_results_url'] ?? vv_mail_results_url();
	$rate     = $flash['mail_rate'] ?? vv_mail_rate();

	$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
	$test_address = $user && isset( $user->user_email ) ? (string) $user->user_email : '';
	$settings_error = vv_mail_settings_error();

	echo '<div class="wrap"><h1>E-Mail-Einstellungen</h1>';
	echo '<p>Diese Angaben gelten für alle Abstimmungen. Der HTML-Rahmen ist fest im Plugin; '
		. 'editierbar sind Betreff, Einleitung und Footer. Der Text je Abstimmung steht unter '
		. '„Teilnehmer &amp; Einladung“.</p>';

	if ( $flash && ! empty( $flash['error'] ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $flash['error'] ) . '</p></div>';
	}
	if ( isset( $_GET['mail_saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>E-Mail-Einstellungen gespeichert.</p></div>';
	}
	if ( isset( $_GET['mail_error'] ) && ( ! $flash || empty( $flash['error'] ) ) ) {
		echo '<div class="notice notice-error"><p>Die E-Mail-Einstellungen wurden nicht gespeichert.</p></div>';
	}
	if ( isset( $_GET['tested'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Testmail wurde an das angemeldete WordPress-Konto versendet.</p></div>';
	}
	if ( isset( $_GET['test_failed'] ) ) {
		$test_error = get_transient( vv_mail_test_error_key() );
		delete_transient( vv_mail_test_error_key() );
		echo '<div class="notice notice-error"><p>' . esc_html( $test_error ?: 'Die Testmail konnte nicht versendet werden.' ) . '</p></div>';
	}
	if ( '' === trim( (string) $footer ) ) {
		echo '<div class="notice notice-warning"><p>Der Footer fehlt. Ohne Footer wird nicht versendet.</p></div>';
	}
	if ( ! vv_mail_url_valid( (string) $activate ) ) {
		echo '<div class="notice notice-warning"><p>Die URL der Aktivierungsseite fehlt oder ist ungültig. Ohne sie wird nicht versendet.</p></div>';
	}
	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
		echo '<div class="notice notice-warning"><p>WP-Cron ist deaktiviert (<code>DISABLE_WP_CRON</code>). '
			. 'Der Versand läuft dann nur über „Weitere Einladungen jetzt senden“.</p></div>';
	}
	if ( '' !== trim( (string) get_option( 'vv_invite_body', '' ) ) ) {
		echo '<div class="notice notice-info"><p>Ein alter Einladungstext aus Version 1.4 bleibt unverändert in der '
			. 'Datenbank gespeichert, wird aber nicht automatisch in die neue Einleitung übernommen.</p></div>';
	}

	echo '<form method="post">';
	wp_nonce_field( 'vv_admin', 'vv_nonce' );
	echo '<input type="hidden" name="vv_action" value="save_mail_settings">';
	echo '<table class="form-table">';
	echo '<tr><th><label for="vv-mail-subject">Betreff</label></th><td>'
		. '<input name="mail_subject" id="vv-mail-subject" type="text" class="large-text" maxlength="200" value="' . esc_attr( $subject ) . '" required>'
		. '<p class="description">Platzhalter: <code>{poll_title}</code>, <code>{site_name}</code></p></td></tr>';
	echo '<tr><th><label for="vv-mail-intro">Einleitung / Anrede</label></th><td>'
		. '<textarea name="mail_intro" id="vv-mail-intro" rows="6" class="large-text" required>' . esc_textarea( $intro ) . '</textarea>'
		. '<p class="description">Platzhalter: <code>{vorname}</code>, <code>{nachname}</code>, <code>{poll_title}</code>, '
		. '<code>{question}</code>, <code>{start}</code>, <code>{end}</code>, '
		. '<code>{site_name}</code>, <code>{site_url}</code>, <code>{privacy_url}</code></p></td></tr>';
	echo '<tr><th><label for="vv-mail-footer">Footer</label></th><td>'
		. '<textarea name="mail_footer" id="vv-mail-footer" rows="7" class="large-text" required>' . esc_textarea( $footer ) . '</textarea>'
		. '<p class="description">Pflicht. Vereinsname, Anschrift, Kontakt und Datenschutz/Impressum. '
		. 'Platzhalter: <code>{site_name}</code>, <code>{site_url}</code>, <code>{privacy_url}</code></p></td></tr>';
	echo '<tr><th><label for="vv-mail-activate">URL der Aktivierungsseite</label></th><td>'
		. '<input name="mail_activate_url" id="vv-mail-activate" type="url" class="large-text" value="' . esc_attr( $activate ) . '" placeholder="' . esc_attr( home_url( '/wahl-code-erzeugen/' ) ) . '" required>'
		. '<p class="description">Seite mit dem Shortcode <code>[verein_voting_activate]</code>.</p></td></tr>';
	echo '<tr><th><label for="vv-mail-vote">URL der Stimmformular-Seite</label></th><td>'
		. '<input name="mail_vote_url" id="vv-mail-vote" type="url" class="large-text" value="' . esc_attr( $vote ) . '" placeholder="' . esc_attr( home_url( '/abstimmen/' ) ) . '" required>'
		. '<p class="description">Pflicht. Seite mit dem Shortcode <code>[verein_voting_form]</code> – Schritt 2 („Jetzt abstimmen“).</p></td></tr>';
	echo '<tr><th><label for="vv-mail-results">URL der Ergebnisseite</label></th><td>'
		. '<input name="mail_results_url" id="vv-mail-results" type="url" class="large-text" value="' . esc_attr( $results ) . '" placeholder="' . esc_attr( home_url( '/ergebnis/' ) ) . '" required>'
		. '<p class="description">Pflicht. Seite mit dem Shortcode <code>[verein_voting_results]</code> – Schritt 3 („Ergebnisse ansehen“).</p></td></tr>';
	echo '<tr><th><label for="vv-mail-rate">Mails pro 5 Minuten</label></th><td>'
		. '<input name="mail_rate" id="vv-mail-rate" type="number" min="1" max="500" value="' . esc_attr( (string) $rate ) . '" required>'
		. '<p class="description">Gilt global für alle Abstimmungen. Standard: 10.</p></td></tr>';
	echo '</table>';
	echo '<p><button type="submit" class="button button-primary">Speichern</button> '
		. '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=vereins-voting' ) ) . '">Abbrechen</a></p>';
	echo '</form>';

	echo '<h2>Vorschau und Test</h2>';
	echo '<p>Vorschau und Testversand nutzen die <strong>gespeicherten</strong> Werte und ausschließlich erfundene Beispieldaten. '
		. 'Bitte zuerst speichern. Der persönliche Link im Test ist absichtlich ungültig.</p>';
	echo '<p><a class="button" href="' . esc_url( vv_mail_preview_url() ) . '" target="_blank" rel="noopener">Vorschau öffnen</a></p>';
	echo '<form method="post">';
	wp_nonce_field( 'vv_admin', 'vv_nonce' );
	echo '<input type="hidden" name="vv_action" value="test_mail">';
	echo '<p><button type="submit" class="button" ' . ( '' !== $settings_error || ! is_email( $test_address ) ? 'disabled' : '' ) . '>Testmail an '
		. esc_html( $test_address ?: 'das WordPress-Konto' ) . ' senden</button></p>';
	if ( '' !== $settings_error ) {
		echo '<p class="description">' . esc_html( $settings_error ) . '</p>';
	}
	echo '</form>';

	echo '<p class="description">Für die Zustellbarkeit sind ein SMTP-Plugin sowie SPF, DKIM und DMARC nötig. '
		. 'Das Plugin setzt nur <code>Reply-To</code>; Absenderadresse und -name gehören in die SMTP-Konfiguration. '
		. 'Kein Öffnungs-Tracking, keine externen Bilder. SMTP-/Server-Logs dürfen keine Mailinhalte mit '
		. 'Aktivierungstokens mitschneiden.</p>';
	echo '</div>';
}

/** [FU006] Das feste HTML-Layout, an das Vorbild angelehnt. */
function vv_mail_layout() {
	return <<<'VV_MAIL_LAYOUT'
<!DOCTYPE html>
<html lang="de">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
	<meta http-equiv="x-ua-compatible" content="ie=edge">
	<title>{{SUBJECT}}</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
<style type="text/css">
	body, table, td, div, p, a { -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; }
	body, table.body-wrap { overflow: auto; box-sizing: border-box; color: #111111; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.5; font-weight: normal; font-style: normal; background-color: #f1f1f1; }
	div, ol, ul, p { font-size: 1em; }
	p, ul, ol, h1, h2, h3, h4, h5, h6 { margin: 1em 0; }
	.footer p { margin: 0; padding: 0; }
	body { width: 100% !important; height: 100% !important; padding: 0 !important; margin: 0 !important; }
	table { border-collapse: collapse !important; }
	h1, h2, h3, h4, h5, h6 { font-weight: 700; }
	h1 { font-size: 32px; line-height: 48px; }
	img, figure { height: auto; line-height: 100%; text-decoration: none; border: 0; outline: none; max-width: 100%; }
</style>
</head>
<body style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; overflow: auto; box-sizing: border-box; color: #111111; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.5; font-weight: normal; font-style: normal; width: 100% !important; height: 100% !important; padding: 0 !important; margin: 0 !important; background-color: #f1f1f1">
<div style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; display: none !important; max-width: 0; max-height: 0; overflow: hidden; font-size: 1px; line-height: 1px; color: #fff; opacity: 0">{{SUBJECT}}</div>
	<!--[if mso]>
		<style type="text/css">table, td, div, p, a { font-family: Arial, sans-serif; }</style>
	<![endif]-->
	<table class="body-wrap" border="0" cellpadding="0" cellspacing="0" width="100%" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; border-collapse: collapse !important; overflow: auto; box-sizing: border-box; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.5; font-weight: normal; font-style: normal; background-color: #f1f1f1; margin-top: 10px; color: #111111" bgcolor="#f1f1f1">
{{LOGO}}
		<tr>
			<td align="center" bgcolor="#f1f1f1" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt">
			<!--[if (gte mso 9)|(IE)]>
					<table align="center" border="0" cellpadding="0" cellspacing="0" width="600px"><tr><td align="center" valign="top" width="600px">
			<![endif]-->
					<table border="0" cellpadding="0" cellspacing="0" width="100%" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; border-collapse: collapse !important; max-width: 600px">
						<tr>
							<td align="left" bgcolor="#ffffff" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; padding: 36px 24px 0; border-top: 3px solid #1a82e2">
									<h1 style="mso-line-height-rule: exactly; font-weight: 700; font-size: 32px; line-height: 48px; margin: 0; letter-spacing: -1px">{{HEADLINE}}</h1>
							</td>
						</tr>
					</table>
			<!--[if (gte mso 9)|(IE)]>
					</td></tr></table>
			<![endif]-->
		</td>
	</tr>
	<tr>
		<td align="center" bgcolor="#f1f1f1" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; padding: 0">
			<!--[if (gte mso 9)|(IE)]>
					<table align="center" border="0" cellpadding="0" cellspacing="0" width="600px"><tr><td align="center" valign="top" width="600px">
			<![endif]-->
				<table border="0" cellpadding="0" cellspacing="0" width="100%" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; border-collapse: collapse !important; max-width: 600px">
					<tr>
						<td align="left" bgcolor="#ffffff" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; padding: 12px 24px; font-size: 14px; color: #111111">
								{{CONTENT}}
						</td>
					</tr>
				</table>
			<!--[if (gte mso 9)|(IE)]>
					</td></tr></table>
			<![endif]-->
		</td>
	</tr>
	<tr>
		<td align="center" bgcolor="#f1f1f1" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; padding: 24px" class="footer">
			<!--[if (gte mso 9)|(IE)]>
					<table align="center" border="0" cellpadding="0" cellspacing="0" width="600px"><tr><td align="center" valign="top" width="600px">
			<![endif]-->
				<table border="0" cellpadding="0" cellspacing="0" width="100%" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; border-collapse: collapse !important; max-width: 600px">
					<tr>
						<td align="center" bgcolor="#f1f1f1" style="-ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; mso-table-rspace: 0pt; mso-table-lspace: 0pt; padding: 12px 24px; font-size: 12px; line-height: 18px; color: #666666">
							{{FOOTER}}
						</td>
					</tr>
				</table>
			<!--[if (gte mso 9)|(IE)]>
					</td></tr></table>
			<![endif]-->
		</td>
	</tr>
	</table>
</body>
</html>
VV_MAIL_LAYOUT;
}
