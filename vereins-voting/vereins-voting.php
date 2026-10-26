<?php
/**
 * Plugin Name:       vereins-voting
 * Description:       Vereins-Abstimmungen: Einladung per E-Mail-Link, Aktivierung mit systemgeneriertem Wahl-Code, anonyme Stimmurne mit Receipt-Hash.
 * Version:           1.5.4
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Felix Tensing
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vereins-voting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direktzugriff verweigern.
}

define( 'VEREINS_VOTING_VERSION', '1.5.4' );

/*
 * [FU001#AK02] Bei Deaktivierung/Deinstallation werden KEINE Daten gelöscht —
 * Wahlergebnisse müssen als Nachweis erhalten bleiben (bewusster Verzicht
 * auf uninstall.php).
 */

/**
 * [FU001#AK01] Tabellen anlegen bzw. additiv um fehlende Spalten ergänzen.
 * dbDelta ändert nur Vorhandenes; Daten werden nie gelöscht.
 */
function vv_create_schema() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();

	// [FU001] Abstimmungen. IDs sind zufällige UUIDs (FU002#AK07) statt
	// aufsteigender Zahlen – Links verraten so keine Anzahl/Reihenfolge.
	$polls = "{$wpdb->prefix}voting_polls";
	dbDelta(
		"CREATE TABLE $polls (
			id CHAR(36) NOT NULL,
			title VARCHAR(190) NOT NULL DEFAULT '',
			question TEXT NULL,
			mail_text TEXT NULL,
			mail_url VARCHAR(500) NULL,
			start_dt DATETIME NULL,
			end_dt DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			urn_fingerprint CHAR(64) NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY end_dt (end_dt)
		) $charset"
	);

	// [FU001] Berechtigte + Wahl-Code-Hashes. Kein Foreign Key zur Urne.
	// first_name/last_name (optional) dienen nur der persönlichen Ansprache.
	// [FU003#AK02] Eindeutig ist die Kombination Mitgliedsnummer + E-Mail:
	// dieselbe E-Mail darf mehrfach vorkommen (z. B. Ehepaare).
	$voters = "{$wpdb->prefix}voting_voters";
	dbDelta(
		"CREATE TABLE $voters (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			poll_id CHAR(36) NOT NULL,
			member_no VARCHAR(191) NOT NULL,
			email VARCHAR(191) NOT NULL,
			first_name VARCHAR(100) NULL DEFAULT NULL,
			last_name VARCHAR(100) NULL DEFAULT NULL,
			activation_token VARCHAR(64) NULL DEFAULT NULL,
			pin_hash VARCHAR(255) NULL DEFAULT NULL,
			has_voted TINYINT(1) NOT NULL DEFAULT 0,
			voted_at DATETIME NULL,
			pin_set_at DATETIME NULL,
			invited_at DATETIME NULL,
			invite_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY poll_member_email (poll_id, member_no, email),
			KEY activation_token (activation_token)
		) $charset"
	);

	// [FU001] Anonyme Urne: keine Personenbeziehungen.
	$ballots = "{$wpdb->prefix}voting_ballots";
	// [FU008#AK03] Urne ausschließlich poll_id/vote_option/receipt_hash:
	// kein Zeitstempel, keine laufende Nummer – sonst Korrelation mit voted_at.
	dbDelta(
		"CREATE TABLE $ballots (
			poll_id CHAR(36) NOT NULL,
			vote_option VARCHAR(20) NOT NULL,
			receipt_hash CHAR(64) NOT NULL,
			PRIMARY KEY  (receipt_hash),
			KEY poll_id (poll_id)
		) $charset"
	);

	update_option( 'vv_db_version', VEREINS_VOTING_VERSION );
}
register_activation_hook( __FILE__, 'vv_create_schema' );

/**
 * [FU001#AK03] Bestandsinstallationen nach einem Plugin-Update nachziehen.
 * Vor 1.3.0 waren Poll-IDs aufsteigende Zahlen; sie werden auf UUIDs
 * umgestellt, damit die Spaltentypen wieder zusammenpassen.
 */
function vv_maybe_upgrade() {
	if ( get_option( 'vv_db_version' ) === VEREINS_VOTING_VERSION ) {
		return;
	}
	vv_upgrade_poll_ids_to_uuid();
	vv_create_schema();
}
add_action( 'plugins_loaded', 'vv_maybe_upgrade' );

/** [FU001#AK03] Alte Zahlen-IDs (BIGINT) auf UUID-Spalten und -Werte heben. */
function vv_upgrade_poll_ids_to_uuid() {
	global $wpdb;

	$polls = "{$wpdb->prefix}voting_polls";
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $polls ) ) ) {
		return; // Frische Installation: vv_create_schema() legt UUID-Spalten an.
	}

	// Spaltentypen angleichen (auf leerer Tabelle sofort, sonst Werte umschreiben).
	foreach ( [ [ $polls, 'id' ], [ "{$wpdb->prefix}voting_voters", 'poll_id' ], [ "{$wpdb->prefix}voting_ballots", 'poll_id' ] ] as [ $table, $col ] ) {
		$row = $wpdb->get_row( "SHOW COLUMNS FROM `$table` LIKE '$col'" );
		if ( $row && 0 !== stripos( (string) $row->Type, 'char(36)' ) ) {
			$wpdb->query( "ALTER TABLE `$table` MODIFY `$col` CHAR(36) NOT NULL" );
		}
	}

	// Legacies: kurze (numerische) IDs durch UUIDs ersetzen; Urne und Wähler folgen.
	foreach ( (array) $wpdb->get_col( "SELECT id FROM `$polls` WHERE CHAR_LENGTH(id) < 36" ) as $old ) {
		$new = vv_uuid();
		$wpdb->update( $polls, [ 'id' => $new ], [ 'id' => (string) $old ] );
		$wpdb->update( "{$wpdb->prefix}voting_voters", [ 'poll_id' => $new ], [ 'poll_id' => (string) $old ] );
		$wpdb->update( "{$wpdb->prefix}voting_ballots", [ 'poll_id' => $new ], [ 'poll_id' => (string) $old ] );
	}
}

// Ein minimales Stylesheet für Formulare/Tabellen, damit es in jedes Theme passt.
add_action( 'wp_enqueue_scripts', function () {
	wp_register_style( 'vv-frontend', plugins_url( 'assets/vv.css', __FILE__ ), [], VEREINS_VOTING_VERSION );
} );
add_action( 'admin_enqueue_scripts', function () {
	wp_enqueue_style( 'vv-frontend', plugins_url( 'assets/vv.css', __FILE__ ), [], VEREINS_VOTING_VERSION );
} );

require_once __DIR__ . '/includes/common.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/queue.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/frontend.php';
