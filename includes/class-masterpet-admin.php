<?php
/**
 * The MasterPet screen inside WordPress (design: masterpet repo,
 * designs/woo-connection/README.md, part 4, version ב: full status screen;
 * before connecting, the code field and "חבר" button of version א).
 *
 * @package MasterPet
 */

defined( 'ABSPATH' ) || exit;

final class MasterPet_Admin {

	const SLUG = 'masterpet';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_masterpet_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_masterpet_check', array( __CLASS__, 'handle_check' ) );
		add_action( 'admin_post_masterpet_disconnect', array( __CLASS__, 'handle_disconnect' ) );
	}

	public static function menu(): void {
		add_menu_page( 'MasterPet', 'MasterPet', 'manage_woocommerce', self::SLUG, array( __CLASS__, 'render' ), 'dashicons-store', 56 );
	}

	private static function back( string $notice, string $type ): void {
		set_transient( 'masterpet_wc_notice_' . get_current_user_id(), array( 'text' => $notice, 'type' => $type ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'אין לך הרשאה לפעולה הזו.', 'masterpet-woocommerce' ), 403 );
		}
		check_admin_referer( $action );
	}

	public static function handle_connect(): void {
		self::guard( 'masterpet_connect' );
		$code = isset( $_POST['masterpet_code'] ) ? sanitize_text_field( wp_unslash( $_POST['masterpet_code'] ) ) : '';
		$res  = MasterPet_Client::connect( $code );
		if ( true === $res ) {
			self::back( 'האתר מחובר ל-MasterPet.', 'success' );
		}
		self::back( (string) $res, 'error' );
	}

	public static function handle_check(): void {
		self::guard( 'masterpet_check' );
		$res = MasterPet_Client::heartbeat();
		self::back( true === $res ? 'הקשר עם MasterPet תקין.' : (string) $res, true === $res ? 'success' : 'error' );
	}

	public static function handle_disconnect(): void {
		self::guard( 'masterpet_disconnect' );
		MasterPet_Client::disconnect();
		self::back( 'האתר נותק מ-MasterPet.', 'success' );
	}

	private static function since( int $ts ): string {
		$min = (int) max( 0, round( ( time() - $ts ) / 60 ) );
		if ( $min < 1 ) {
			return 'עכשיו';
		}
		if ( $min < 60 ) {
			return 1 === $min ? 'לפני דקה' : sprintf( 'לפני %d דקות', $min );
		}
		$h = (int) round( $min / 60 );
		return $h < 48 ? ( 1 === $h ? 'לפני שעה' : sprintf( 'לפני %d שעות', $h ) ) : sprintf( 'לפני %d ימים', (int) round( $h / 24 ) );
	}

	private static function button( string $action, string $label, string $class = 'button' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-inline-end:8px">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s      = MasterPet_Settings::get();
		$notice = get_transient( 'masterpet_wc_notice_' . get_current_user_id() );
		delete_transient( 'masterpet_wc_notice_' . get_current_user_id() );

		echo '<div class="wrap" dir="rtl">';
		echo '<h1>MasterPet' . ( MasterPet_Settings::is_connected() ? ' <span style="font-size:13px;font-weight:400;color:#50575e">· מחובר</span>' : '' ) . '</h1>';

		if ( is_array( $notice ) ) {
			$type = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $type ) . '"><p>' . esc_html( $notice['text'] ) . '</p></div>';
		}

		if ( ! MasterPet_Settings::is_connected() ) {
			if ( 'disconnected' === ( $s['status'] ?? '' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( 'החיבור נותק ב-MasterPet. כדי לחבר שוב, צור קוד חדש ב-MasterPet.' ) . '</p></div>';
			}
			echo '<p>' . esc_html( 'הדבק את קוד החיבור שקיבלת ב-MasterPet, במסך הגדרות ← אינטגרציות.' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'masterpet_connect' );
			echo '<input type="hidden" name="action" value="masterpet_connect">';
			echo '<input type="text" name="masterpet_code" class="regular-text code" dir="ltr" placeholder="T-XXXX-XXXX-XXXX" autocomplete="off" required> ';
			echo '<button type="submit" class="button button-primary">' . esc_html( 'חבר' ) . '</button>';
			echo '</form></div>';
			return;
		}

		$rows = array(
			'סניף'               => (string) ( $s['branch_name'] ?? '-' ),
			'בדיקת קשר אחרונה'   => empty( $s['last_check'] ) ? '-' : self::since( (int) $s['last_check'] ),
			'מנוהל מ-MasterPet'  => 'שם, מחיר רגיל, מלאי, מק"ט, תמונה, תיאור',
			'פתוח לעריכה כאן'    => 'מחיר מבצע, גלריה, תיאור קצר, קידום',
		);
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html( 'סנכרון המוצרים וההזמנות יופעל בעדכון הבא של התוסף.' ) . '</p>';

		self::button( 'masterpet_check', 'בדיקת קשר' );
		self::button( 'masterpet_disconnect', 'נתק', 'button button-link-delete' );
		echo '</div>';
	}
}
