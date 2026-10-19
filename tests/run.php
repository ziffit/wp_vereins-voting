<?php
/**
 * Unit-Test-Läufer für vereins-voting.
 * Ausführung (Docker):  bash tests/run.sh
 * Manueller Aufruf:     php tests/run.php <dbhost> <user> <pass> <dbname>
 */

define( 'ABSPATH', '/tmp/vv-wp/' ); // wp-admin-Stub landet außerhalb des Repos.

if ( ! defined( 'VV_DB_HOST' ) ) {
	define( 'VV_DB_HOST', $argv[1] ?? 'vv-db' );
	define( 'VV_DB_USER', $argv[2] ?? 'vv' );
	define( 'VV_DB_PASS', $argv[3] ?? 'vvpw' );
	define( 'VV_DB_NAME', $argv[4] ?? 'vvtest' );
}

require __DIR__ . '/_bootstrap.php'; // Shim laden, Plugin booten, vv_db_reset().
require __DIR__ . '/helpers.php';    // Fixtures + vv_check.

$tests = [];
foreach ( glob( __DIR__ . '/test_*.php' ) as $file ) {
	$tests = array_merge( $tests, require $file );
}
if ( ! $tests ) {
	echo "Keine Testdateien gefunden (tests/test_*.php).\n";
	exit( 1 );
}

$pass   = 0;
$fail   = 0;
$failures = [];

foreach ( $tests as $name => $fn ) {
	vv_db_reset();
	echo "\n== TEST $name\n";
	$t0 = microtime( true );
	try {
		$fn();
		++$pass;
		printf( "\n== PASS %-44s (%.3fs)\n", $name, microtime( true ) - $t0 );
	} catch ( Throwable $e ) {
		++$fail;
		$failures[] = [ $name, $e->getMessage() ];
		printf( "\n== FAIL %-44s (%.3fs)\n   %s\n", $name, microtime( true ) - $t0, $e->getMessage() );
	}
}

printf( "\nVV-TESTS: %d bestanden, %d fehlgeschlagen (%d Tests)\n", $pass, $fail, $pass + $fail );
if ( $failures ) {
	echo "Fehlgeschlagene Tests:\n";
	foreach ( $failures as [ $n, $m ] ) {
		echo "  - $n: $m\n";
	}
}
exit( $fail ? 1 : 0 );
