<?php
/**
 * Locked fields (PRD #13.1 D-5, FR-13, T-279).
 *
 * On a product MasterPet manages (it carries this connection's site id in
 * _masterpet_site), these come from MasterPet only: name, SKU, regular price,
 * stock, description, status, and the main image when MasterPet set it.
 * Open on the site: sale price and dates, gallery, short description, SEO,
 * anything MasterPet does not have.
 *
 * Two layers:
 *   1. The edit screen greys the fields and says why.
 *   2. Any save puts the locked values back: the admin form and the REST API
 *      (stock too), and every other save (name, SKU, price, description,
 *      status, image; stock is left alone there so an order can still lower it).
 * Disconnecting releases everything (the site id no longer matches).
 *
 * @package MasterPet
 */

defined( 'ABSPATH' ) || exit;

final class MasterPet_Lock {

	const META = '_masterpet_locked';

	/** True while the sync itself writes, so its own changes are not reverted. */
	public static $writing = false;

	public static function init(): void {
		foreach ( array( 'woocommerce_before_product_object_save', 'woocommerce_before_product_variation_object_save' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_any_save' ) );
		}
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'on_form_save' ) );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'on_form_save' ) );
		add_filter( 'woocommerce_rest_pre_insert_product_object', array( __CLASS__, 'on_rest_save' ) );
		add_filter( 'woocommerce_rest_pre_insert_product_variation_object', array( __CLASS__, 'on_rest_save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_footer', array( __CLASS__, 'grey_out' ) );
	}

	public static function is_managed( $product ): bool {
		if ( ! $product instanceof WC_Product || ! MasterPet_Settings::is_connected() ) {
			return false;
		}
		$site = (string) ( MasterPet_Settings::get()['site_id'] ?? '' );
		return '' !== $site && $site === (string) $product->get_meta( '_masterpet_site' );
	}

	/** Stores what MasterPet wrote, right after the sync saved it. */
	public static function remember( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$snap = array(
			'name'           => $product->get_name( 'edit' ),
			'sku'            => $product->get_sku( 'edit' ),
			'regular_price'  => $product->get_regular_price( 'edit' ),
			'description'    => $product->get_description( 'edit' ),
			'status'         => $product->get_status( 'edit' ),
			'manage_stock'   => $product->get_manage_stock( 'edit' ),
			'stock_quantity' => $product->get_stock_quantity( 'edit' ),
		);
		if ( '' !== (string) $product->get_meta( '_masterpet_image_url' ) ) {
			$snap['image_id'] = $product->get_image_id( 'edit' );
		}
		$was           = self::$writing;
		self::$writing = true;
		try {
			$product->update_meta_data( self::META, $snap );
			$product->save_meta_data();
		} finally {
			self::$writing = $was;
		}
	}

	private static function revert( WC_Product $product, bool $with_stock ): void {
		if ( self::$writing || ! self::is_managed( $product ) ) {
			return;
		}
		$snap = $product->get_meta( self::META );
		if ( ! is_array( $snap ) ) {
			return;
		}
		$keys = array( 'name', 'sku', 'regular_price', 'description', 'status', 'image_id' );
		if ( $with_stock ) {
			$keys[] = 'manage_stock';
			$keys[] = 'stock_quantity';
		}
		$changes = $product->get_changes();
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $snap ) || ! array_key_exists( $key, $changes ) ) {
				continue;
			}
			if ( $changes[ $key ] == $snap[ $key ] ) { // phpcs:ignore WordPress.PHP.StrictComparisons -- '10' vs '10.00' style values
				continue;
			}
			$setter = 'set_' . $key;
			try {
				$product->{$setter}( $snap[ $key ] );
			} catch ( Throwable $e ) {
				continue; // e.g. the SKU is taken now; the next sync reports it
			}
		}
	}

	public static function on_any_save( $product ): void {
		if ( $product instanceof WC_Product ) {
			self::revert( $product, false );
		}
	}

	public static function on_form_save( $product ): void {
		if ( $product instanceof WC_Product ) {
			self::revert( $product, true );
		}
	}

	/** @return mixed the same object (filter). */
	public static function on_rest_save( $product ) {
		if ( $product instanceof WC_Product ) {
			self::revert( $product, true );
		}
		return $product;
	}

	private static function edited_product(): ?WC_Product {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return null;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id || empty( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read only
			return null;
		}
		$product = wc_get_product( absint( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- read only
		return $product ?: null;
	}

	public static function notice(): void {
		$product = self::edited_product();
		if ( ! $product || ! self::is_managed( $product ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html( 'המוצר מנוהל ב-MasterPet: שם, מק"ט, מחיר רגיל, מלאי, תיאור, סטטוס ותמונה ראשית מתעדכנים משם, ושינוי שלהם כאן לא יישמר. מחיר מבצע, גלריה, תיאור קצר וקידום פתוחים לעריכה.' ) . '</p></div>';
	}

	public static function grey_out(): void {
		$product = self::edited_product();
		if ( ! $product || ! self::is_managed( $product ) ) {
			return;
		}
		?>
<script>
jQuery(function ($) {
	var lock = function () {
		$('#title, #_sku, #_regular_price, #_stock, input[name^="variable_sku"], input[name^="variable_regular_price"], input[name^="variable_stock"]')
			.prop('readonly', true).css('background', '#f0f0f1');
		$('#_manage_stock, input[name^="variable_manage_stock"], input[name^="variable_enabled"]').prop('disabled', true);
	};
	lock();
	$('#woocommerce-product-data').on('woocommerce_variations_loaded', lock);
});
</script>
		<?php
	}
}
