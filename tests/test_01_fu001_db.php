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
		foreach ( [ 'id', 'title', 'question', 'start_dt', 'end_dt', 'status' ] as $c ) {
			vv_check( in_array( $c, $cols, true ), "[FU001#AK01] polls-Spalte $c vorhanden" );
		}
	},

	'test_01_fu001_ak01_voters_spalten' => function () {
		$cols = vv_cols( 'voting_voters' );
		foreach ( [ 'poll_id', 'member_no', 'email', 'activation_token', 'pin_hash', 'has_voted', 'voted_at' ] as $c ) {
			vv_check( in_array( $c, $cols, true ), "[FU001#AK01] voters-Spalte $c vorhanden" );
		}
	},

	'test_01_fu001_ak01_ballots_spalten' => function () {
		$cols = vv_cols( 'voting_ballots' );
		foreach ( [ 'poll_id', 'vote_option', 'receipt_hash' ] as $c ) {
			vv_check( in_array( $c, $cols, true ), "[FU001#AK01] ballots-Spalte $c vorhanden" );
		}
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
		$dup = $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . vv_tbl( 'voting_ballots' ) . ' (poll_id, vote_option, receipt_hash) VALUES (%d, %s, %s)', $pid, 'nein', $h ) );
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
];
