<?php
/**
 * Plugin Name:       vereins-voting
 * Description:       Vereins-Abstimmungen: Einladung per E-Mail-Link, PIN-Aktivierung durch das Mitglied, anonyme Stimmurne mit Receipt-Hash.
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Felix
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vereins-voting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direktzugriff verweigern.
}

define( 'VEREINS_VOTING_VERSION', '1.2.0' );

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

	// [FU001] Abstimmungen.
	$polls = "{$wpdb->prefix}voting_polls";
	dbDelta(
		"CREATE TABLE $polls (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(190) NOT NULL DEFAULT '',
			question TEXT NULL,
			start_dt DATETIME NULL,
			end_dt DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status),
			KEY end_dt (end_dt)
		) $charset"
	);

	// [FU001] Berechtigte + PIN-Hashes. Kein Foreign Key zur Urne.
	// first_name/last_name (optional) dienen nur der persönlichen Ansprache.
	$voters = "{$wpdb->prefix}voting_voters";
	dbDelta(
		"CREATE TABLE $voters (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			poll_id BIGINT UNSIGNED NOT NULL,
			member_no VARCHAR(191) NOT NULL,
			email VARCHAR(191) NOT NULL,
			first_name VARCHAR(100) NULL DEFAULT NULL,
			last_name VARCHAR(100) NULL DEFAULT NULL,
			activation_token VARCHAR(64) NULL DEFAULT NULL,
			pin_hash VARCHAR(255) NULL DEFAULT NULL,
			has_voted TINYINT(1) NOT NULL DEFAULT 0,
			voted_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY poll_email (poll_id, email),
			KEY poll_member (poll_id, member_no),
			KEY activation_token (activation_token)
		) $charset"
	);

	// [FU001] Anonyme Urne: keine Personenbeziehungen.
	$ballots = "{$wpdb->prefix}voting_ballots";
	dbDelta(
		"CREATE TABLE $ballots (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			poll_id BIGINT UNSIGNED NOT NULL,
			vote_option VARCHAR(20) NOT NULL,
			receipt_hash CHAR(64) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY receipt_hash (receipt_hash),
			KEY poll_id (poll_id)
		) $charset"
	);

	update_option( 'vv_db_version', VEREINS_VOTING_VERSION );
}
register_activation_hook( __FILE__, 'vv_create_schema' );

/** [FU001#AK03] Bestandsinstallationen nach einem Plugin-Update nachziehen. */
function vv_maybe_upgrade() {
	if ( get_option( 'vv_db_version' ) !== VEREINS_VOTING_VERSION ) {
		vv_create_schema();
	}
}
add_action( 'plugins_loaded', 'vv_maybe_upgrade' );

// Ein minimales Stylesheet für Formulare/Tabellen, damit es in jedes Theme passt.
add_action( 'wp_enqueue_scripts', function () {
	wp_register_style( 'vv-frontend', plugins_url( 'assets/vv.css', __FILE__ ), [], VEREINS_VOTING_VERSION );
} );
add_action( 'admin_enqueue_scripts', function () {
	wp_enqueue_style( 'vv-frontend', plugins_url( 'assets/vv.css', __FILE__ ), [], VEREINS_VOTING_VERSION );
} );

require_once __DIR__ . '/includes/common.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/frontend.php';
