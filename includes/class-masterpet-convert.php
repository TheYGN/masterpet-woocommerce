<?php
/**
 * One-time repair (T-299, PRD #13.1 D-5a): products MasterPet created on this
 * site before 0.4.0 went up as "variable" with a single variation, so the
 * shopper had to pick the only option before "add to cart". D-5a says a
 * product with one variant is a simple product.
 *
 * Each one is converted in place: the same product id, URL, images and
 * categories; the variation's SKU, prices, sale and stock move to the product
 * and the variation is removed. Then MasterPet re-links it:
 *   1. /v1/ack "missing" drops MasterPet's old link (parent + variation id);
 *   2. /v1/link-report sends it as a simple product, linked again by SKU, and
 *      the regular pull refreshes it.
 * If a step after the conversion fails, the next pull reports the product as
 * "missing" (its type no longer matches) and MasterPet's creation path finds
 * it by SKU and links it again, so nothing is left orphaned.
 *
 * Only products this plugin created for this connection and that MasterPet
 * already linked and filled (_masterpet_pending = _masterpet_product_id, the
 * same _masterpet_site), with exactly one variation. A store's own variable
 * products, and duplicates made on the site, are never touched.
 * Past orders keep their line items, but those point at the removed variation
 * (accepted, review 13.10: only test sites carry such products).
 *
 * @package MasterPet
 */

defined( 'ABSPATH' ) || exit;

final class MasterPet_Convert {

	const PER_RUN = 20;

	/**
	 * Converts up to PER_RUN products and re-links them.
	 *
	 * @return bool|string true = more may be waiting, false = nothing left, string = error code.
	 */
	public static function run() {
		if ( ! empty( MasterPet_Settings::get()['single_converted'] ) ) {
			return false;
		}
		$ids = self::candidates();
		if ( ! $ids ) {
			MasterPet_Settings::update( array( 'single_converted' => time() ) );
			return false;
		}

		$missing = array();
		$items   = array();
		$tried   = 0;
		foreach ( array_slice( $ids, 0, self::PER_RUN ) as $id ) {
			if ( $tried > 0 && MasterPet_Sync::over_budget() ) {
				break; // the rest on the next job
			}
			$tried++;
			$done = self::convert( $id );
			if ( null === $done ) {
				continue;
			}
			$missing[] = array( 'product_id' => $done['product_id'] );
			$items[]   = $done['item'];
		}
		if ( ! $items ) {
			// Every one of this batch failed: try again on a later pull. The flag
			// is set only when a scan finds none (review 13.10).
			return false;
		}
		// From here the batch is reported even past the budget: a converted
		// product must be re-linked in the same job.

		$a = MasterPet_Client::call( '/v1/ack', array( 'done' => array(), 'failed' => array(), 'missing' => $missing, 'missing_variations' => array() ) );
		if ( is_string( $a ) || 200 !== $a['status'] ) {
			return is_string( $a ) ? $a : (string) ( $a['data']['error'] ?? 'http_' . $a['status'] );
		}
		$l = MasterPet_Client::call( '/v1/link-report', array( 'items' => $items, 'done' => false ) );
		if ( is_string( $l ) || 200 !== $l['status'] ) {
			return is_string( $l ) ? $l : (string) ( $l['data']['error'] ?? 'http_' . $l['status'] );
		}
		return count( $ids ) > count( $items );
	}

	/** Ids of products this plugin created that are variable with exactly one variation. */
	private static function candidates(): array {
		$ids = wc_get_products(
			array(
				'type'       => 'variable',
				'status'     => array( 'publish', 'draft', 'pending', 'private' ),
				'limit'      => -1,
				'return'     => 'ids',
				'meta_key'   => '_masterpet_pending', // phpcs:ignore WordPress.DB.SlowDBQuery -- a one-time scan
				'meta_compare' => 'EXISTS',
			)
		);
		$site = (string) ( MasterPet_Settings::get()['site_id'] ?? '' );
		$out  = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( $p && $p->is_type( 'variable' ) && 1 === count( $p->get_children() ) && self::ours( $p, $site ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/** Created here for this connection, and MasterPet has linked and filled it since. */
	private static function ours( WC_Product $p, string $site ): bool {
		$pending = (string) $p->get_meta( '_masterpet_pending' );
		return '' !== $site && '' !== $pending
			&& $pending === (string) $p->get_meta( '_masterpet_product_id' )
			&& $site === (string) $p->get_meta( '_masterpet_site' );
	}

	/**
	 * Turns one variable product into a simple one, keeping its id.
	 *
	 * @return array{product_id:string,item:array}|null null = left as it is.
	 */
	private static function convert( int $id ): ?array {
		$parent   = wc_get_product( $id );
		$children = $parent ? $parent->get_children() : array();
		$var      = 1 === count( $children ) ? wc_get_product( (int) $children[0] ) : null;
		$pid      = $parent ? (string) $parent->get_meta( '_masterpet_pending' ) : '';
		$site     = (string) ( MasterPet_Settings::get()['site_id'] ?? '' );
		if ( ! $parent || ! $parent->is_type( 'variable' ) || ! $var || ! $var->is_type( 'variation' ) || ! self::ours( $parent, $site ) ) {
			return null;
		}

		$keep = array(
			'sku'               => (string) $var->get_sku( 'edit' ),
			'regular_price'     => $var->get_regular_price( 'edit' ),
			'sale_price'        => $var->get_sale_price( 'edit' ),
			'date_on_sale_from' => $var->get_date_on_sale_from( 'edit' ),
			'date_on_sale_to'   => $var->get_date_on_sale_to( 'edit' ),
			'manage_stock'      => $var->get_manage_stock( 'edit' ),
			'stock_quantity'    => $var->get_stock_quantity( 'edit' ),
			'stock_status'      => $var->get_stock_status( 'edit' ),
			'backorders'        => $var->get_backorders( 'edit' ),
			'weight'            => $var->get_weight( 'edit' ),
			'length'            => $var->get_length( 'edit' ),
			'width'             => $var->get_width( 'edit' ),
			'height'            => $var->get_height( 'edit' ),
			'variant_id'        => (string) $var->get_meta( '_masterpet_variant_id' ),
			'image_id'          => (int) $var->get_image_id( 'edit' ),
			'tax_class'         => $var->get_tax_class( 'edit' ),
			'shipping_class_id' => (int) $var->get_shipping_class_id( 'edit' ),
		);
		// "Same as parent" (a variation that took its stock from the product).
		if ( 'parent' === $keep['manage_stock'] ) {
			$keep['manage_stock']   = $parent->get_manage_stock( 'edit' );
			$keep['stock_quantity'] = $parent->get_stock_quantity( 'edit' );
		}
		if ( 'parent' === $keep['tax_class'] ) {
			$keep['tax_class'] = $parent->get_tax_class( 'edit' );
		}
		// Attributes stay as plain product details, not as a choice; the made-up
		// "וריאציה" one (its value was the SKU) goes.
		$attrs = array();
		foreach ( $parent->get_attributes() as $key => $attr ) {
			if ( ! $attr instanceof WC_Product_Attribute || 'וריאציה' === $attr->get_name() ) {
				continue;
			}
			$attr->set_variation( false );
			$attrs[ $key ] = $attr;
		}

		MasterPet_Lock::$writing = true;
		try {
			// The variation goes first: its SKU must be free before the product takes it.
			$var->delete( true );
			// The type itself changes on save() (WooCommerce sets the term and fires
			// woocommerce_product_type_changed), as in WooCommerce's own editor.
			if ( class_exists( 'WC_Cache_Helper' ) ) {
				WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
			}
			wc_delete_product_transients( $id );
			clean_post_cache( $id );

			$p = new WC_Product_Simple( $id );
			$p->set_attributes( $attrs );
			$p->set_sku( $keep['sku'] );
			$p->set_regular_price( $keep['regular_price'] );
			$p->set_sale_price( $keep['sale_price'] );
			$p->set_date_on_sale_from( $keep['date_on_sale_from'] );
			$p->set_date_on_sale_to( $keep['date_on_sale_to'] );
			$p->set_manage_stock( $keep['manage_stock'] );
			$p->set_stock_quantity( $keep['stock_quantity'] );
			$p->set_stock_status( $keep['stock_status'] );
			$p->set_backorders( $keep['backorders'] );
			$p->set_weight( $keep['weight'] );
			$p->set_length( $keep['length'] );
			$p->set_width( $keep['width'] );
			$p->set_height( $keep['height'] );
			$p->set_tax_class( (string) $keep['tax_class'] );
			$p->set_shipping_class_id( $keep['shipping_class_id'] );
			if ( ! $p->get_image_id( 'edit' ) && $keep['image_id'] ) {
				$p->set_image_id( $keep['image_id'] );
			}
			if ( '' !== $keep['variant_id'] ) {
				$p->update_meta_data( '_masterpet_variant_id', $keep['variant_id'] );
			}
			$p->save();
			wc_delete_product_transients( $id );
			MasterPet_Lock::remember( wc_get_product( $id ) );
		} catch ( Throwable $e ) {
			// Half-way, the variation may already be gone: take the product off the
			// storefront so nobody buys it without a price. MasterPet's next change
			// to it reports it missing, and the creation path links it again.
			wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
			return null;
		} finally {
			MasterPet_Lock::$writing = false;
		}

		$product = wc_get_product( $id );
		return array(
			'product_id' => $pid,
			'item'       => array(
				'woo_id'      => $id,
				'type'        => 'simple',
				'sku'         => $keep['sku'],
				'name'        => $product ? $product->get_name( 'edit' ) : '',
				'description' => $product ? mb_substr( MasterPet_Sync::plain( (string) $product->get_description( 'edit' ) ), 0, MasterPet_Sync::DESC_MAX ) : '',
			),
		);
	}
}
