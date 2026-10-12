<?php
/**
 * Talks to MasterPet: connect with a code, signed heartbeat, signed disconnect.
 *
 * @package MasterPet
 */

defined( 'ABSPATH' ) || exit;

final class MasterPet_Client {

	const API_VERSION = '1';

	/** Server error codes → what the store owner reads. */
	private static function message( string $code ): string {
		$map = array(
			'code_invalid'           => 'הקוד לא תקין או שפג תוקפו. צור קוד חדש ב-MasterPet.',
			'feature_off'            => 'המסלול של העסק ב-MasterPet לא כולל חיבור אתר.',
			'branch_has_site'        => 'לסניף הזה כבר מחובר אתר אחר ב-MasterPet.',
			'tenant_not_operational' => 'החשבון ב-MasterPet לא פעיל כרגע.',
			'bad_request'            => 'כתובת האתר חייבת לעבוד ב-https.',
			'disconnected'           => 'החיבור נותק ב-MasterPet.',
			'bad_signature'          => 'החיבור לא מזוהה. נתק וחבר מחדש עם קוד חדש.',
			'stale_request'          => 'שעון השרת של האתר לא מדויק. בדוק את השעה בשרת.',
			'network'                => 'אין קשר ל-MasterPet כרגע. נסה שוב בעוד כמה דקות.',
		);
		return $map[ $code ] ?? 'משהו השתבש. נסה שוב בעוד כמה דקות.';
	}

	private static function versions(): array {
		global $wp_version;
		return array(
			'plugin_version' => MASTERPET_WC_VERSION,
			'wp_version'     => (string) $wp_version,
			'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : '',
		);
	}

	/**
	 * Swap a connection code for this site's secret.
	 *
	 * @return true|string true, or a message for the owner.
	 */
	public static function connect( string $raw_code ) {
		$code = MasterPet_Signature::normalize_code( $raw_code );
		if ( null === $code ) {
			return self::message( 'code_invalid' );
		}
		$server = MasterPet_Signature::server_for_code( $code );
		$body   = wp_json_encode( array_merge( array( 'code' => $code, 'site_url' => home_url() ), self::versions() ) );

		$res = wp_remote_post(
			$server . '/functions/v1/fn-woo-connect',
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			return self::message( 'network' );
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		$data   = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $status || empty( $data['site_id'] ) || empty( $data['secret'] ) ) {
			return self::message( is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : 'server' );
		}

		MasterPet_Settings::save_connection( (string) $data['site_id'], (string) $data['secret'], $server, $data['branch_name'] ?? null );
		self::heartbeat();
		return true;
	}

	/**
	 * Signed POST to fn-woo-api.
	 *
	 * @return array{status:int,data:?array}|null null when not connected or unreachable.
	 */
	private static function signed_post( string $route, array $payload ): ?array {
		$s      = MasterPet_Settings::get();
		$secret = MasterPet_Settings::secret();
		if ( empty( $s['site_id'] ) || empty( $s['server'] ) || ! $secret ) {
			return null;
		}
		$body  = wp_json_encode( $payload );
		$ts    = (string) time();
		$nonce = MasterPet_Signature::new_nonce();
		$res   = wp_remote_post(
			$s['server'] . '/functions/v1/fn-woo-api' . $route,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'      => 'application/json',
					'X-MP-Site'         => $s['site_id'],
					'X-MP-Ts'           => $ts,
					'X-MP-Nonce'        => $nonce,
					'X-MP-Sig'          => MasterPet_Signature::sign( $secret, 'POST', $route, $ts, $nonce, $body ),
					'X-MP-Api-Version'  => self::API_VERSION,
				),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $res ),
			'data'   => is_array( $data ) ? $data : null,
		);
	}

	/**
	 * "Connection check": tells MasterPet the site is alive and reads back the
	 * branch and the payment methods chosen in MasterPet.
	 *
	 * @return true|string
	 */
	public static function heartbeat() {
		if ( ! MasterPet_Settings::is_connected() ) {
			return self::message( 'disconnected' );
		}
		$r = self::signed_post( '/v1/heartbeat', self::versions() );
		if ( null === $r ) {
			MasterPet_Settings::update( array( 'last_error' => 'network' ) );
			return self::message( 'network' );
		}
		if ( 410 === $r['status'] ) {
			// Disconnected from the MasterPet side: forget the secret.
			MasterPet_Settings::update( array( 'status' => 'disconnected', 'secret' => '', 'last_error' => 'disconnected' ) );
			return self::message( 'disconnected' );
		}
		if ( 200 !== $r['status'] ) {
			$code = (string) ( $r['data']['error'] ?? 'server' );
			MasterPet_Settings::update( array( 'last_error' => $code ) );
			return self::message( $code );
		}
		MasterPet_Settings::update(
			array(
				'last_check'      => time(),
				'last_error'      => '',
				'branch_name'     => $r['data']['branch_name'] ?? null,
				'payment_methods' => is_array( $r['data']['payment_methods'] ?? null ) ? $r['data']['payment_methods'] : array(),
			)
		);
		return true;
	}

	/** Disconnect from this side. The local secret is forgotten even if MasterPet is unreachable. */
	public static function disconnect(): void {
		if ( MasterPet_Settings::is_connected() ) {
			self::signed_post( '/v1/disconnect', array() );
		}
		MasterPet_Settings::clear();
	}
}
