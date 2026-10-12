<?php
/**
 * Request signatures and connection codes. Pure PHP, no WordPress calls, so
 * tests/signature-vector.php can run it from the command line.
 *
 * Must match MasterPet's supabase/functions/_shared/woo-auth.ts exactly:
 *   base = METHOD \n route \n site_id \n ts \n nonce \n sha256hex(body)
 *   sig  = hex(HMAC-SHA256(secret, base))
 *
 * @package MasterPet
 */

defined( 'ABSPATH' ) || defined( 'MASTERPET_CLI_TEST' ) || exit;

final class MasterPet_Signature {

	const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/** Servers by the first letter of the connection code. */
	const SERVERS = array(
		'T' => 'https://api-dev.masterpet.app',
		'P' => 'https://api.masterpet.app',
	);

	/**
	 * The string that is signed.
	 */
	public static function base( string $method, string $route, string $site_id, string $ts, string $nonce, string $body ): string {
		return implode( "\n", array( strtoupper( $method ), $route, strtolower( $site_id ), $ts, $nonce, hash( 'sha256', $body ) ) );
	}

	public static function sign( string $secret, string $method, string $route, string $site_id, string $ts, string $nonce, string $body ): string {
		return hash_hmac( 'sha256', self::base( $method, $route, $site_id, $ts, $nonce, $body ), $secret );
	}

	/** 32 hex characters, fits the 16..64 [A-Za-z0-9_-] rule on the server. */
	public static function new_nonce(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Uppercase, drop spaces and dashes, map O→0 and I/L→1.
	 * Returns null unless it is <T|P> + 12 Crockford characters.
	 */
	public static function normalize_code( string $raw ): ?string {
		$s = strtoupper( preg_replace( '/[\s-]/', '', $raw ) );
		if ( strlen( $s ) !== 13 || ! isset( self::SERVERS[ $s[0] ] ) ) {
			return null;
		}
		$body = strtr( substr( $s, 1 ), array( 'O' => '0', 'I' => '1', 'L' => '1' ) );
		if ( strspn( $body, self::CROCKFORD ) !== 12 ) {
			return null;
		}
		return $s[0] . $body;
	}

	public static function server_for_code( string $normalized ): string {
		return self::SERVERS[ $normalized[0] ];
	}

	/** Seconds a signed request may be early or late (same as MasterPet). */
	const WINDOW = 300;

	/**
	 * Checks a request MasterPet sent to this site (doorbell, ping): shape,
	 * the ±300 s window, then the signature. The nonce replay check is the caller's.
	 */
	public static function verify_inbound( string $secret, string $method, string $route, string $site_id, string $ts, string $nonce, string $body, string $sig, int $now ): bool {
		if ( ! preg_match( '/^\d{9,11}$/', $ts ) || abs( $now - (int) $ts ) > self::WINDOW ) {
			return false;
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce ) || ! preg_match( '/^[0-9a-fA-F]{64}$/', $sig ) ) {
			return false;
		}
		return hash_equals( self::sign( $secret, $method, $route, $site_id, $ts, $nonce, $body ), strtolower( $sig ) );
	}

	/**
	 * The signature this site puts in its answer, so MasterPet knows the answer
	 * came from the holder of the secret. $kind: 'pong' (ping) or 'ack' (doorbell).
	 * Must match answerBase() in MasterPet's _shared/woo-doorbell.ts.
	 */
	public static function answer( string $secret, string $kind, string $site_id, string $nonce ): string {
		return hash_hmac( 'sha256', $kind . "\n" . strtolower( $site_id ) . "\n" . $nonce, $secret );
	}
}
