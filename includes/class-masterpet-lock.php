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
		// A duplicate made on the site is the site's own product, not MasterPet's.
		add_filter( 'woocommerce_duplicate_product_exclude_meta', array( __CLASS__, 'not_copied' ) );
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
			'category_ids'      => $product->get_category_ids( 'edit' ),
			'tag_ids'           => $product->get_tag_ids( 'edit' ),
			'sale_price'        => $product->get_sale_price( 'edit' ),
			'date_on_sale_from' => self::ts( $product->get_date_on_sale_from( 'edit' ) ),
			'date_on_sale_to'   => self::ts( $product->get_date_on_sale_to( 'edit' ) ),
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

	/** @param string[] $keys */
	public static function not_copied( $keys ): array {
		return array_merge( (array) $keys, array( '_masterpet_site', '_masterpet_product_id', '_masterpet_variant_id', self::META, '_masterpet_image_url' ) );
	}

	/**
	 * Puts every locked field back to what MasterPet wrote.
	 * Name, SKU, regular price, description, status, image: compares the
	 * CURRENT value with what MasterPet wrote. The WordPress edit screen writes
	 * the title, content, status and featured image to the database before
	 * WooCommerce loads the product, so those never show up as changes (code
	 * review 12.10).
	 * Stock: only a change in THIS save is put back, and to the value the
	 * product had before it (not MasterPet's last number), because the site
	 * itself lowers stock on every sale between updates (second review 12.10).
	 */
	private static function revert( WC_Product $product, bool $with_stock ): void {
		if ( self::$writing || 0 === $product->get_id() || ! self::is_managed( $product ) ) {
			return;
		}
		$snap = $product->get_meta( self::META );
		if ( ! is_array( $snap ) ) {
			return;
		}
		if ( $with_stock ) {
			$changes = $product->get_changes();
			$before  = $product->get_data(); // the values as loaded, before this save's changes
			foreach ( array( 'manage_stock', 'stock_quantity' ) as $key ) {
				if ( array_key_exists( $key, $changes ) && ! self::same( $key, $changes[ $key ], $before[ $key ] ?? null ) ) {
					$setter = 'set_' . $key;
					$product->{$setter}( $before[ $key ] ?? null );
				}
			}
		}
		// D-5a: categories, tags and the sale price come from MasterPet too.
		$keys = array( 'name', 'sku', 'regular_price', 'description', 'status', 'image_id',
			'category_ids', 'tag_ids', 'sale_price', 'date_on_sale_from', 'date_on_sale_to' );
		foreach ( $keys as $key ) {
			// A product in the trash stays there; the sync reports it and waits.
			if ( 'status' === $key && 'trash' === $product->get_status( 'edit' ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, $snap ) ) {
				continue;
			}
			$getter = 'get_' . $key;
			$now    = $product->{$getter}( 'edit' );
			if ( 'date_on_sale_from' === $key || 'date_on_sale_to' === $key ) {
				$now = self::ts( $now );
			}
			if ( self::same( $key, $now, $snap[ $key ] ) ) {
				continue;
			}
			$setter = 'set_' . $key;
			$value  = $snap[ $key ];
			if ( ( 'date_on_sale_from' === $key || 'date_on_sale_to' === $key ) ) {
				$value = '' === (string) $value ? '' : (int) $value;
			}
			try {
				$product->{$setter}( $value );
			} catch ( Throwable $e ) {
				continue; // e.g. the SKU is taken now; the next sync reports it
			}
		}
	}

	/** A WC_DateTime (or nothing) as a unix time string, '' for none. */
	private static function ts( $d ): string {
		return $d instanceof WC_DateTime ? (string) $d->getTimestamp() : '';
	}

	/** Prices as numbers, term lists as sets, everything else as exact strings (never PHP's loose ==). */
	private static function same( string $key, $a, $b ): bool {
		if ( 'category_ids' === $key || 'tag_ids' === $key ) {
			$a = array_map( 'intval', (array) $a );
			$b = array_map( 'intval', (array) $b );
			sort( $a );
			sort( $b );
			return $a === $b;
		}
		if ( 'sale_price' === $key && ( '' === (string) $a || '' === (string) $b ) ) {
			return (string) $a === (string) $b;
		}
		if ( 'sale_price' === $key ) {
			return wc_format_decimal( $a, wc_get_price_decimals() ) === wc_format_decimal( $b, wc_get_price_decimals() );
		}
		if ( 'regular_price' === $key || 'stock_quantity' === $key ) {
			if ( '' === (string) $a || '' === (string) $b || null === $a || null === $b ) {
				return (string) $a === (string) $b;
			}
			return wc_format_decimal( $a, wc_get_price_decimals() ) === wc_format_decimal( $b, wc_get_price_decimals() );
		}
		if ( 'manage_stock' === $key ) {
			return (bool) $a === (bool) $b;
		}
		return (string) $a === (string) $b;
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
		echo '<div class="notice notice-info"><p>' . esc_html( 'המוצר מנוהל ב-MasterPet: שם, מק"ט, מחיר רגיל ומחיר מבצע, מלאי, תיאור, סטטוס, קטגוריות, תגיות ותמונה ראשית מתעדכנים משם, ושינוי שלהם כאן לא יישמר. גלריה, תיאור קצר וקידום פתוחים לעריכה.' ) . '</p></div>';
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
		$('#title, #_sku, #_regular_price, #_sale_price, #_stock, input[name^="variable_sku"], input[name^="variable_regular_price"], input[name^="variable_sale_price"], input[name^="variable_stock"]')
			.prop('readonly', true).css('background', '#f0f0f1');
		$('#_manage_stock, input[name^="variable_manage_stock"], input[name^="variable_enabled"]').prop('disabled', true);
		// Description, status and the main image are put back on save; greyed so the owner sees it first.
		$('#postdivrich, #submitdiv .misc-pub-post-status, #postimagediv, #product_catdiv, #tagsdiv-product_tag').css({ opacity: 0.6, 'pointer-events': 'none' });
	};
	lock();
	$('#woocommerce-product-data').on('woocommerce_variations_loaded', lock);
});
</script>
		<?php
	}
}
