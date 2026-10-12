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
		add_action( 'admin_post_masterpet_sync', array( __CLASS__, 'handle_sync' ) );
		add_action( 'admin_post_masterpet_relink', array( __CLASS__, 'handle_relink' ) );
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
		if ( MasterPet_Client::disconnect() ) {
			self::back( 'האתר נותק מ-MasterPet.', 'success' );
		}
		self::back( 'האתר נותק כאן, אבל MasterPet לא אישר. נתק גם במסך האינטגרציות ב-MasterPet, אחרת הסניף יישאר תפוס.', 'error' );
	}

	public static function handle_sync(): void {
		self::guard( 'masterpet_sync' );
		MasterPet_Sync::schedule();
		self::back( 'הסנכרון התחיל. המוצרים יתעדכנו בדקות הקרובות.', 'success' );
	}

	/** Runs the SKU link again: picks up products added to the site or to MasterPet since. */
	public static function handle_relink(): void {
		self::guard( 'masterpet_relink' );
		MasterPet_Settings::update( array( 'first_link_done' => 0, 'link_page' => 1, 'link_totals' => null ) );
		MasterPet_Sync::schedule();
		self::back( 'הקישור לפי מק"ט רץ שוב. מוצרים שכבר מקושרים לא משתנים.', 'success' );
	}

	/** Sync error codes → what the store owner reads. */
	private static function sync_error( string $code ): string {
		$map = array(
			'some_failed'       => 'חלק מהמוצרים לא התעדכנו (למשל מק"ט שכבר תפוס באתר). ננסה שוב אוטומטית.',
			'network'           => 'אין קשר ל-MasterPet כרגע. ננסה שוב אוטומטית.',
			'disconnected'      => 'החיבור נותק ב-MasterPet.',
			'secret_unreadable' => 'פרטי החיבור באתר כבר לא קריאים. נתק וחבר מחדש עם קוד חדש.',
		);
		return $map[ $code ] ?? 'העדכון האחרון נכשל. ננסה שוב אוטומטית.';
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
			'עדכון מוצרים אחרון' => empty( $s['sync_last'] ) ? 'עוד לא' : self::since( (int) $s['sync_last'] ),
			'קישור לפי מק"ט'     => empty( $s['first_link_done'] ) ? ( empty( $s['sync_error'] ) ? 'רץ עכשיו' : 'נעצר, ננסה שוב אוטומטית' ) : sprintf(
				'%d מקושרים, %d לא מקושרים',
				(int) ( $s['link_result']['linked'] ?? 0 ) + (int) ( $s['link_result']['already'] ?? 0 ),
				(int) ( $s['link_result']['unlinked'] ?? 0 )
			),
			'מנוהל מ-MasterPet'  => 'שם, מחיר רגיל ומבצע, מלאי, מק"ט, תמונה, תיאור, סטטוס, קטגוריות, תגיות',
			'פתוח לעריכה כאן'    => 'גלריה, תיאור קצר, קידום',
		);
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( ! empty( $s['sync_error'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( self::sync_error( (string) $s['sync_error'] ) ) . '</p></div>';
		}
		echo '<p class="description">' . esc_html( 'מוצרים באתר בלי מק"ט תואם ב-MasterPet לא משתנים. ההזמנות יחוברו בעדכון הבא של התוסף.' ) . '</p>';

		self::button( 'masterpet_sync', 'סנכרן עכשיו', 'button button-primary' );
		self::button( 'masterpet_relink', 'קשר שוב לפי מק"ט' );
		self::button( 'masterpet_check', 'בדיקת קשר' );
		self::button( 'masterpet_disconnect', 'נתק', 'button button-link-delete' );
		echo '</div>';
	}
}
