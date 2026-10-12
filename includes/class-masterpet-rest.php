<?php
/**
 * The two doors MasterPet knocks on (PRD #13.1 FR-10, T-279):
 *   POST masterpet/v1/ping      proves this site holds the secret (answer: pong signature)
 *   POST masterpet/v1/doorbell  "changes are waiting": schedules a pull (answer: ack signature)
 *
 * Both accept only a request signed by MasterPet with this site's secret,
 * inside ±300 s, with a nonce not seen before. No data arrives through here:
 * the plugin pulls the changes itself from MasterPet.
 *
 * @package MasterPet
 */

defined( 'ABSPATH' ) || exit;

final class MasterPet_Rest {

	const NS = 'masterpet/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		foreach ( array( 'ping', 'doorbell' ) as $name ) {
			register_rest_route(
				self::NS,
				'/' . $name,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, $name ),
					'permission_callback' => array( __CLASS__, 'signed' ),
				)
			);
		}
	}

	/**
	 * permission_callback: the request is MasterPet's, for this site, fresh and new.
	 *
	 * @return true|WP_Error
	 */
	public static function signed( WP_REST_Request $request ) {
		$deny = new WP_Error( 'masterpet_unauthorized', 'unauthorized', array( 'status' => 401 ) );
		if ( ! MasterPet_Settings::is_connected() ) {
			return $deny;
		}
		$s      = MasterPet_Settings::get();
		$secret = MasterPet_Settings::secret();
		if ( ! $secret ) {
			return $deny;
		}
		$site  = strtolower( (string) $request->get_header( 'x_mp_site' ) );
		$ts    = (string) $request->get_header( 'x_mp_ts' );
		$nonce = (string) $request->get_header( 'x_mp_nonce' );
		$sig   = (string) $request->get_header( 'x_mp_sig' );
		if ( ! hash_equals( strtolower( (string) $s['site_id'] ), $site ) ) {
			return $deny;
		}
		$route = '/' . self::NS . '/' . basename( $request->get_route() );
		if ( ! MasterPet_Signature::verify_inbound( $secret, 'POST', $route, $site, $ts, $nonce, $request->get_body(), $sig, time() ) ) {
			return $deny;
		}
		// Replay guard. Kept 20 minutes, well past the window. A replayed doorbell
		// could only schedule one more pull, so a transient (not atomic without an
		// object cache) is enough here.
		$key = 'masterpet_wc_n_' . md5( $nonce );
		if ( false !== get_transient( $key ) ) {
			return $deny;
		}
		set_transient( $key, 1, 20 * MINUTE_IN_SECONDS );
		return true;
	}

	private static function answer( WP_REST_Request $request, string $kind ): WP_REST_Response {
		$s = MasterPet_Settings::get();
		return new WP_REST_Response(
			array(
				'ok'  => true,
				'sig' => MasterPet_Signature::answer( (string) MasterPet_Settings::secret(), $kind, (string) $s['site_id'], (string) $request->get_header( 'x_mp_nonce' ) ),
			),
			200
		);
	}

	public static function ping( WP_REST_Request $request ): WP_REST_Response {
		return self::answer( $request, 'pong' );
	}

	public static function doorbell( WP_REST_Request $request ): WP_REST_Response {
		MasterPet_Sync::schedule();
		return self::answer( $request, 'ack' );
	}
}
