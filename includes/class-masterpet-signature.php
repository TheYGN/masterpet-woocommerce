<?php
/**
 * Request signatures and connection codes. Pure PHP, no WordPress calls, so
 * tests/signature-vector.php can run it from the command line.
 *
 * Must match MasterPet's supabase/functions/_shared/woo-auth.ts exactly:
 *   base = METHOD \n route \n ts \n nonce \n sha256hex(body)
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
	public static function base( string $method, string $route, string $ts, string $nonce, string $body ): string {
		return implode( "\n", array( strtoupper( $method ), $route, $ts, $nonce, hash( 'sha256', $body ) ) );
	}

	public static function sign( string $secret, string $method, string $route, string $ts, string $nonce, string $body ): string {
		return hash_hmac( 'sha256', self::base( $method, $route, $ts, $nonce, $body ), $secret );
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
}
