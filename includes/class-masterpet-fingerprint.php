<?php
/**
 * The nightly check (PRD #13.1 FR-11, T-291, 0.12.0).
 *
 * Once a day the plugin sends MasterPet what it holds for every linked item:
 * the SKU, the regular price (in this store's tax mode) and the stock. MasterPet
 * compares it with what it would send now and queues every product that
 * differs, so a change that never reached the site (a skipped trigger, a direct
 * database edit, a write that failed here) is put right within a day.
 *
 * Runs on Action Scheduler at night (site time). A big catalog spreads over
 * several jobs: the job stops after BUDGET seconds, keeps its place (fp_cursor)
 * and queues its own follow-up. Nothing is written to the products here.
 *
 * @package MasterPet
 */

defined( 'ABSPATH' ) || exit;

final class MasterPet_Fingerprint {

	const HOOK      = 'masterpet_wc_fingerprint';
	const LOCK      = 'masterpet_wc_fingerprint_lock';
	const PAGE      = 50;   // parents per catalog query
	const ITEMS     = 400;  // items per request (MasterPet takes 500)
	const BUDGET    = 20;   // seconds of work per job, then it continues in a new job
	const MIN_GAP   = 72000; // 20 hours: at most one full check a day

	/** When this job started (microtime). */
	private static $started = 0.0;

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/** One recurring job a day, at 03:00 site time. */
	public static function ensure_scheduled(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! MasterPet_Settings::is_connected() ) {
			return;
		}
		if ( as_has_scheduled_action( self::HOOK, array(), MasterPet_Sync::GROUP ) ) {
			return;
		}
		$next = new DateTime( 'tomorrow 03:00', wp_timezone() );
		as_schedule_recurring_action( $next->getTimestamp(), DAY_IN_SECONDS, self::HOOK, array(), MasterPet_Sync::GROUP );
	}

	public static function run(): void {
		if ( ! MasterPet_Settings::is_connected() ) {
			return;
		}
		$s      = MasterPet_Settings::get();
		$cursor = (int) ( $s['fp_cursor'] ?? 0 );
		// A new check starts at most once in 20 hours; a check already under way goes on.
		if ( 0 === $cursor && time() - (int) ( $s['fp_done_at'] ?? 0 ) < self::MIN_GAP ) {
			return;
		}
		if ( false !== get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		self::$started = microtime( true );
		try {
			self::check( $cursor, (string) ( $s['site_id'] ?? '' ) );
		} finally {
			delete_transient( self::LOCK );
		}
	}

	private static function over_budget(): bool {
		return microtime( true ) - self::$started > self::BUDGET;
	}

	private static function check( int $cursor, string $site_id ): void {
		$gross = ! wc_tax_enabled() || wc_prices_include_tax();
		$items = array();
		for ( ;; ) {
			global $wpdb;
			// Linked products after the cursor, in id order (a WP_Query cannot say "id > n").
			$ids = array_map(
				'intval',
				$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- once a day, paged, read only
					$wpdb->prepare(
						"SELECT p.ID FROM {$wpdb->posts} p
						 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_masterpet_site' AND m.meta_value = %s
						 WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private') AND p.ID > %d
						 ORDER BY p.ID ASC LIMIT %d",
						$site_id,
						$cursor,
						self::PAGE
					)
				)
			);
			if ( ! $ids ) {
				if ( true !== self::send( $items, true ) ) {
					return;
				}
				MasterPet_Settings::update( array( 'fp_cursor' => 0, 'fp_done_at' => time() ) );
				return;
			}
			foreach ( $ids as $id ) {
				$product = wc_get_product( $id );
				if ( $product ) {
					foreach ( self::items_of( $product, $gross ) as $item ) {
						$items[] = $item;
					}
				}
				$cursor = $id;
				if ( count( $items ) >= self::ITEMS ) {
					if ( true !== self::send( $items, false ) ) {
						return;
					}
					$items = array();
					MasterPet_Settings::update( array( 'fp_cursor' => $cursor ) );
				}
			}
			if ( self::over_budget() ) {
				if ( $items && true !== self::send( $items, false ) ) {
					return;
				}
				MasterPet_Settings::update( array( 'fp_cursor' => $cursor ) );
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action( self::HOOK, array(), MasterPet_Sync::GROUP );
				}
				return;
			}
		}
	}

	/**
	 * The fingerprint of a simple product, or of each variation of a variable one.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function items_of( WC_Product $product, bool $gross ): array {
		$out = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child ) {
				$var = wc_get_product( $child );
				if ( $var ) {
					$out[] = self::item( $var, $gross );
				}
			}
			return $out;
		}
		return array( self::item( $product, $gross ) );
	}

	/** @return array<string,mixed> */
	private static function item( WC_Product $p, bool $gross ): array {
		$item = array(
			'woo_id'      => $p->get_id(),
			'sku'         => (string) $p->get_sku( 'edit' ),
			'price_gross' => $gross,
		);
		$regular = (string) $p->get_regular_price( 'edit' );
		if ( '' !== $regular && is_numeric( $regular ) ) {
			$item['price'] = wc_format_decimal( $regular, 2 );
		}
		if ( $p->managing_stock() ) {
			$item['stock'] = (int) $p->get_stock_quantity( 'edit' );
		}
		return $item;
	}

	/** @return true|string */
	private static function send( array $items, bool $done ) {
		$r = MasterPet_Client::call( '/v1/fingerprint', array( 'items' => array_values( $items ), 'done' => $done ) );
		if ( ! is_array( $r ) ) {
			return (string) $r;
		}
		if ( 200 !== $r['status'] ) {
			return 'fingerprint_' . $r['status'];
		}
		return true;
	}
}
