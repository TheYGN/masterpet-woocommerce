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
	const DESC_MAX      = 20000; // the same cap as MasterPet, so a long text is never cut short
	const LINK_BYTES    = 600000; // a link-report request stays well under MasterPet's 1 MB
	const BUDGET        = 20;     // seconds of work per job, then it reschedules itself

	/** When this job started (microtime). */
	private static $started = 0.0;

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
		self::$started = microtime( true );
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
				// T-299: once the queue is empty, turn this plugin's single-variation
				// products into simple ones (a one-time repair, 20 per job).
				if ( false === $more ) {
					$c = MasterPet_Convert::run();
					if ( is_string( $c ) ) {
						self::note_error( $c );
					} else {
						$more = $c;
					}
				}
			}
		} finally {
			delete_transient( self::LOCK );
		}
		if ( true === $more ) {
			self::schedule( true );
		}
	}

	/**
	 * True once this job has worked BUDGET seconds. Shared hosts end a PHP run
	 * at 30 to 300 seconds, and a job cut off that way never reschedules itself
	 * (the conversion stopped after 3 rounds on the test site, 13.10); stopping
	 * early and rescheduling keeps the chain going without a new doorbell.
	 */
	public static function over_budget(): bool {
		return self::$started > 0 && microtime( true ) - self::$started > self::BUDGET;
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
		$bytes  = 0;
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
					// A parent always travels with its variations. Past 450 variations
					// the rest is not sent (MasterPet takes 500 items a request); they
					// stay unlinked and the owner links them by hand (B2).
					$group = array_slice( $group, 0, 450 );
					$size  = strlen( (string) wp_json_encode( $group, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
					if ( $items && ( count( $items ) + count( $group ) > self::LINK_ITEMS || $bytes + $size > self::LINK_BYTES ) ) {
						$r = self::send_link_page( $items, false, $totals );
						if ( true !== $r ) {
							return $r;
						}
						$items = array();
						$bytes = 0;
					}
					$items  = array_merge( $items, $group );
					$bytes += $size;
				} else {
					$one   = array(
						'woo_id'      => $product->get_id(),
						'type'        => 'simple',
						'sku'         => (string) $product->get_sku( 'edit' ),
						'name'        => $product->get_name( 'edit' ),
						'description' => $desc,
					);
					$size  = strlen( (string) wp_json_encode( $one, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
					if ( $items && $bytes + $size > self::LINK_BYTES ) {
						$r = self::send_link_page( $items, false, $totals );
						if ( true !== $r ) {
							return $r;
						}
						$items = array();
						$bytes = 0;
					}
					$items[] = $one;
					$bytes  += $size;
				}
				if ( count( $items ) >= self::LINK_ITEMS ) {
					$r = self::send_link_page( $items, false, $totals );
					if ( true !== $r ) {
						return $r;
					}
					$items = array();
					$bytes = 0;
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
		$any_failed = false;
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
			$new_links = array();
			foreach ( $items as $item ) {
				// Out of time: what is not written stays leased in MasterPet and
				// comes back on a later pull.
				if ( self::over_budget() ) {
					break;
				}
				self::apply( $item, $ack, $new_links );
			}
			// T-305: variations created here for variants new in MasterPet are linked
			// by SKU BEFORE the ack: if this fails, the batch is not acked and comes
			// back (the variations are reused by SKU, never twinned). MasterPet then
			// sends them through the regular path (price, stock).
			foreach ( array_chunk( $new_links, 400 ) as $chunk ) {
				$l = MasterPet_Client::call( '/v1/link-report', array( 'items' => $chunk, 'done' => false ) );
				if ( is_string( $l ) || 200 !== $l['status'] ) {
					$code = is_string( $l ) ? $l : (string) ( $l['data']['error'] ?? 'http_' . $l['status'] );
					self::note_error( $code );
					return $code;
				}
			}
			if ( $items ) {
				$a = MasterPet_Client::call( '/v1/ack', $ack );
				if ( is_string( $a ) || 200 !== $a['status'] ) {
					$code = is_string( $a ) ? $a : (string) ( $a['data']['error'] ?? 'http_' . $a['status'] );
					self::note_error( $code );
					return $code;
				}
			}
			// T-293: products that exist only in MasterPet are created here as drafts,
			// reported back, and then filled by the regular path on the next pull.
			$creates = is_array( $r['data']['creates'] ?? null ) ? $r['data']['creates'] : array();
			if ( $creates ) {
				$report = array( 'created' => array(), 'failed' => array() );
				foreach ( $creates as $c ) {
					self::create( $c, $report );
				}
				$cr = MasterPet_Client::call( '/v1/created', $report );
				if ( is_string( $cr ) || 200 !== $cr['status'] ) {
					$code = is_string( $cr ) ? $cr : (string) ( $cr['data']['error'] ?? 'http_' . $cr['status'] );
					self::note_error( $code );
					return $code;
				}
				$any_failed = $any_failed || count( $report['failed'] ) > 0;
			}
			$any_failed = $any_failed || count( $ack['failed'] ) > 0;
			MasterPet_Settings::update(
				array(
					'sync_last'  => time(),
					'sync_error' => $any_failed ? 'some_failed' : '',
				)
			);
			if ( self::over_budget() ) {
				return true;
			}
			if ( empty( $r['data']['has_more'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Creates the skeleton of a MasterPet product on the site (T-293): a simple
	 * product when it has one variant with no attributes, otherwise a variable
	 * product with its attributes and one variation per variant, each with its
	 * SKU. Created as a draft; the regular pull fills the name, price, stock,
	 * description, image and status right after MasterPet links it.
	 * A product that fails half-way is removed again (it is ours, seconds old),
	 * so no orphan is left behind; MasterPet retries it later.
	 */
	private static function create( array $c, array &$report ): void {
		$pid      = (string) ( $c['product_id'] ?? '' );
		$variants = is_array( $c['variants'] ?? null ) ? $c['variants'] : array();
		$attrs    = is_array( $c['attributes'] ?? null ) ? $c['attributes'] : array();
		$name     = mb_substr( sanitize_text_field( (string) ( $c['name'] ?? '' ) ), 0, 300 );
		if ( '' === $pid || ! $variants ) {
			$report['failed'][] = array( 'product_id' => $pid, 'error' => 'no_variant' );
			return;
		}
		// Several variants but no attribute in MasterPet: one made-up attribute, the SKU as its value.
		if ( count( $variants ) > 1 && ! $attrs ) {
			$values = array();
			foreach ( $variants as $i => $v ) {
				$values[]                         = (string) $v['sku'];
				$variants[ $i ]['attributes'] = array( array( 'name' => 'וריאציה', 'value' => (string) $v['sku'] ) );
			}
			$attrs = array( array( 'name' => 'וריאציה', 'values' => $values ) );
		}

		MasterPet_Lock::$writing = true;
		$new_id = 0;
		try {
			// Started here before and cut off (a PHP timeout ends the run without
			// the catch below): finish that product, never a second one.
			$pending = self::pending_parent( $pid );
			if ( $pending && $pending->is_type( 'variable' ) ) {
				$report['created'][] = array(
					'product_id'     => $pid,
					'woo_product_id' => $pending->get_id(),
					'woo_type'       => 'variable',
					'variations'     => self::ensure_variations( $pending->get_id(), $variants ),
				);
				return;
			}
			// Already here (the owner added it, or a create whose report was lost):
			// report the existing ids, never a twin.
			$found = $pending
				? array(
					'woo_product_id' => $pending->get_id(),
					'woo_type'       => 'simple',
					'variations'     => array( array( 'variant_id' => (string) $variants[0]['variant_id'], 'woo_variation_id' => $pending->get_id() ) ),
				)
				: self::existing_by_sku( $variants );
			if ( $found ) {
				$report['created'][] = array_merge( array( 'product_id' => $pid ), $found );
				return;
			}

			if ( 1 === count( $variants ) && ! $attrs ) {
				$v       = $variants[0];
				$product = new WC_Product_Simple();
				$product->set_name( $name );
				$product->set_status( 'draft' );
				$product->update_meta_data( '_masterpet_pending', $pid );
				$product->set_sku( mb_substr( wc_clean( (string) $v['sku'] ), 0, 100 ) );
				$new_id = $product->save();
				$report['created'][] = array(
					'product_id'     => $pid,
					'woo_product_id' => $new_id,
					'woo_type'       => 'simple',
					'variations'     => array( array( 'variant_id' => (string) $v['variant_id'], 'woo_variation_id' => $new_id ) ),
				);
				return;
			}

			$product    = new WC_Product_Variable();
			$wc_attrs   = array();
			$position   = 0;
			foreach ( $attrs as $a ) {
				$attr = new WC_Product_Attribute();
				$attr->set_name( self::attr_name( (string) $a['name'] ) );
				$attr->set_options( array_values( array_map( 'wc_clean', array_map( 'strval', (array) ( $a['values'] ?? array() ) ) ) ) );
				$attr->set_position( $position++ );
				$attr->set_visible( true );
				$attr->set_variation( true );
				$wc_attrs[] = $attr;
			}
			$product->set_name( $name );
			$product->set_status( 'draft' );
			$product->set_attributes( $wc_attrs );
			// Saved with the parent's first write, so a cut-off run is found and finished next time.
			$product->update_meta_data( '_masterpet_pending', $pid );
			$new_id = $product->save();

			$links = self::ensure_variations( $new_id, $variants );
			$report['created'][] = array(
				'product_id'     => $pid,
				'woo_product_id' => $new_id,
				'woo_type'       => 'variable',
				'variations'     => $links,
			);
		} catch ( Throwable $e ) {
			if ( $new_id ) {
				$half = wc_get_product( $new_id );
				if ( $half ) {
					$half->delete( true );
				}
			}
			$msg                = $e instanceof WC_Data_Exception ? $e->getErrorCode() : get_class( $e );
			$report['failed'][] = array( 'product_id' => $pid, 'error' => substr( (string) $msg, 0, 120 ) );
		} finally {
			MasterPet_Lock::$writing = false;
		}
	}

	/** The attribute name as stored on the parent; the variation key is built from the same string. */
	private static function attr_name( string $name ): string {
		return mb_substr( sanitize_text_field( $name ), 0, 100 );
	}

	/** A product this plugin started for MasterPet product $pid (any status but trash). */
	private static function pending_parent( string $pid ): ?WC_Product {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'draft', 'publish', 'private', 'pending' ),
				'meta_key'       => '_masterpet_pending', // phpcs:ignore WordPress.DB.SlowDBQuery -- one lookup per creation
				'meta_value'     => $pid, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		$p = $ids ? wc_get_product( (int) $ids[0] ) : null;
		return $p ?: null;
	}

	/**
	 * One variation per variant under $parent_id: reuses one that already has
	 * the SKU there, creates the missing ones (private until the regular pull
	 * enables it with its price). Returns the links for /v1/created.
	 */
	private static function ensure_variations( int $parent_id, array $variants ): array {
		$links = array();
		foreach ( $variants as $v ) {
			$sku      = mb_substr( wc_clean( (string) $v['sku'] ), 0, 100 );
			$existing = wc_get_product_id_by_sku( $sku );
			$found    = $existing ? wc_get_product( $existing ) : null;
			if ( $found && $found->is_type( 'variation' ) && $found->get_parent_id() === $parent_id ) {
				$links[] = array( 'variant_id' => (string) $v['variant_id'], 'woo_variation_id' => $found->get_id() );
				continue;
			}
			$var = new WC_Product_Variation();
			$var->set_parent_id( $parent_id );
			$picked = array();
			foreach ( (array) ( $v['attributes'] ?? array() ) as $va ) {
				$key            = sanitize_title( self::attr_name( (string) $va['name'] ) );
				$picked[ $key ] = wc_clean( (string) $va['value'] );
			}
			$var->set_attributes( $picked );
			$var->set_sku( $sku );
			$var->set_status( 'private' );
			$links[] = array( 'variant_id' => (string) $v['variant_id'], 'woo_variation_id' => $var->save() );
		}
		WC_Product_Variable::sync( $parent_id );
		return $links;
	}

	/**
	 * The product on this site that already carries these SKUs, in the shape
	 * /v1/created expects, or null. Simple: one variant whose SKU is a simple
	 * product. Variable: every variant's SKU is a variation of the same parent.
	 */
	private static function existing_by_sku( array $variants ): ?array {
		$first = wc_get_product_id_by_sku( (string) ( $variants[0]['sku'] ?? '' ) );
		if ( ! $first ) {
			return null;
		}
		$p = wc_get_product( $first );
		if ( ! $p ) {
			return null;
		}
		if ( $p->is_type( 'simple' ) && 1 === count( $variants ) ) {
			return array(
				'woo_product_id' => $p->get_id(),
				'woo_type'       => 'simple',
				'variations'     => array( array( 'variant_id' => (string) $variants[0]['variant_id'], 'woo_variation_id' => $p->get_id() ) ),
			);
		}
		if ( ! $p->is_type( 'variation' ) ) {
			return null;
		}
		$parent = $p->get_parent_id();
		$links  = array();
		foreach ( $variants as $v ) {
			$id  = wc_get_product_id_by_sku( (string) $v['sku'] );
			$var = $id ? wc_get_product( $id ) : null;
			if ( $var && $var->is_type( 'variation' ) && $var->get_parent_id() === $parent ) {
				$links[] = array( 'variant_id' => (string) $v['variant_id'], 'woo_variation_id' => $var->get_id() );
			}
		}
		return $links ? array( 'woo_product_id' => $parent, 'woo_type' => 'variable', 'variations' => $links ) : null;
	}

	/** The sale price in the same tax mode as the regular price, or '' for none. */
	private static function sale_price( array $v ): string {
		if ( ! isset( $v['sale_gross'] ) || null === $v['sale_gross'] ) {
			return '';
		}
		$gross = ! wc_tax_enabled() || wc_prices_include_tax();
		return wc_format_decimal( $gross ? $v['sale_gross'] : $v['sale_net'] );
	}

	/**
	 * Term ids for these names in $taxonomy, creating the missing terms (top
	 * level). Names are plain text; at most 50, each up to 200 characters.
	 *
	 * @param array<int,mixed> $names
	 * @return int[]
	 */
	private static function term_ids( array $names, string $taxonomy ): array {
		$ids = array();
		foreach ( array_slice( $names, 0, 50 ) as $raw ) {
			$name = mb_substr( sanitize_text_field( (string) $raw ), 0, 200 );
			if ( '' === $name ) {
				continue;
			}
			$found = term_exists( $name, $taxonomy );
			if ( ! $found ) {
				$found = wp_insert_term( $name, $taxonomy );
			}
			if ( is_wp_error( $found ) ) {
				// Two runs creating the same term at once: the other one won.
				$tid = (int) $found->get_error_data( 'term_exists' );
				if ( $tid ) {
					$ids[] = $tid;
				}
				continue;
			}
			$ids[] = (int) ( is_array( $found ) ? $found['term_id'] : $found );
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * FR-12. With WooCommerce tax off (common in Israel: prices typed with VAT),
	 * the price on the site is what the customer pays, so it gets the gross.
	 * With tax on, it follows "prices entered with tax".
	 */
	private static function price( array $v ): string {
		$gross = ! wc_tax_enabled() || wc_prices_include_tax();
		return wc_format_decimal( $gross ? $v['price_gross'] : $v['price_net'] );
	}

	/**
	 * Writes one product snapshot. Fills $ack.
	 */
	private static function apply( array $item, array &$ack, array &$new_links ): void {
		$pid     = (string) ( $item['product_id'] ?? '' );
		$version = $item['version'] ?? null;
		$woo_id  = (int) ( $item['woo_product_id'] ?? 0 );
		$product = $woo_id ? wc_get_product( $woo_id ) : null;
		$type    = (string) ( $item['woo_type'] ?? '' );

		if ( $product && 'trash' === $product->get_status() ) {
			// In the trash: kept linked, retried later, so restoring it resumes the sync.
			$ack['failed'][] = array( 'product_id' => $pid, 'version' => $version, 'error' => 'in_trash' );
			return;
		}
		if ( ! $product || ! $product->is_type( $type ) ) {
			$ack['missing'][] = array( 'product_id' => $pid );
			return;
		}

		MasterPet_Lock::$writing = true;
		try {
			$site_id   = (string) MasterPet_Settings::get()['site_id'];
			$published = ! empty( $item['published'] );
			$variants  = is_array( $item['variants'] ?? null ) ? $item['variants'] : array();

			// Plain text only: the name is printed on the storefront unescaped by some themes.
			$product->set_name( mb_substr( sanitize_text_field( (string) $item['name'] ), 0, 300 ) );
			$desc = (string) ( $item['description'] ?? '' );
			// Only when the text differs: the site's own formatting of the same words is kept.
			if ( self::plain( (string) $product->get_description( 'edit' ) ) !== self::plain( $desc ) ) {
				$product->set_description( wpautop( esc_html( $desc ) ) );
			}
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
					// Each variation may download an image: out of time, this product is
					// retried later (written variations and downloaded images are kept).
					if ( self::over_budget() ) {
						throw new RuntimeException( 'time_budget' );
					}
					$var = wc_get_product( (int) $v['woo_variation_id'] );
					if ( ! $var || $var->get_parent_id() !== $product->get_id() ) {
						$ack['missing_variations'][] = array( 'variant_id' => (string) $v['variant_id'] );
						continue;
					}
					self::stock_and_price( $var, $v );
					self::variation_image( $var, $v );
					$var->set_status( ! empty( $v['enabled'] ) ? 'publish' : 'private' );
					$var->update_meta_data( '_masterpet_site', $site_id );
					$var->update_meta_data( '_masterpet_variant_id', (string) $v['variant_id'] );
					$var->save();
					MasterPet_Lock::remember( $var );
				}
			}

			// T-305: variants added in MasterPet after this product went up.
			if ( 'variable' === $type && ! empty( $item['new_variants'] ) && is_array( $item['new_variants'] ) ) {
				self::add_variations( $product, $item['new_variants'], $new_links );
			}

			// Last, so a product that fails above never downloads the image again on every retry.
			self::image( $product, (string) ( $item['image_url'] ?? '' ) );
			// D-5a: categories and tags come from MasterPet, created here by name.
			// An empty list leaves the site's own categories alone (no "Uncategorized").
			if ( ! empty( $item['categories'] ) && is_array( $item['categories'] ) ) {
				$product->set_category_ids( self::term_ids( $item['categories'], 'product_cat' ) );
			}
			if ( ! empty( $item['tags'] ) && is_array( $item['tags'] ) ) {
				$product->set_tag_ids( self::term_ids( $item['tags'], 'product_tag' ) );
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
		// Plain text only (WooCommerce prints the SKU unescaped in some templates).
		$sku = mb_substr( wc_clean( (string) $v['sku'] ), 0, 100 );
		if ( $p->get_sku( 'edit' ) !== $sku ) {
			$p->set_sku( $sku ); // throws WC_Data_Exception when another product already uses it
		}
		$p->set_regular_price( self::price( $v ) );
		// D-5a: the sale price lives in MasterPet (site only). None = no sale here.
		// Only when MasterPet sends the field at all: an older MasterPet never
		// erases a sale the owner set on the site.
		if ( array_key_exists( 'sale_gross', $v ) ) {
			$sale = self::sale_price( $v );
			$p->set_sale_price( $sale );
			$p->set_date_on_sale_from( '' !== $sale && ! empty( $v['sale_from'] ) ? strtotime( (string) $v['sale_from'] ) : '' );
			$p->set_date_on_sale_to( '' !== $sale && ! empty( $v['sale_to'] ) ? strtotime( (string) $v['sale_to'] ) : '' );
		}
		// D-8: the connected branch's stock. manage_stock lets the site lower it between updates.
		$p->set_manage_stock( true );
		$p->set_stock_quantity( max( 0, (int) $v['qty'] ) );
	}

	/**
	 * T-305: a variation for each variant MasterPet added to this (variable)
	 * product. The attribute value is added to the parent's options first (a
	 * value missing there cannot be picked on the storefront), then the
	 * variation is created by SKU (reused if it is already there) and queued for
	 * /v1/link-report, which links it and makes MasterPet send its price and
	 * stock. A variant still without attribute values waits (MasterPet sends the
	 * product again once they are linked); a variant of an attribute the
	 * product does not have here (a new dimension), or of the site's global
	 * attributes (pa_*), is skipped. At most 10 per product per pull, and none
	 * once the time budget is spent: the rest come on the next pull.
	 */
	private static function add_variations( WC_Product $product, array $new, array &$new_links ): void {
		$attrs   = $product->get_attributes();
		$by_name = array();
		foreach ( $attrs as $key => $a ) {
			if ( $a instanceof WC_Product_Attribute && $a->get_variation() ) {
				$by_name[ $a->get_name() ] = $key;
			}
		}
		$made_up = isset( $by_name['וריאציה'] ); // created with the SKU as the value (no attributes in MasterPet)
		$ready   = array();
		foreach ( $new as $v ) {
			if ( count( $ready ) >= 10 || self::over_budget() ) {
				break;
			}
			$sku = (string) ( $v['sku'] ?? '' );
			if ( '' === $sku || empty( $v['variant_id'] ) ) {
				continue;
			}
			$va = is_array( $v['attributes'] ?? null ) ? $v['attributes'] : array();
			if ( ! $va && $made_up ) {
				$va = array( array( 'name' => 'וריאציה', 'value' => $sku ) );
			}
			if ( ! $va ) {
				continue;
			}
			$ok = true;
			foreach ( $va as $pair ) {
				$name = self::attr_name( (string) ( $pair['name'] ?? '' ) );
				if ( ! isset( $by_name[ $name ] ) ) {
					$ok = false;
					break;
				}
			}
			if ( ! $ok || count( $va ) !== count( $by_name ) ) {
				continue;
			}
			foreach ( $va as $pair ) {
				$key     = $by_name[ self::attr_name( (string) $pair['name'] ) ];
				$attr    = $attrs[ $key ];
				$options = $attr->get_options();
				$value   = wc_clean( (string) $pair['value'] );
				if ( ! in_array( $value, $options, true ) ) {
					$options[] = $value;
					$attr->set_options( $options );
					$attrs[ $key ] = $attr;
				}
			}
			$ready[] = array( 'variant_id' => (string) $v['variant_id'], 'sku' => $sku, 'attributes' => $va );
		}
		if ( ! $ready ) {
			return;
		}
		$product->set_attributes( $attrs );
		$product->save();
		try {
			$links = self::ensure_variations( $product->get_id(), $ready );
		} catch ( Throwable $e ) {
			return; // e.g. the SKU is taken by another product here; the rest of the update still goes through
		}
		$new_links[] = array(
			'woo_id'      => $product->get_id(),
			'type'        => 'variable',
			'name'        => $product->get_name( 'edit' ),
			'description' => mb_substr( self::plain( (string) $product->get_description( 'edit' ) ), 0, self::DESC_MAX ),
		);
		foreach ( $links as $i => $l ) {
			$new_links[] = array(
				'woo_id'    => (int) $l['woo_variation_id'],
				'type'      => 'variation',
				'parent_id' => $product->get_id(),
				'sku'       => mb_substr( wc_clean( $ready[ $i ]['sku'] ), 0, 100 ),
				'name'      => '',
			);
		}
	}

	/**
	 * T-301: a variation's own image from MasterPet. None in MasterPet: an image
	 * MasterPet set before is taken off, so the variation shows the product's
	 * image again; an image the site set itself stays. An older MasterPet that
	 * does not send the field changes nothing.
	 */
	private static function variation_image( WC_Product $var, array $v ): void {
		if ( ! array_key_exists( 'image_url', $v ) ) {
			return;
		}
		$url = (string) ( $v['image_url'] ?? '' );
		if ( '' !== $url ) {
			self::image( $var, $url );
			return;
		}
		if ( '' !== (string) $var->get_meta( '_masterpet_image_url' ) ) {
			$var->set_image_id( 0 );
			$var->delete_meta_data( '_masterpet_image_url' );
		}
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
		// Only MasterPet's public storage on its own server: https, same host, no port.
		$server = wp_parse_url( (string) ( MasterPet_Settings::get()['server'] ?? '' ), PHP_URL_HOST );
		$u      = wp_parse_url( $url );
		if ( empty( $u['scheme'] ) || 'https' !== $u['scheme'] || empty( $u['host'] ) || $u['host'] !== $server
			|| isset( $u['port'] ) || isset( $u['user'] ) || 0 !== strpos( (string) ( $u['path'] ?? '' ), '/storage/v1/object/public/' ) ) {
			return;
		}
		// The same URL already downloaded (for this or an earlier try): reuse it.
		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'meta_key'       => '_masterpet_source_url', // phpcs:ignore WordPress.DB.SlowDBQuery -- one indexed lookup per image change
				'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		if ( $existing ) {
			$att = (int) $existing[0];
		} else {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$att = media_sideload_image( $url, $product->get_id(), $product->get_name( 'edit' ), 'id' );
			if ( is_wp_error( $att ) ) {
				return; // price and stock still go through; the next change tries the image again
			}
			update_post_meta( (int) $att, '_masterpet_source_url', $url );
		}
		$product->set_image_id( (int) $att );
		$product->update_meta_data( '_masterpet_image_url', $url );
	}
}
