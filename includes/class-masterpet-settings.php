<?php
/**
 * The plugin's stored state. The site secret is encrypted at rest with a key
 * derived from this site's WordPress salts, when OpenSSL is available, so a
 * leaked database dump alone does not reveal it.
 *
 * @package MasterPet
 */

defined( 'ABSPATH' ) || exit;

final class MasterPet_Settings {

	const OPTION = 'masterpet_wc_connection';

	/**
	 * @return array{site_id?:string,server?:string,branch_name?:string,status?:string,last_check?:int,last_error?:string,payment_methods?:array}
	 */
	public static function get(): array {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	public static function is_connected(): bool {
		$s = self::get();
		return ! empty( $s['site_id'] ) && ! empty( $s['secret'] ) && ( $s['status'] ?? '' ) === 'connected';
	}

	public static function save_connection( string $site_id, string $secret, string $server, ?string $branch_name ): void {
		update_option(
			self::OPTION,
			array(
				'site_id'     => $site_id,
				'secret'      => self::encrypt( $secret ),
				'server'      => $server,
				'branch_name' => $branch_name,
				'status'      => 'connected',
				'last_check'  => time(),
			),
			false // never autoloaded
		);
	}

	public static function update( array $fields ): void {
		$s = array_merge( self::get(), $fields );
		update_option( self::OPTION, $s, false );
	}

	public static function secret(): ?string {
		$s = self::get();
		return empty( $s['secret'] ) ? null : self::decrypt( $s['secret'] );
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	// ── encryption at rest ────────────────────────────────────────────────

	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|masterpet-wc', true );
	}

	private static function encrypt( string $plain ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'plain:' . $plain;
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return 'gcm:' . base64_encode( $iv . $tag . $ct );
	}

	private static function decrypt( string $stored ): ?string {
		if ( strpos( $stored, 'plain:' ) === 0 ) {
			return substr( $stored, 6 );
		}
		if ( strpos( $stored, 'gcm:' ) !== 0 || ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, 4 ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return null;
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? null : $plain;
	}
}
