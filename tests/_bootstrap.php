<?php
/**
 * Bootstrap: lädt den WP-Shim (aus _verify, wiederverwendet), bootet das
 * Plugin gegen die Test-DB und stellt vv_db_reset() bereit.
 * DB-Zugangsdaten kommen aus run.php (VV_DB_* Konstanten), Fallback vv-db.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/vv-wp/' );
}

require __DIR__ . '/../_verify/shim.php';

vv_boot(
	defined( 'VV_DB_HOST' ) ? VV_DB_HOST : 'vv-db',
	defined( 'VV_DB_USER' ) ? VV_DB_USER : 'vv',
	defined( 'VV_DB_PASS' ) ? VV_DB_PASS : 'vvpw',
	defined( 'VV_DB_NAME' ) ? VV_DB_NAME : 'vvtest'
);

/**
 * Frisches Schema pro Testfall: Tabellen droppen und Plugin-Aktivierung
 * erneut ausführen ([FU001] Anlegepfad). Mail-Log und Options leeren,
 * damit Tests stateslos bleiben.
 */
function vv_db_reset() {
	global $wpdb;
	foreach ( [ 'voting_ballots', 'voting_voters', 'voting_polls' ] as $t ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}$t" );
	}
	( $GLOBALS['vv_activation_hook'] ?? null )();
	$GLOBALS['vv_mails']       = [];
	$GLOBALS['vv_mail_fail']   = false;
	$GLOBALS['vv_options']     = [];
	$GLOBALS['vv_transients']  = [];
	$GLOBALS['vv_cron']        = [];
	$GLOBALS['vv_current_user'] = (object) [ 'ID' => 1, 'user_email' => 'admin@example.test' ];
	$_GET  = [];
	$_POST = [];
	unset( $_FILES );
	$_SERVER['REQUEST_METHOD'] = 'GET';
}
