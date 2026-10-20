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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_masterpet_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_masterpet_check', array( __CLASS__, 'handle_check' ) );
		add_action( 'admin_post_masterpet_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_masterpet_sync', array( __CLASS__, 'handle_sync' ) );
		add_action( 'admin_post_masterpet_relink', array( __CLASS__, 'handle_relink' ) );
		add_action( 'admin_post_masterpet_fingerprint', array( __CLASS__, 'handle_fingerprint' ) );
	}

	public static function menu(): void {
		add_menu_page( 'MasterPet', 'MasterPet', 'manage_woocommerce', self::SLUG, array( __CLASS__, 'render' ), 'dashicons-store', 56 );
	}

	/** MasterPet's look (Heebo + assets/admin.css), on this screen only. */
	public static function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'masterpet-heebo', 'https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;700&display=swap', array(), null );
		wp_enqueue_style( 'masterpet-admin', plugins_url( 'assets/admin.css', MASTERPET_WC_FILE ), array(), MASTERPET_WC_VERSION );
	}

	/** Material Symbols Outlined paths (Apache-2.0), inline so nothing else loads. */
	const ICONS = array(
		'pets' => 'M169.86-485Q132-485 106-511.14t-26-64Q80-613 106.14-639t64-26Q208-665 234-638.86t26 64Q260-537 233.86-511t-64 26ZM291-681.14q-26-26.14-26-64T291.14-809q26.14-26 64-26T419-808.86q26 26.14 26 64T418.86-681q-26.14 26-64 26T291-681.14Zm250 0q-26-26.14-26-64T541.14-809q26.14-26 64-26T669-808.86q26 26.14 26 64T668.86-681q-26.14 26-64 26T541-681.14ZM789.86-485Q752-485 726-511.14t-26-64Q700-613 726.14-639t64-26Q828-665 854-638.86t26 64Q880-537 853.86-511t-64 26ZM266-75q-42 0-69-31.53-27-31.52-27-74.47 0-42 25.5-74.5T250-318q22-22 41-46.5t36-50.5q29-44 65-82t88-38q52 0 88.5 38t65.5 83q17 26 35.5 50t40.5 46q29 30 54.5 62.5T790-181q0 42.95-27 74.47Q736-75 694-75q-54 0-107-9t-107-9q-54 0-107 9t-107 9Z',
		'storefront' => 'M840-519v339q0 24-18 42t-42 18H179q-24 0-42-18t-18-42v-339q-28-24-37-59t2-70l43-135q8-27 28-42t46-15h553q28 0 49 15.5t29 41.5l44 135q11 35 1.5 70T840-519Zm-270-31q29 0 49-19t16-46l-25-165H510v165q0 26 17 45.5t43 19.5Zm-187 0q28 0 47.5-19t19.5-46v-165H350l-25 165q-4 26 14 45.5t44 19.5Zm-182 0q24 0 41.5-16.5T263-607l26-173H189l-46 146q-10 31 8 57.5t50 26.5Zm557 0q32 0 50.5-26t8.5-58l-46-146H671l26 173q3 24 20.5 40.5T758-550ZM179-180h601v-311q1 1-6.5 1H758q-25 0-47.5-10.5T666-533q-16 20-40 31.5T573-490q-30 0-51.5-8.5T480-527q-15 18-38 27.5t-52 9.5q-31 0-55-11t-41-32q-24 21-47 32t-46 11h-13.5q-6.5 0-8.5-1v311Zm601 0H179h601Z',
		'network_check' => 'M63-537 0-600q95-97 219.5-148.5T480-800q29 0 57.5 2.5T595-791l-42 85q-18-3-36.5-3.5T480-710q-118 0-225 45.5T63-537Zm124 119-64-64q81-68 180-101t205-28l-42 90q-77-2-148 26t-131 77Zm257 254q-29-11-40.5-41t2.5-59l237-490q3-8 10.5-10.5t15.5-.5q8 2 13 9t3 15L547-206q-8 32-39.5 43.5T444-164Zm330-253q-22-19-46-34.5T678-478l23-88q38 15 72 36t64 48l-63 65Zm123-120q-38-37-81-67.5T724-656l22-90q60 26 113.5 63T960-600l-63 63Z',
		'sync' => 'M167-160v-60h130l-15-12q-64-51-93-111t-29-134q0-106 62.5-190.5T387-784v62q-75 29-121 96.5T220-477q0 63 23.5 109.5T307-287l30 21v-124h60v230H167Zm407-15v-63q76-29 121-96.5T740-483q0-48-23.5-97.5T655-668l-29-26v124h-60v-230h230v60H665l15 14q60 56 90 120t30 123q0 106-62 191T574-175Z',
		'link' => 'M450-280H280q-83 0-141.5-58.5T80-480q0-83 58.5-141.5T280-680h170v60H280q-58.33 0-99.17 40.76-40.83 40.77-40.83 99Q140-422 180.83-381q40.84 41 99.17 41h170v60ZM325-450v-60h310v60H325Zm185 170v-60h170q58.33 0 99.17-40.76 40.83-40.77 40.83-99Q820-538 779.17-579q-40.84-41-99.17-41H510v-60h170q83 0 141.5 58.5T880-480q0 83-58.5 141.5T680-280H510Z',
		'link_off' => 'm750-291-49-49q51-10 85-48t34-89q0-58-41-99t-99-41H525v-60h155q83 0 141.5 58.5T880-477q0 62-36 112t-94 74ZM594-447l-60-60h101v60h-41ZM814-56 63-807l43-43L857-99l-43 43ZM450-280H280q-83 0-141.5-58.5T80-480q0-72 44.5-127T238-676l56 56h-14q-58 0-99 41t-41 99q0 58 41 99t99 41h170v60ZM325-450v-60h79l60 60H325Z',
		'lock' => 'M220-80q-24.75 0-42.37-17.63Q160-115.25 160-140v-434q0-24.75 17.63-42.38Q195.25-634 220-634h70v-96q0-78.85 55.61-134.42Q401.21-920 480.11-920q78.89 0 134.39 55.58Q670-808.85 670-730v96h70q24.75 0 42.38 17.62Q800-598.75 800-574v434q0 24.75-17.62 42.37Q764.75-80 740-80H220Zm0-60h520v-434H220v434Zm314.5-162.03Q557-324.06 557-355q0-30-22.67-54.5t-54.5-24.5q-31.83 0-54.33 24.5t-22.5 55q0 30.5 22.67 52.5t54.5 22q31.83 0 54.33-22.03ZM350-634h260v-96q0-54.17-37.88-92.08-37.88-37.92-92-37.92T388-822.08q-38 37.91-38 92.08v96ZM220-140v-434 434Z',
		'edit_note' => 'M160-410v-60h300v60H160Zm0-165v-60h470v60H160Zm0-165v-60h470v60H160Zm360 580v-123l221-220q9-9 20-13t22-4q12 0 23 4.5t20 13.5l37 37q9 9 13 20t4 22q0 11-4.5 22.5T862.09-380L643-160H520Zm300-263-37-37 37 37ZM580-220h38l121-122-18-19-19-18-122 121v38Zm141-141-19-18 37 37-18-19Z',
		'update' => 'M483-120q-75 0-141-28.5T226.5-226q-49.5-49-78-115T120-482q0-75 28.5-140t78-113.5Q276-784 342-812t141-28q80 0 151.5 35T758-709v-106h60v208H609v-60h105q-44-51-103.5-82T483-780q-125 0-214 85.5T180-485q0 127 88 216t215 89q125 0 211-88t86-213h60q0 150-104 255.5T483-120Zm122-197L451-469v-214h60v189l137 134-43 43Z',
		'science' => 'M172-120q-41.78 0-59.39-39T124-230l248-280v-270h-52q-12.75 0-21.37-8.68-8.63-8.67-8.63-21.5 0-12.82 8.63-21.32 8.62-8.5 21.37-8.5h320q12.75 0 21.38 8.68 8.62 8.67 8.62 21.5 0 12.82-8.62 21.32-8.63 8.5-21.38 8.5h-52v270l248 280q29 32 11.39 71T788-120H172Zm-12-60h640L528-488v-292h-96v292L160-180Zm318-300Z',
		'warning' => 'm40-120 440-760 440 760H40Zm104-60h672L480-760 144-180Zm361.5-65.68q8.5-8.67 8.5-21.5 0-12.82-8.68-21.32-8.67-8.5-21.5-8.5-12.82 0-21.32 8.68-8.5 8.67-8.5 21.5 0 12.82 8.68 21.32 8.67 8.5 21.5 8.5 12.82 0 21.32-8.68ZM454-348h60v-224h-60v224Zm26-122Z',
		'error' => 'M503.5-289.48q9.5-9.48 9.5-23.5t-9.48-23.52q-9.48-9.5-23.5-9.5t-23.52 9.48q-9.5 9.48-9.5 23.5t9.48 23.52q9.48 9.5 23.5 9.5t23.52-9.48ZM453-433h60v-253h-60v253Zm27.27 353q-82.74 0-155.5-31.5Q252-143 197.5-197.5t-86-127.34Q80-397.68 80-480.5t31.5-155.66Q143-709 197.5-763t127.34-85.5Q397.68-880 480.5-880t155.66 31.5Q709-817 763-763t85.5 127Q880-563 880-480.27q0 82.74-31.5 155.5Q817-252 763-197.68q-54 54.31-127 86Q563-80 480.27-80Zm.23-60Q622-140 721-239.5t99-241Q820-622 721.19-721T480-820q-141 0-240.5 98.81T140-480q0 141 99.5 240.5t241 99.5Zm-.5-340Z',
		'check_circle' => 'm421-298 283-283-46-45-237 237-120-120-45 45 165 166Zm59 218q-82 0-155-31.5t-127.5-86Q143-252 111.5-325T80-480q0-83 31.5-156t86-127Q252-817 325-848.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 82-31.5 155T763-197.5q-54 54.5-127 86T480-80Zm0-60q142 0 241-99.5T820-480q0-142-99-241t-241-99q-141 0-240.5 99T140-480q0 141 99.5 240.5T480-140Zm0-340Z',
		'info' => 'M453-280h60v-240h-60v240Zm50.5-323.2q9.5-9.2 9.5-22.8 0-14.45-9.48-24.22-9.48-9.78-23.5-9.78t-23.52 9.78Q447-640.45 447-626q0 13.6 9.48 22.8 9.48 9.2 23.5 9.2t23.52-9.2ZM480.27-80q-82.74 0-155.5-31.5Q252-143 197.5-197.5t-86-127.34Q80-397.68 80-480.5t31.5-155.66Q143-709 197.5-763t127.34-85.5Q397.68-880 480.5-880t155.66 31.5Q709-817 763-763t85.5 127Q880-563 880-480.27q0 82.74-31.5 155.5Q817-252 763-197.68q-54 54.31-127 86Q563-80 480.27-80Zm.23-60Q622-140 721-239.5t99-241Q820-622 721.19-721T480-820q-141 0-240.5 98.81T140-480q0 141 99.5 240.5t241 99.5Zm-.5-340Z',
		'key' => 'M232-432q-20-20-20-48t20-48q20-20 48-20t48 20q20 20 20 48t-20 48q-20 20-48 20t-48-20Zm48 192q-100 0-170-70T40-480q0-100 70-170t170-70q72 0 126 34t85 103h356l113 113-167 153-88-64-88 64-75-60h-51q-25 60-78.5 98.5T280-240Zm0-60q58 0 107-38.5t63-98.5h114l54 45 88-63 82 62 85-79-51-51H450q-12-56-60-96.5T280-660q-75 0-127.5 52.5T100-480q0 75 52.5 127.5T280-300Z',
		'cloud_sync' => 'M167-160v-60h130q-64-48-100.5-108.5T160-477q0-100 59-186.5T387-784v62q-71 26-119 93t-48 152q0 64 27.5 117.5T337-266v-124h60v230H167Zm433 0q-50 0-85-35t-35-85q0-48 33-83t81-37q17-36 50.5-58t75.5-22q53 0 91.5 34.5T858-360h2q42 0 71 30t29 69q0 42-29 71.5T860-160H600Zm136-360q-11-51-38-93.5T626-694v124h-60v-230h230v60H665q49 41 86.5 100.5T797-520h-61ZM600-220h260q17 0 28.5-11.5T900-260q0-17-11.5-28.5T860-300h-50v-30q0-38-26-64t-64-26q-38 0-64 24.5T630-340h-30q-25 0-42.5 17.5T540-280q0 25 17.5 42.5T600-220Zm120-100Z',
		'inventory_2' => 'M180-80q-24.75 0-42.37-17.63Q120-115.25 120-140v-483q-17-6-28.5-21.39T80-680v-140q0-24.75 17.63-42.38Q115.25-880 140-880h680q24.75 0 42.38 17.62Q880-844.75 880-820v140q0 20.22-11.5 35.61T840-623v483q0 24.75-17.62 42.37Q804.75-80 780-80H180Zm0-540v480h600v-480H180Zm-40-60h680v-140H140v140Zm220 260h240v-60H360v60Zm120 40Z',
	);

	/** An icon from ICONS as inline SVG; decorative, hidden from screen readers. */
	public static function icon( string $name ): string {
		$d = self::ICONS[ $name ] ?? '';
		return '<svg class="mp-ic" viewBox="0 -960 960 960" aria-hidden="true" focusable="false"><path d="' . esc_attr( $d ) . '"/></svg>';
	}

	/** One of DESIGN-SYSTEM §10's alert boxes. */
	public static function alert( string $type, string $text ): void {
		$icons = array( 'success' => 'check_circle', 'warning' => 'warning', 'error' => 'error' );
		echo '<div class="mp-alert mp-alert--' . esc_attr( $type ) . '" role="' . ( 'success' === $type ? 'status' : 'alert' ) . '">' . self::icon( $icons[ $type ] ?? 'info' ) . '<p>' . esc_html( $text ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icon() is escaped.
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

	/** Runs the nightly check now (T-291): every linked product that differs from MasterPet is sent again. */
	public static function handle_fingerprint(): void {
		self::guard( 'masterpet_fingerprint' );
		if ( MasterPet_Fingerprint::run_now() ) {
			self::back( 'בדיקת ההתאמה התחילה. מוצר שלא תואם ל-MasterPet יתעדכן בדקות הקרובות.', 'success' );
		}
		self::back( 'אי אפשר להריץ את הבדיקה עכשיו. בדוק שהאתר מחובר ל-MasterPet.', 'error' );
	}

	/** The site's clock runs on Israel time now (a named zone or a +2/+3 offset that matches it). */
	private static function israel_time(): bool {
		$tz = wp_timezone();
		if ( in_array( $tz->getName(), array( 'Asia/Jerusalem', 'Asia/Tel_Aviv', 'Israel' ), true ) ) {
			return true;
		}
		$now = new DateTimeImmutable( 'now' );
		return $tz->getOffset( $now ) === ( new DateTimeZone( 'Asia/Jerusalem' ) )->getOffset( $now );
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

	private static function button( string $action, string $label, string $kind, string $icon, string $extra = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $extra ? ' class="' . esc_attr( $extra ) . '"' : '' ) . '>';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<button type="submit" class="mp-btn mp-btn--' . esc_attr( $kind ) . '">' . self::icon( $icon ) . esc_html( $label ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icon() is escaped.
	}

	/** One tile of the status row. */
	private static function stat( string $icon, string $label, string $value, string $meta = '', bool $warn = false ): void {
		echo '<div class="mp-stat"><div class="mp-stat-label">' . self::icon( $icon ) . esc_html( $label ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icon() is escaped.
		echo '<div class="mp-stat-value">' . esc_html( $value ) . '</div>';
		if ( '' !== $meta ) {
			echo '<div class="mp-stat-meta' . ( $warn ? ' mp-stat-meta--warn' : '' ) . '">' . esc_html( $meta ) . '</div>';
		}
		echo '</div>';
	}

	/** One "who manages what" box. */
	private static function fields( string $icon, string $title, string $desc, array $items, string $kind ): void {
		echo '<div class="mp-field-group"><div class="mp-field-head">' . self::icon( $icon ) . esc_html( $title ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icon() is escaped.
		echo '<div class="mp-field-desc">' . esc_html( $desc ) . '</div><div class="mp-tags">';
		foreach ( $items as $item ) {
			echo '<span class="mp-tag mp-tag--' . esc_attr( $kind ) . '">' . esc_html( $item ) . '</span>';
		}
		echo '</div></div>';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s         = MasterPet_Settings::get();
		$connected = MasterPet_Settings::is_connected();
		$notice    = get_transient( 'masterpet_wc_notice_' . get_current_user_id() );
		delete_transient( 'masterpet_wc_notice_' . get_current_user_id() );

		echo '<div class="wrap mp" dir="rtl">';
		echo '<div class="mp-head"><div class="mp-logo">' . self::icon( 'pets' ) . '</div><div class="mp-head-text"><div class="mp-head-row">'; // phpcs:ignore WordPress.Security.EscapeOutput -- icon() is escaped.
		echo '<h1 class="mp-title">MasterPet</h1>';
		echo $connected ? '<span class="mp-chip mp-chip--ok">' . esc_html( 'מחובר' ) . '</span>' : '<span class="mp-chip mp-chip--off">' . esc_html( 'לא מחובר' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped.
		echo '</div><div class="mp-sub">' . esc_html( 'המוצרים מגיעים מ-MasterPet וההזמנות מנוהלות שם' ) . '</div></div></div>';
		// WordPress moves other plugins' notices to right after this line, not into the header.
		echo '<hr class="wp-header-end">';

		if ( is_array( $notice ) ) {
			self::alert( 'success' === $notice['type'] ? 'success' : 'error', (string) $notice['text'] );
		}

		if ( ! $connected ) {
			if ( 'disconnected' === ( $s['status'] ?? '' ) ) {
				self::alert( 'warning', 'החיבור נותק ב-MasterPet. כדי לחבר שוב, צור קוד חדש ב-MasterPet.' );
			}
			echo '<div class="mp-card"><div class="mp-card-head">' . self::icon( 'key' ) . '<h2 class="mp-card-title">' . esc_html( 'חיבור ל-MasterPet' ) . '</h2></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icon() is escaped.
			echo '<p>' . esc_html( 'הדבק את קוד החיבור שקיבלת ב-MasterPet, במסך הגדרות ← אינטגרציות.' ) . '</p>';
			echo '<form class="mp-connect" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'masterpet_connect' );
			echo '<input type="hidden" name="action" value="masterpet_connect">';
			echo '<input type="text" name="masterpet_code" class="mp-input" dir="ltr" placeholder="T-XXXX-XXXX-XXXX" autocomplete="off" aria-label="' . esc_attr( 'קוד חיבור' ) . '" required>';
			echo '<button type="submit" class="mp-btn mp-btn--filled mp-btn--lg">' . self::icon( 'link' ) . esc_html( 'חבר' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icon() is escaped.
			echo '</form>';
			echo '<ol class="mp-steps"><li>' . esc_html( 'ב-MasterPet: הגדרות ← אינטגרציות ← אתר WooCommerce, בוחרים סניף ויוצרים קוד חיבור' ) . '</li>';
			echo '<li>' . esc_html( 'מדביקים את הקוד כאן ולוחצים "חבר". הקוד תקף לרבע שעה ולפעם אחת' ) . '</li>';
			echo '<li>' . esc_html( 'MasterPet מקשר את מוצרי האתר למוצרים שלו לפי מק"ט' ) . '</li></ol>';
			echo '</div>';
			MasterPet_Updater::render_box();
			echo '</div>';
			return;
		}

		if ( empty( $s['first_link_done'] ) ) {
			$link_value = empty( $s['sync_error'] ) ? 'רץ עכשיו' : 'נעצר';
			$link_meta  = empty( $s['sync_error'] ) ? '' : 'ננסה שוב אוטומטית';
			$link_warn  = ! empty( $s['sync_error'] );
		} else {
			$unlinked   = (int) ( $s['link_result']['unlinked'] ?? 0 );
			$link_value = sprintf( '%d מקושרים', (int) ( $s['link_result']['linked'] ?? 0 ) + (int) ( $s['link_result']['already'] ?? 0 ) );
			$link_meta  = sprintf( '%d לא מקושרים', $unlinked );
			$link_warn  = $unlinked > 0;
		}

		echo '<div class="mp-card"><div class="mp-card-head">' . self::icon( 'cloud_sync' ) . '<h2 class="mp-card-title">' . esc_html( 'מצב החיבור' ) . '</h2></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icon() is escaped.
		echo '<div class="mp-stats">';
		self::stat( 'storefront', 'סניף', (string) ( $s['branch_name'] ?? '-' ) );
		self::stat( 'network_check', 'בדיקת קשר אחרונה', empty( $s['last_check'] ) ? '-' : self::since( (int) $s['last_check'] ) );
		self::stat( 'sync', 'עדכון מוצרים אחרון', empty( $s['sync_last'] ) ? 'עוד לא' : self::since( (int) $s['sync_last'] ) );
		self::stat( 'link', 'קישור לפי מק"ט', $link_value, $link_meta, $link_warn );
		echo '</div>';
		if ( ! empty( $s['sync_error'] ) ) {
			self::alert( 'warning', self::sync_error( (string) $s['sync_error'] ) );
		}
		// MasterPet's sale dates are Israel days. With the site on another time zone
		// (WordPress starts on UTC) the product screen shows them a day early.
		if ( ! self::israel_time() ) {
			self::alert( 'warning', 'אזור הזמן של האתר אינו ישראל, ולכן תאריכי המבצעים והשעות של ההזמנות מוצגים כאן ביום או בשעות הלא נכונים. כדאי לשנות בהגדרות ← כללי ← אזור זמן ל"ירושלים".' );
		}
		echo '<div class="mp-actions">';
		self::button( 'masterpet_sync', 'סנכרן עכשיו', 'filled', 'sync' );
		self::button( 'masterpet_relink', 'קשר שוב לפי מק"ט', 'tonal', 'link' );
		self::button( 'masterpet_fingerprint', 'בדוק התאמה עכשיו', 'tonal', 'science' );
		self::button( 'masterpet_check', 'בדיקת קשר', 'outlined', 'network_check' );
		self::button( 'masterpet_disconnect', 'נתק', 'danger', 'link_off', 'mp-push' );
		echo '</div></div>';

		echo '<div class="mp-card"><div class="mp-card-head">' . self::icon( 'inventory_2' ) . '<h2 class="mp-card-title">' . esc_html( 'מה מתעדכן מאיפה' ) . '</h2></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icon() is escaped.
		echo '<div class="mp-fields">';
		self::fields( 'lock', 'מנוהל מ-MasterPet', 'נעול לעריכה כאן. משנים ב-MasterPet והאתר מתעדכן לבד.', array( 'שם', 'מחיר רגיל ומבצע', 'מלאי', 'מק"ט', 'תמונה', 'תיאור', 'סטטוס', 'קטגוריות', 'תגיות' ), 'managed' );
		self::fields( 'edit_note', 'פתוח לעריכה כאן', 'שינויים כאן נשמרים, MasterPet לא נוגע בהם.', array( 'גלריה', 'תיאור קצר', 'קידום' ), 'open' );
		echo '</div>';
		echo '<p class="mp-hint">' . self::icon( 'info' ) . esc_html( 'מוצרים באתר בלי מק"ט תואם ב-MasterPet לא משתנים. ההזמנות יחוברו בעדכון הבא של התוסף.' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- icon() is escaped.
		echo '</div>';

		MasterPet_Updater::render_box();
		echo '</div>';
	}
}
