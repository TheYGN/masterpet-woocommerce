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
 * Only products this plugin created (meta _masterpet_pending) and that have
 * exactly one variation. A store's own variable products are never touched.
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
		foreach ( array_slice( $ids, 0, self::PER_RUN ) as $id ) {
			$done = self::convert( $id );
			if ( null === $done ) {
				continue;
			}
			$missing[] = array( 'product_id' => $done['product_id'] );
			$items[]   = $done['item'];
		}
		if ( ! $items ) {
			// Nothing convertible after all (each one refused): stop scanning.
			MasterPet_Settings::update( array( 'single_converted' => time() ) );
			return false;
		}

		$a = MasterPet_Client::call( '/v1/ack', array( 'done' => array(), 'failed' => array(), 'missing' => $missing, 'missing_variations' => array() ) );
		if ( is_string( $a ) || 200 !== $a['status'] ) {
			return is_string( $a ) ? $a : (string) ( $a['data']['error'] ?? 'http_' . $a['status'] );
		}
		$l = MasterPet_Client::call( '/v1/link-report', array( 'items' => $items, 'done' => false ) );
		if ( is_string( $l ) || 200 !== $l['status'] ) {
			return is_string( $l ) ? $l : (string) ( $l['data']['error'] ?? 'http_' . $l['status'] );
		}
		return count( $ids ) > self::PER_RUN;
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
		$out = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( $p && $p->is_type( 'variable' ) && 1 === count( $p->get_children() ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
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
		if ( ! $parent || ! $parent->is_type( 'variable' ) || ! $var || ! $var->is_type( 'variation' ) || '' === $pid ) {
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
		);
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
			wp_set_object_terms( $id, 'simple', 'product_type' );
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
			// Half-way (the variation is already gone): the next pull reports it
			// missing and MasterPet links it again by SKU. Nothing to undo here.
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
