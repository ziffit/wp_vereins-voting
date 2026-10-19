<?php
/**
 * WP-Shim für den Plugin-Verifikationslauf (kein WP-Kern, nur die Schnittstellen,
 * die vereins-voting benutzt). Wird nur in _verify verwendet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "ABSPATH fehlt\n";
	exit( 1 );
}

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

date_default_timezone_set( 'Europe/Berlin' );

class VVWpDie extends Exception {}
class VVRedirectFound extends Exception {
	public $url;
	public function __construct( $url ) { $this->url = $url; parent::__construct( 'redirect' ); }
}

// ---------------------------------------------------------------------------
// Mini-WPDB auf PDO (reicht für alle im Plugin verwendeten Muster: prepare,
// query, get_row, get_results, get_var, insert, update, insert_id)
// ---------------------------------------------------------------------------

class VVWpDb {
	public $prefix  = 'wpx_';
	public $insert_id = 0;
	private $db; // mysqli
	public $last_error = '';

	public function get_charset_collate() {
		return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
	}

	public function __construct( $host, $user, $pass, $db ) {
		mysqli_report( MYSQLI_REPORT_OFF ); // Fehler ohne Fatals, wir sind WPDB-Ersatz.
		$this->db = new mysqli( $host, $user, $pass, $db );
		if ( $this->db->connect_error ) {
			die( 'VV-DB-CONN: ' . $this->db->connect_error . "\n" );
		}
		$this->db->set_charset( 'utf8mb4' );
		$this->query( "SET time_zone = '+00:00'" );
	}

	/** QString-escapen (für %s in prepare). */
	private function q( $v ) {
		return "'" . ( null === $v ? '' : $this->db->real_escape_string( (string) $v ) ) . "'";
	}

	/** WP-prepare: %s wird quoted, %d/%f numerisch. */
	public function prepare( $sql, ...$args ) {
		$i = 0;
		return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ] ?? null;
			if ( '%s' === $m[0] ) {
				return $this->q( $v );
			}
			if ( '%d' === $m[0] ) {
				return (int) $v;
			}
			return (float) $v + 0;
		}, $sql );
	}

	public function query( $sql ) {
		$this->last_error = '';
		$res = $this->db->query( $sql );
		if ( false === $res ) {
			$this->last_error = (string) $this->db->error;
			return false;
		}
		if ( true === $res ) { // INSERT/UPDATE/DDL ohne Resultset.
			if ( preg_match( '/^\s*INSERT INTO/i', $sql ) ) {
				$this->insert_id = (int) $this->db->insert_id;
				return 1;
			}
			return $this->db->affected_rows;
		}
		return $res; // mysqli_result für SELECTs.
	}

	public function get_var( $sql ) {
		$res = $this->db->query( $sql );
		if ( ! $res instanceof mysqli_result ) {
			return null;
		}
		$row = $res->fetch_row();
		$res->free();
		return $row ? $row[0] : null;
	}

	public function get_row( $sql ) {
		$res = $this->db->query( $sql );
		if ( ! $res instanceof mysqli_result ) {
			return null;
		}
		$row = $res->fetch_object();
		$res->free();
		return $row ?: null;
	}

	public function get_results( $sql ) {
		$res = $this->db->query( $sql );
		if ( ! $res instanceof mysqli_result ) {
			return [];
		}
		$rows = [];
		while ( $r = $res->fetch_object() ) {
			$rows[] = $r;
		}
		$res->free();
		return $rows;
	}

	public function insert( $table, $data ) {
		$cols = array_map( fn( $c ) => "`$c`", array_keys( $data ) );
		$vals = array_map( fn( $v ) => $this->qv( $v ), array_values( $data ) );
		$sql  = 'INSERT INTO ' . $table . ' (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ')';
		return false !== $this->query( $sql ) ? true : false;
	}

	public function update( $table, $data, $where ) {
		$sets  = [];
		foreach ( $data as $c => $v ) {
			$sets[] = "`$c` = " . $this->qv( $v );
		}
		$conds = [];
		foreach ( $where as $c => $v ) {
			$conds[] = "`$c` = " . $this->qv( $v );
		}
		$sql = 'UPDATE ' . $table . ' SET ' . implode( ', ', $sets ) . ' WHERE ' . implode( ' AND ', $conds );
		$this->query( $sql );
		return '' === $this->last_error;
	}

	/** Value für SQL-Literal: null / int / string. */
	private function qv( $v ) {
		if ( null === $v ) {
			return 'NULL';
		}
		if ( is_int( $v ) ) {
			return (string) $v;
		}
		return $this->q( $v );
	}
}

// ---------------------------------------------------------------------------
// WP-API-Stubs (ausschließlich das, was vereins-voting anfasst)
// ---------------------------------------------------------------------------

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb ) {}
}
if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $cb ) { $GLOBALS['vv_activation_hook'] = $cb; }
}
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $cb ) { $GLOBALS['vv_shortcodes'] ??= []; $GLOBALS['vv_shortcodes'][ $tag ] = $cb; }
}
function absint( $v ) { return abs( intval( $v ) ); }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_email( $v ) { $v = strip_tags( (string) $v ); return filter_var( $v, FILTER_VALIDATE_EMAIL ) ? $v : ''; }
function is_email( $v ) { return filter_var( (string) $v, FILTER_VALIDATE_EMAIL ) ? $v : false; }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : stripslashes( (string) $v ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v )  { return esc_html( $v ); }
function esc_textarea( $v ) { return esc_html( $v ); }
function esc_url( $v ) { return preg_replace('/["\'<>]/i', '', (string) $v ); }
function esc_url_raw( $v ) { return esc_url( $v ); }
function current_time( $type ) {
	if ( 'mysql' === $type ) {
		return date( 'Y-m-d H:i:s' );
	}
	return date( 'H:i:s' );
}
function mysql2date( $fmt, $dt ) { return strtotime( (string) $dt ); }
function date_i18n( $fmt, $ts = null ) { return date( 'd.m.Y, H:i', (int) ( $ts ?? time() ) ); }
function get_option( $key, $default = false ) { return $GLOBALS['vv_options'][ $key ] ?? $default; }
function update_option( $key, $value ) { $GLOBALS['vv_options'][ $key ] = $value; return true; }
function get_bloginfo( $what = '' ) { return 'Testverein e.V.'; }
function add_query_arg( $args, $url = '' ) {
	$q = implode( '&', array_map( fn( $k, $v ) => urlencode( $k ) . '=' . urlencode( $v ), array_keys( $args ), $args ) );
	$sep = str_contains( $url, '?' ) ? '&' : '?';
	return $url . $sep . $q;
}
function admin_url( $path = '' ) { return 'https://admin.test/ ' . $path; }
function home_url( $path = '' ) { return 'https://admin.test/' . ltrim( $path, '/' ); }
function wp_safe_redirect( $url ) { throw new VVRedirectFound( $url ); }
function wp_die( $msg = '' ) { throw new VVWpDie( (string) $msg ); }
function check_admin_referer( $a = '', $b = '' ) { return true; }
function current_user_can( $cap ) { return true; }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

/** Nonces im Shim deterministisch pro Aktion (echtes WP nutzt Nutzer+Sitzung+Tick). */
function wp_create_nonce( $action = -1 ) { return md5( 'vvshim|' . $action ); }
function wp_verify_nonce( $nonce, $action = -1 ) {
	return hash_equals( wp_create_nonce( $action ), (string) $nonce ) ? 1 : false;
}
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
	if ( $echo ) {
		echo $field;
	}
	return $field;
}

/** Transients im Speicher mit Ablauf, damit Rate-Limits testbar sind. */
$GLOBALS['vv_transients'] = [];
function get_transient( $key ) {
	$t = $GLOBALS['vv_transients'][ $key ] ?? false;
	if ( false === $t ) {
		return false;
	}
	if ( $t['exp'] && $t['exp'] < time() ) {
		unset( $GLOBALS['vv_transients'][ $key ] );
		return false;
	}
	return $t['val'];
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['vv_transients'][ $key ] = [ 'val' => $value, 'exp' => $ttl ? time() + (int) $ttl : 0 ];
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['vv_transients'][ $key ] );
	return true;
}
function wp_enqueue_style( $id, $src = '', $deps = [], $ver = '' ) {}
function wp_register_style( $id, $src = '', $deps = [], $ver = '' ) {}
function wp_enqueue_scripts() {}
function load_plugin_textdomain( $d = '', $p = false, $q = '' ) {}
function plugins_url( $p = '', $d = '' ) { return '/static' . $p; }
function plugin_dir_path( $f ) { return rtrim( dirname( $f ), '/' ) . '/'; }

/** [FU004] wp_mail in eine Datei schreiben, damit der Versand assertbar wird. */
$GLOBALS['vv_mails'] = [];
function wp_mail( $to, $subject, $body, $headers = [] ) {
	$GLOBALS['vv_mails'][] = [ 'to' => $to, 'subject' => $subject, 'body' => $body ];
	return true;
}

/** [FU001] dbDelta-Ersatz: erwartete CREATEs einzeln ausführen. */
function dbDelta( $sql ) {
	global $wpdb;
	$chunks = array_filter( preg_split( '/(?=CREATE TABLE)/', $sql ) );
	foreach ( $chunks as $chunk ) {
		$chunk = trim( $chunk );
		if ( stripos( $chunk, 'CREATE TABLE' ) === 0 ) {
			$wpdb->query( $chunk );
		}
	}
}

function vv_boot( $dbhost, $dbuser, $dbpass, $dbname, $plugin_main = '/proj/vereins-voting/vereins-voting.php' ) {
	global $wpdb;
	$wpdb = new VVWpDb( $dbhost, $dbuser, $dbpass, $dbname );
	// Aktivierungshook braucht wp-admin/includes/upgrade.php (nur als Stub-Datei).
	@mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
	file_put_contents( ABSPATH . 'wp-admin/includes/upgrade.php', "<?php // shim-stub\n" );
	require $plugin_main;
}

/** Einfacher Assert mit Zeitausgabe. */
function vv_assert( $cond, $label ) {
	if ( ! $cond ) {
		echo "VV-FAIL: $label\n";
		exit( 1 );
	}
	echo "VV-OK: $label\n";
}
