<?php
/**
 * Plugin Name:       vereins-voting
 * Description:       Vereins-Abstimmungen: Einladung per E-Mail-Link, PIN-Aktivierung durch das Mitglied, anonyme Stimmurne mit Receipt-Hash.
 * Version:           1.1.0
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

define( 'VEREINS_VOTING_VERSION', '1.1.0' );

/*
 * [FU001#AK02] Bei Deaktivierung/Deinstallation werden KEINE Daten gelöscht —
 * Wahlergebnisse müssen als Nachweis erhalten bleiben (bewusster Verzicht
 * auf uninstall.php).
 */

register_activation_hook(
	__FILE__,
	function () {
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
		$voters = "{$wpdb->prefix}voting_voters";
		dbDelta(
			"CREATE TABLE $voters (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				poll_id BIGINT UNSIGNED NOT NULL,
				member_no VARCHAR(191) NOT NULL,
				email VARCHAR(191) NOT NULL,
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
	}
);

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
