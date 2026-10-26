<?php
/**
 * [FU001] Datenbank & Aktivierung.
 */
return [

	'test_01_fu001_ak01_tabellen_angelegt' => function () {
		global $wpdb;
		foreach ( [ 'voting_polls', 'voting_voters', 'voting_ballots' ] as $t ) {
			$full = vv_tbl( $t );
			$name = $wpdb->prepare( '%s', $full );
			$db   = $wpdb->prepare( '%s', VV_DB_NAME );
			$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = $db AND TABLE_NAME = $name" );
			vv_check( 1 === $n, "[FU001#AK01] Tabelle $full existiert nach Aktivierung" );
		}
	},

	'test_01_fu001_ak01_polls_spalten' => function () {
		$cols = vv_cols( 'voting_polls' );
		foreach ( [ 'id', 'title', 'question', 'mail_text', 'mail_url', 'start_dt', 'end_dt', 'status' ] as $c ) {
			vv_check( in_array( $c, $cols, true ), "[FU001#AK01] polls-Spalte $c vorhanden" );
		}
	},

	'test_01_fu001_ak01_voters_spalten' => function () {
		$cols = vv_cols( 'voting_voters' );
		foreach ( [ 'poll_id', 'member_no', 'email', 'first_name', 'last_name', 'activation_token', 'pin_hash', 'has_voted', 'voted_at', 'invited_at', 'invite_attempts' ] as $c ) {
			vv_check( in_array( $c, $cols, true ), "[FU001#AK01] voters-Spalte $c vorhanden" );
		}
	},

	'test_01_fu001_ak01_ballots_spalten' => function () {
		$cols = vv_cols( 'voting_ballots' );
		foreach ( [ 'poll_id', 'vote_option', 'receipt_hash' ] as $c ) {
			vv_check( in_array( $c, $cols, true ), "[FU001#AK01] ballots-Spalte $c vorhanden" );
		}
		// [FU008#AK03] Urne ausschließlich diese drei Felder: kein Zeitstempel,
		// keine laufende Nummer – sonst Korrelation mit voted_at möglich.
		vv_check( [] === array_diff( $cols, [ 'poll_id', 'vote_option', 'receipt_hash' ] ),
			'[FU008#AK03] Urne ausschließlich poll_id/vote_option/receipt_hash (kein created_at, kein id)' );
	},

	'test_01_fu001_ak02_neue_spalten_v152' => function () {
		$vcols = vv_cols( 'voting_voters' );
		vv_check( in_array( 'pin_set_at', $vcols, true ),
			'[FU001#AK01/FU007#AK03] voters-Spalte pin_set_at (Zeitpunkt der Wahl-Code-Aktivierung)' );
		$pcols = vv_cols( 'voting_polls' );
		vv_check( in_array( 'urn_fingerprint', $pcols, true ),
			'[FU001#AK01/FU002#AK02] polls-Spalte urn_fingerprint (Abschluss-Referenzwert)' );
	},

	'test_01_fu001_ak01_kein_foreign_key' => function () {
		global $wpdb;
		$db   = $wpdb->prepare( '%s', VV_DB_NAME );
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
			WHERE TABLE_SCHEMA = $db AND REFERENCED_TABLE_NAME IS NOT NULL
			  AND (TABLE_NAME LIKE '%voting_%' OR REFERENCED_TABLE_NAME LIKE '%voting_%')" );
		vv_check( 0 === $n, "[FU001#AK01/Sicherheitsarchitektur] keine Foreign Keys zwischen Urne/Wählern" );
	},

	'test_01_fu001_ak01_receipt_hash_eindeutig' => function () {
		global $wpdb;
		$pid = vv_fx_poll();
		$h   = 'aa' . str_repeat( '0', 62 );
		vv_fx_ballot( $pid, 'ja', $h );
		$dup = $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . vv_tbl( 'voting_ballots' ) . ' (poll_id, vote_option, receipt_hash) VALUES (%s, %s, %s)', $pid, 'nein', $h ) );
		vv_check( false === $dup, '[FU001#AK01] Doppel-Receipt-Hash in Urne abgelehnt (UNIQUE-Index)' );
	},

	'test_01_fu001_ak02_keine_entfernung_logik_im_code' => function () {
		// [FU001#AK02] Daten bleiben bei Deaktivierung/Deinstallation: kein uninstall.php
		// und im gesamten Plugin keinerlei Lösch-Statements für die voting-Tabellen.
		vv_check( ! file_exists( __DIR__ . '/../vereins-voting/uninstall.php' ), '[FU001#AK02] kein uninstall.php vorhanden' );
		$files = array_merge(
			[ __DIR__ . '/../vereins-voting/vereins-voting.php' ],
			glob( __DIR__ . '/../vereins-voting/includes/*.php' )
		);
		$found = '';
		foreach ( $files as $f ) {
			$src = file_get_contents( $f );
			if ( stripos( $src, 'DROP TABLE' ) !== false || stripos( $src, 'TRUNCATE' ) !== false ) {
				$found .= basename( $f ) . ' ';
			}
		}
		vv_check( '' === $found, "[FU001#AK02] kein DROP/TRUNCATE im Plugin-Code (Hits: $found)" );
	},

	'test_01_fu001_ak02_daten_bleiben_erhalten' => function () {
		global $wpdb;
		$pid = vv_fx_poll();
		vv_fx_voter( $pid );
		vv_fx_ballot( $pid ); // schon existierende Bestandsdaten.
		// Zweite Aktivierung darf nichts zerstören — [FU001#AK02] Datenschutz/Schutz der Wahlergebnisse.
		( $GLOBALS['vv_activation_hook'] ?? null )();
		vv_check( vv_fx_poll_row( $pid ) && 1 === vv_fx_voter_count( $pid ) && 1 === vv_fx_ballots_count( $pid ),
			'[FU001#AK02] Daten bleiben nach wiederholter Aktivierung erhalten' );
	},

	'test_01_fu001_ak03_upgrade_ergaenzt_spalten_ohne_datenverlust' => function () {
		global $wpdb;
		$pid = vv_fx_poll();
		vv_fx_voter( $pid, '101', 'alt@example.org', 'tok1' );

		// Bestandsinstallation vor 1.2.0 simulieren: Namensspalten fehlen.
		$wpdb->query( 'ALTER TABLE ' . vv_tbl( 'voting_voters' ) . ' DROP COLUMN first_name, DROP COLUMN last_name, DROP COLUMN invited_at, DROP COLUMN invite_attempts' );
		$wpdb->query( 'ALTER TABLE ' . vv_tbl( 'voting_polls' ) . ' DROP COLUMN mail_text, DROP COLUMN mail_url' );
		vv_check( ! in_array( 'first_name', vv_cols( 'voting_voters' ), true ), '[FU001#AK03] Ausgangslage: first_name fehlt' );

		vv_maybe_upgrade();

		$cols = vv_cols( 'voting_voters' );
		vv_check( in_array( 'first_name', $cols, true ) && in_array( 'last_name', $cols, true )
			&& in_array( 'invited_at', $cols, true ) && in_array( 'invite_attempts', $cols, true ),
			'[FU001#AK03] Upgrade zieht fehlende Spalten nach' );
		$poll_cols = vv_cols( 'voting_polls' );
		vv_check( in_array( 'mail_text', $poll_cols, true ) && in_array( 'mail_url', $poll_cols, true ),
			'[FU001#AK03] Upgrade ergänzt mail_text/mail_url' );
		vv_check( 1 === vv_fx_voter_count( $pid ) && vv_fx_voter_row( 'alt@example.org' ),
			'[FU001#AK03] Bestandsdaten bleiben beim Upgrade erhalten' );
	},

	'test_01_fu002_ak07_poll_id_ist_uuid' => function () {
		global $wpdb;
		$pid  = vv_fx_poll();
		$type = $wpdb->get_row( 'SHOW COLUMNS FROM ' . vv_tbl( 'voting_polls' ) . " LIKE 'id'" );

		vv_check( (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $pid ),
			'[FU002#AK07] Poll-ID ist eine UUID v4 (keine aufsteigende Zahl)' );
		vv_check( $type && 0 === stripos( (string) $type->Type, 'char(36)' ) && 'auto_increment' !== ( $type->Extra ?? '' ),
			'[FU002#AK07] polls.id ist CHAR(36) ohne AUTO_INCREMENT' );
	},

	'test_01_fu001_ak03_alte_zahlen_ids_werden_zu_uuid' => function () {
		global $wpdb;
		// Altbestand simulieren: Poll mit kurzer Zahlen-ID inkl. Wähler und Urne.
		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . vv_tbl( 'voting_polls' ) . ' (id, title, status) VALUES (%s, %s, %s)',
			'9001', 'Altbestand 1.2', 'draft'
		) );
		vv_fx_voter( '9001', '1', 'alt-id@example.org', 'tok9' );
		vv_fx_ballot( '9001' );

		update_option( 'vv_db_version', '1.2.0' );
		vv_maybe_upgrade();

		$new = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . vv_tbl( 'voting_polls' ) . ' WHERE title = %s', 'Altbestand 1.2'
		) );
		vv_check( $new && 36 === strlen( $new ) && '9001' !== $new,
			'[FU001#AK03] alte Zahlen-Poll-ID wird beim Upgrade durch eine UUID ersetzt' );
		vv_check( 1 === vv_fx_voter_count( $new ) && 1 === vv_fx_ballots_count( $new ),
			'[FU001#AK03] Wähler und Urne folgen der neuen Poll-ID (kein Datenverlust)' );
		vv_check( null === vv_fx_poll_row( '9001' ), '[FU001#AK03] alte ID ist nicht mehr vorhanden' );
	},
];
