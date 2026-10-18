<?php
/**
 * No new products on a connected site (PRD #13.1 D-8b, T-312, 0.11.0).
 *
 * MasterPet is the only place to manage products (Yarin, 16.10.2026). While the
 * site is connected, a person cannot create a product here: the "Add new" menu
 * item and button are gone, the add-new screen explains where to add products,
 * and the REST API, "Duplicate" and the CSV importer refuse to create one.
 * Products MasterPet sends are created by the sync through the WooCommerce CRUD
 * classes, which none of these paths touch. Disconnecting releases everything.
 *
 * @package MasterPet
 */

defined( 'ABSPATH' ) || exit;

final class MasterPet_No_New {

	const MESSAGE = 'מוסיפים מוצרים ב-MasterPet. המוצר עולה לאתר לבד.';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 99 );
		add_action( 'load-post-new.php', array( __CLASS__, 'add_screen' ) );
		add_action( 'admin_head-edit.php', array( __CLASS__, 'hide_button' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 99, 2 );
		add_action( 'admin_action_duplicate_product', array( __CLASS__, 'refuse_duplicate' ), 1 );
		add_filter( 'woocommerce_rest_pre_insert_product_object', array( __CLASS__, 'refuse_rest' ), 5, 3 );
		add_filter( 'woocommerce_product_import_pre_insert_product_object', array( __CLASS__, 'refuse_import' ), 5, 2 );
	}

	/** Blocking applies only while connected and only to a person, never to the sync. */
	private static function active(): bool {
		return MasterPet_Settings::is_connected() && ! MasterPet_Lock::$writing;
	}

	private static function where(): string {
		return admin_url( 'admin.php?page=' . MasterPet_Admin::SLUG );
	}

	public static function menu(): void {
		if ( self::active() ) {
			remove_submenu_page( 'edit.php?post_type=product', 'post-new.php?post_type=product' );
		}
	}

	/** The add-new screen for products, reached by a bookmark or a typed URL. */
	public static function add_screen(): void {
		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only
		if ( 'product' !== $type || ! self::active() ) {
			return;
		}
		wp_die(
			'<p>' . esc_html( self::MESSAGE ) . '</p><p><a href="' . esc_url( self::where() ) . '">' . esc_html( 'למסך MasterPet' ) . '</a></p>',
			esc_html( 'מוסיפים מוצרים ב-MasterPet' ),
			array( 'response' => 403, 'back_link' => true )
		);
	}

	public static function hide_button(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type || ! self::active() ) {
			return;
		}
		echo '<style>.post-type-product .page-title-action{display:none!important}</style>';
	}

	/**
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public static function row_actions( $actions, $post ) {
		if ( self::active() && $post instanceof WP_Post && 'product' === $post->post_type ) {
			unset( $actions['duplicate'] );
		}
		return $actions;
	}

	public static function refuse_duplicate(): void {
		if ( self::active() ) {
			wp_die( esc_html( self::MESSAGE ), esc_html( 'מוסיפים מוצרים ב-MasterPet' ), array( 'response' => 403, 'back_link' => true ) );
		}
	}

	/**
	 * A REST request that would create a product (no id yet) is refused.
	 *
	 * @param WC_Product|WP_Error $product
	 * @return WC_Product|WP_Error
	 */
	public static function refuse_rest( $product, $request = null, $creating = false ) {
		if ( $creating && self::active() && $product instanceof WC_Product ) {
			return new WP_Error( 'masterpet_no_new_products', self::MESSAGE, array( 'status' => 403 ) );
		}
		return $product;
	}

	/**
	 * The CSV importer: updating an existing product stays possible (its locked
	 * fields are put back anyway), creating a new one is refused.
	 *
	 * @param WC_Product|WP_Error $product
	 * @return WC_Product|WP_Error
	 */
	public static function refuse_import( $product, $data = array() ) {
		if ( self::active() && $product instanceof WC_Product && ! $product->get_id() ) {
			return new WP_Error( 'masterpet_no_new_products', self::MESSAGE );
		}
		return $product;
	}
}
