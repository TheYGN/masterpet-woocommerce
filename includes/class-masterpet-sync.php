<?php
/**
 * Product sync, MasterPet → this site (PRD #13.1 D-5..D-8, FR-6, FR-10, FR-12, T-279).
 *
 * The doorbell (or the "סנכרן עכשיו" button) schedules one Action Scheduler
 * job. The job:
 *   1. First link, once per connection: sends the catalog (simple products,
 *      variable products with their variations) to /v1/link-report in pages.
 *      MasterPet links by the variation SKU only; nothing here changes a product.
 *   2. Pull: /v1/changes in batches of 50, writes each product through WC CRUD,
 *      then /v1/ack with what was written, what failed and what no longer exists.
 * A product is never deleted here. Unpublished in MasterPet = draft on the site.
 *
 * @package MasterPet
 */

defined( 'ABSPATH' ) || exit;

final class MasterPet_Sync {

	const HOOK          = 'masterpet_wc_sync';
	const GROUP         = 'masterpet';
	const LOCK          = 'masterpet_wc_sync_lock';
	const LINK_PAGE     = 25;   // parents per catalog query
	const LINK_ITEMS    = 200;  // items per link-report request
	const LINK_PAGES    = 8;    // catalog pages per job (200 parents), then the job reschedules itself
	const PULL_BATCHES  = 10;   // 10 × 50 products per job, then the job reschedules itself
	const CONTINUE_LINK = 'continue';
	const DESC_MAX      = 5000;

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * One pending job at a time. as_has_scheduled_action also sees the job that
	 * is running now, so the job itself passes $continue to queue its follow-up.
	 * A doorbell that arrives during a pull is dropped safely: what it announced
	 * stays in MasterPet's queue and the clock rings again a minute later.
	 */
	public static function schedule( bool $continue = false ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		if ( ! $continue && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			return;
		}
		as_enqueue_async_action( self::HOOK, array(), self::GROUP );
	}

	public static function run(): void {
		if ( ! MasterPet_Settings::is_connected() ) {
			return;
		}
		// Action Scheduler may run two jobs at once (a doorbell during a long pull).
		if ( false !== get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		try {
			$more = false;
			$s    = MasterPet_Settings::get();
			$link = empty( $s['first_link_done'] ) ? self::first_link() : true;
			if ( self::CONTINUE_LINK === $link ) {
				$more = true;
			} elseif ( true !== $link ) {
				self::note_error( (string) $link );
			} else {
				$more = self::pull();
			}
		} finally {
			delete_transient( self::LOCK );
		}
		if ( true === $more ) {
			self::schedule( true );
		}
	}

	private static function note_error( string $code ): void {
		MasterPet_Settings::update( array( 'sync_error' => $code, 'sync_error_at' => time() ) );
	}

	/** Plain text of a description, the same normalization on both sides of a compare. */
	public static function plain( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	// ── 1. first link ─────────────────────────────────────────────────────

	/**
	 * Resumable: a big catalog spreads over several jobs (cursor link_page).
	 *
	 * @return true|string true = done, CONTINUE_LINK = more pages next job, other string = error.
	 */
	private static function first_link() {
		$s      = MasterPet_Settings::get();
		$page   = max( 1, (int) ( $s['link_page'] ?? 1 ) );
		$totals = is_array( $s['link_totals'] ?? null ) && $page > 1
			? $s['link_totals'] : array( 'linked' => 0, 'already' => 0, 'unlinked' => 0 );
		$items  = array();
		$pages  = 0;
		do {
			$ids = wc_get_products(
				array(
					'type'    => array( 'simple', 'variable' ),
					'status'  => array( 'publish', 'draft', 'pending', 'private' ),
					'limit'   => self::LINK_PAGE,
					'page'    => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'ids',
				)
			);
			foreach ( $ids as $id ) {
				$product = wc_get_product( $id );
				if ( ! $product ) {
					continue;
				}
				$desc = mb_substr( self::plain( (string) $product->get_description( 'edit' ) ), 0, self::DESC_MAX );
				if ( $product->is_type( 'variable' ) ) {
					$group   = array();
					$group[] = array( 'woo_id' => $product->get_id(), 'type' => 'variable', 'name' => $product->get_name( 'edit' ), 'description' => $desc );
					foreach ( $product->get_children() as $child_id ) {
						$v = wc_get_product( $child_id );
						if ( ! $v ) {
							continue;
						}
						$group[] = array(
							'woo_id'    => $v->get_id(),
							'type'      => 'variation',
							'parent_id' => $product->get_id(),
							'sku'       => (string) $v->get_sku( 'edit' ),
							'name'      => $v->get_name( 'edit' ),
						);
					}
					// A parent always travels with its variations; a giant one is cut, never split.
					$group = array_slice( $group, 0, 450 );
					if ( count( $items ) + count( $group ) > self::LINK_ITEMS && $items ) {
						$r = self::send_link_page( $items, false, $totals );
						if ( true !== $r ) {
							return $r;
						}
						$items = array();
					}
					$items = array_merge( $items, $group );
				} else {
					$items[] = array(
						'woo_id'      => $product->get_id(),
						'type'        => 'simple',
						'sku'         => (string) $product->get_sku( 'edit' ),
						'name'        => $product->get_name( 'edit' ),
						'description' => $desc,
					);
				}
				if ( count( $items ) >= self::LINK_ITEMS ) {
					$r = self::send_link_page( $items, false, $totals );
					if ( true !== $r ) {
						return $r;
					}
					$items = array();
				}
			}
			$page++;
			$pages++;
			$last = count( $ids ) < self::LINK_PAGE;
		} while ( ! $last && $pages < self::LINK_PAGES );

		$r = self::send_link_page( $items, $last, $totals );
		if ( true !== $r ) {
			return $r;
		}
		if ( ! $last ) {
			MasterPet_Settings::update( array( 'link_page' => $page, 'link_totals' => $totals ) );
			return self::CONTINUE_LINK;
		}
		MasterPet_Settings::update(
			array(
				'first_link_done' => time(),
				'link_result'     => $totals,
				'link_page'       => 1,
				'link_totals'     => null,
			)
		);
		return true;
	}

	/** @return true|string */
	private static function send_link_page( array $items, bool $done, array &$totals ) {
		$r = MasterPet_Client::call( '/v1/link-report', array( 'items' => array_values( $items ), 'done' => $done ) );
		if ( is_string( $r ) ) {
			return $r;
		}
		if ( 200 !== $r['status'] ) {
			return (string) ( $r['data']['error'] ?? 'http_' . $r['status'] );
		}
		foreach ( array( 'linked', 'already', 'unlinked' ) as $k ) {
			$totals[ $k ] += (int) ( $r['data'][ $k ] ?? 0 );
		}
		return true;
	}

	// ── 2. pull and write ─────────────────────────────────────────────────

	/** @return bool|string true = more is waiting (reschedule), false = done, string = error */
	private static function pull() {
		for ( $i = 0; $i < self::PULL_BATCHES; $i++ ) {
			$r = MasterPet_Client::call( '/v1/changes', array( 'limit' => 50 ) );
			if ( is_string( $r ) ) {
				self::note_error( $r );
				return $r;
			}
			if ( 200 !== $r['status'] ) {
				$code = (string) ( $r['data']['error'] ?? 'http_' . $r['status'] );
				self::note_error( $code );
				return $code;
			}
			$items = is_array( $r['data']['items'] ?? null ) ? $r['data']['items'] : array();
			$ack   = array( 'done' => array(), 'failed' => array(), 'missing' => array(), 'missing_variations' => array() );
			foreach ( $items as $item ) {
				self::apply( $item, $ack );
			}
			if ( $items ) {
				$a = MasterPet_Client::call( '/v1/ack', $ack );
				if ( is_string( $a ) || 200 !== $a['status'] ) {
					$code = is_string( $a ) ? $a : (string) ( $a['data']['error'] ?? 'http_' . $a['status'] );
					self::note_error( $code );
					return $code;
				}
			}
			MasterPet_Settings::update(
				array(
					'sync_last'  => time(),
					'sync_error' => count( $ack['failed'] ) ? 'some_failed' : '',
				)
			);
			if ( empty( $r['data']['has_more'] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function price( array $v ): string {
		$gross = 'yes' === get_option( 'woocommerce_prices_include_tax' );
		return wc_format_decimal( $gross ? $v['price_gross'] : $v['price_net'] );
	}

	/**
	 * Writes one product snapshot. Fills $ack.
	 */
	private static function apply( array $item, array &$ack ): void {
		$pid     = (string) ( $item['product_id'] ?? '' );
		$version = $item['version'] ?? null;
		$woo_id  = (int) ( $item['woo_product_id'] ?? 0 );
		$product = $woo_id ? wc_get_product( $woo_id ) : null;
		$type    = (string) ( $item['woo_type'] ?? '' );

		if ( ! $product || 'trash' === $product->get_status() || ! $product->is_type( $type ) ) {
			$ack['missing'][] = array( 'product_id' => $pid );
			return;
		}

		MasterPet_Lock::$writing = true;
		try {
			$site_id   = (string) MasterPet_Settings::get()['site_id'];
			$published = ! empty( $item['published'] );
			$variants  = is_array( $item['variants'] ?? null ) ? $item['variants'] : array();

			$product->set_name( (string) $item['name'] );
			$desc = (string) ( $item['description'] ?? '' );
			// Only when the text differs: the site's own formatting of the same words is kept.
			if ( self::plain( (string) $product->get_description( 'edit' ) ) !== self::plain( $desc ) ) {
				$product->set_description( wpautop( esc_html( $desc ) ) );
			}
			self::image( $product, (string) ( $item['image_url'] ?? '' ) );

			if ( 'simple' === $type ) {
				$v = $variants[0] ?? null;
				if ( ! $v ) {
					throw new RuntimeException( 'no_variant' );
				}
				self::stock_and_price( $product, $v );
				$published = $published && ! empty( $v['enabled'] );
				$product->update_meta_data( '_masterpet_variant_id', (string) $v['variant_id'] );
			} else {
				foreach ( $variants as $v ) {
					$var = wc_get_product( (int) $v['woo_variation_id'] );
					if ( ! $var || $var->get_parent_id() !== $product->get_id() ) {
						$ack['missing_variations'][] = array( 'variant_id' => (string) $v['variant_id'] );
						continue;
					}
					self::stock_and_price( $var, $v );
					$var->set_status( ! empty( $v['enabled'] ) ? 'publish' : 'private' );
					$var->update_meta_data( '_masterpet_site', $site_id );
					$var->update_meta_data( '_masterpet_variant_id', (string) $v['variant_id'] );
					$var->save();
					MasterPet_Lock::remember( $var );
				}
			}

			$product->set_status( $published ? 'publish' : 'draft' );
			$product->update_meta_data( '_masterpet_site', $site_id );
			$product->update_meta_data( '_masterpet_product_id', $pid );
			$product->save();
			if ( 'variable' === $type ) {
				WC_Product_Variable::sync( $product->get_id() );
			}
			MasterPet_Lock::remember( wc_get_product( $product->get_id() ) );
			$ack['done'][] = array( 'product_id' => $pid, 'version' => $version );
		} catch ( Throwable $e ) {
			$msg = $e instanceof WC_Data_Exception ? $e->getErrorCode() : get_class( $e );
			$ack['failed'][] = array( 'product_id' => $pid, 'version' => $version, 'error' => substr( (string) $msg, 0, 120 ) );
		} finally {
			MasterPet_Lock::$writing = false;
		}
	}

	private static function stock_and_price( WC_Product $p, array $v ): void {
		$sku = (string) $v['sku'];
		if ( $p->get_sku( 'edit' ) !== $sku ) {
			$p->set_sku( $sku ); // throws WC_Data_Exception when another product already uses it
		}
		$p->set_regular_price( self::price( $v ) );
		// D-8: the connected branch's stock. manage_stock lets the site lower it between updates.
		$p->set_manage_stock( true );
		$p->set_stock_quantity( max( 0, (int) $v['qty'] ) );
	}

	/**
	 * D-6: an image that came from MasterPet is kept in step; an image the site
	 * already had stays until MasterPet takes the site's images in (later phase).
	 * Only an https URL on MasterPet's own server is fetched.
	 */
	private static function image( WC_Product $product, string $url ): void {
		if ( '' === $url ) {
			return;
		}
		$ours    = (string) $product->get_meta( '_masterpet_image_url' );
		$current = (int) $product->get_image_id( 'edit' );
		if ( $current && '' === $ours ) {
			return; // the site's own image
		}
		if ( $current && $ours === $url ) {
			return; // already this one
		}
		$server = wp_parse_url( (string) ( MasterPet_Settings::get()['server'] ?? '' ), PHP_URL_HOST );
		$u      = wp_parse_url( $url );
		if ( empty( $u['scheme'] ) || 'https' !== $u['scheme'] || empty( $u['host'] ) || $u['host'] !== $server ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$att = media_sideload_image( $url, $product->get_id(), $product->get_name( 'edit' ), 'id' );
		if ( is_wp_error( $att ) ) {
			return; // price and stock still go through; the next change tries the image again
		}
		$product->set_image_id( (int) $att );
		$product->update_meta_data( '_masterpet_image_url', $url );
	}
}
