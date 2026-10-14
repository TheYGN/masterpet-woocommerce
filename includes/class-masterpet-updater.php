<?php
/**
 * Updates from GitHub releases (PRD #13.1 D-34, D-35).
 *
 * Every merge that raises the version publishes a pre-release on GitHub
 * (.github/workflows/release.yml). Only sites marked "test site" here get
 * pre-releases; after a few days the release is promoted
 * (.github/workflows/promote.yml) and every other site gets it.
 *
 * WordPress asks this class through the plugin's "Update URI" header
 * (update_plugins_github.com), so the usual "update available" notice, the
 * update button and the automatic updates all work as for any plugin.
 * Packages come only from this repository's release assets. The repository
 * is public, so no token is stored on the site.
 *
 * @package MasterPet
 */

defined( 'ABSPATH' ) || defined( 'MASTERPET_CLI_TEST' ) || exit;

final class MasterPet_Updater {

	const REPO      = 'TheYGN/masterpet-woocommerce';
	const SLUG      = 'masterpet-woocommerce';
	const ASSET     = 'masterpet-woocommerce.zip';
	const TEST_SITE = 'masterpet_wc_test_site';
	const CACHE     = 'masterpet_wc_release_';     // + 'test' | 'stable'
	const AUTO_ONCE = 'masterpet_wc_auto_update_on';

	public static function init(): void {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'offer' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ) );
		add_action( 'admin_init', array( __CLASS__, 'auto_update_once' ) );
		add_action( 'admin_post_masterpet_updates', array( __CLASS__, 'handle_save' ) );
	}

	public static function is_test_site(): bool {
		return (bool) get_option( self::TEST_SITE, false );
	}

	private static function basename(): string {
		return plugin_basename( MASTERPET_WC_FILE );
	}

	// ── the release to offer ──────────────────────────────────────────────

	/**
	 * The newest release this site may install, from GitHub's releases list.
	 * Pure: no WordPress calls, so tests/updater-pick.php runs it directly.
	 *
	 * @param array $releases  GitHub's /releases response, decoded.
	 * @param bool  $test_site Pre-releases count only on a test site.
	 * @return array{version:string,package:string,url:string,notes:string,date:string,prerelease:bool}|null
	 */
	public static function pick( array $releases, bool $test_site ): ?array {
		$prefix = 'https://github.com/' . self::REPO . '/releases/download/';
		$best   = null;
		foreach ( $releases as $r ) {
			if ( ! is_array( $r ) || ! empty( $r['draft'] ) ) {
				continue;
			}
			if ( ! empty( $r['prerelease'] ) && ! $test_site ) {
				continue;
			}
			if ( ! preg_match( '/^v?(\d+\.\d+\.\d+)$/', (string) ( $r['tag_name'] ?? '' ), $m ) ) {
				continue;
			}
			$package = null;
			foreach ( (array) ( $r['assets'] ?? array() ) as $a ) {
				$url = (string) ( $a['browser_download_url'] ?? '' );
				if ( self::ASSET === ( $a['name'] ?? '' ) && 0 === strpos( $url, $prefix ) ) {
					$package = $url;
					break;
				}
			}
			if ( null === $package || ( $best && version_compare( $m[1], $best['version'], '<=' ) ) ) {
				continue;
			}
			$best = array(
				'version'    => $m[1],
				'package'    => $package,
				'url'        => (string) ( $r['html_url'] ?? 'https://github.com/' . self::REPO . '/releases' ),
				'notes'      => (string) ( $r['body'] ?? '' ),
				'date'       => (string) ( $r['published_at'] ?? '' ),
				'prerelease' => ! empty( $r['prerelease'] ),
			);
		}
		return $best;
	}

	/**
	 * The release for this site's channel, cached for 6 hours (1 hour after a
	 * failure). GitHub allows 60 unauthenticated requests per hour per address,
	 * so a shared host must not ask on every page.
	 */
	public static function release(): ?array {
		$key    = self::CACHE . ( self::is_test_site() ? 'test' : 'stable' );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached['release'] ?? null;
		}
		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases?per_page=20',
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'MasterPet-WooCommerce/' . MASTERPET_WC_VERSION,
				),
			)
		);
		$list = is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res )
			? null
			: json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $list ) ) {
			set_transient( $key, array( 'release' => null ), HOUR_IN_SECONDS );
			return null;
		}
		$release = self::pick( $list, self::is_test_site() );
		set_transient( $key, array( 'release' => $release ), 6 * HOUR_IN_SECONDS );
		return $release;
	}

	public static function forget(): void {
		delete_transient( self::CACHE . 'test' );
		delete_transient( self::CACHE . 'stable' );
	}

	// ── WordPress hooks ───────────────────────────────────────────────────

	/**
	 * WordPress compares the version itself: newer goes to "update available",
	 * otherwise the plugin shows as up to date (and keeps its auto-update toggle).
	 *
	 * @param array|false $update
	 * @param array       $plugin_data
	 * @param string      $plugin_file
	 * @return array|false
	 */
	public static function offer( $update, $plugin_data, $plugin_file ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		$r = self::release();
		if ( null === $r ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $r['version'],
			'new_version'  => $r['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $r['package'],
			'requires'     => '6.4',
			'requires_php' => '7.4',
		);
	}

	/** The "view details" window on the plugins screen: the release notes. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$r = self::release();
		if ( null === $r ) {
			return $result;
		}
		return (object) array(
			'name'          => 'MasterPet for WooCommerce',
			'slug'          => self::SLUG,
			'version'       => $r['version'],
			'author'        => '<a href="https://www.masterpet.app">MasterPet</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.4',
			'requires_php'  => '7.4',
			'last_updated'  => $r['date'],
			'download_link' => $r['package'],
			'sections'      => array(
				'changelog' => wpautop( esc_html( '' !== $r['notes'] ? $r['notes'] : 'גרסה ' . $r['version'] ) ),
			),
		);
	}

	/**
	 * The package unpacks to masterpet-woocommerce/. If this site keeps the
	 * plugin in a folder with another name, the update goes into that folder,
	 * so WordPress does not end up with a second, inactive copy.
	 */
	public static function folder( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( is_wp_error( $source ) || self::basename() !== ( $hook_extra['plugin'] ?? '' ) ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . dirname( self::basename() );
		if ( untrailingslashit( $source ) === $want ) {
			return $source;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem || ! $wp_filesystem->move( untrailingslashit( $source ), $want, true ) ) {
			return new WP_Error( 'masterpet_folder', 'לא הצלחנו להכין את תיקיית העדכון.' );
		}
		return trailingslashit( $want );
	}

	/**
	 * Automatic updates are on by default, once: the owner can still turn them
	 * off on the plugins screen and it stays off.
	 */
	public static function auto_update_once(): void {
		if ( get_option( self::AUTO_ONCE ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		update_option( self::AUTO_ONCE, 1, false );
		if ( ! function_exists( 'wp_is_auto_update_enabled_for_type' ) || ! wp_is_auto_update_enabled_for_type( 'plugin' ) ) {
			return;
		}
		$list = (array) get_site_option( 'auto_update_plugins', array() );
		if ( ! in_array( self::basename(), $list, true ) ) {
			$list[] = self::basename();
			update_site_option( 'auto_update_plugins', $list );
		}
	}

	// ── the "updates" box on the MasterPet screen ─────────────────────────

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'אין לך הרשאה לפעולה הזו.', 'masterpet-woocommerce' ), 403 );
		}
		check_admin_referer( 'masterpet_updates' );
		update_option( self::TEST_SITE, empty( $_POST['masterpet_test_site'] ) ? 0 : 1, false );
		self::forget();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$r    = self::release();
		$text = null !== $r && version_compare( $r['version'], MASTERPET_WC_VERSION, '>' )
			? sprintf( 'גרסה %s זמינה. אפשר לעדכן במסך התוספים, או לחכות לעדכון האוטומטי.', $r['version'] )
			: sprintf( 'התוסף מעודכן (גרסה %s).', MASTERPET_WC_VERSION );
		set_transient( 'masterpet_wc_notice_' . get_current_user_id(), array( 'text' => $text, 'type' => 'success' ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . MasterPet_Admin::SLUG ) );
		exit;
	}

	public static function render_box(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		echo '<h2>' . esc_html( 'עדכונים' ) . '</h2>';
		echo '<p>' . esc_html( sprintf( 'התוסף מתעדכן לבד לגרסאות חדשות. הגרסה המותקנת: %s', MASTERPET_WC_VERSION ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'masterpet_updates' );
		echo '<input type="hidden" name="action" value="masterpet_updates">';
		echo '<label><input type="checkbox" name="masterpet_test_site" value="1"' . checked( self::is_test_site(), true, false ) . '> ';
		echo esc_html( 'אתר בדיקה: מקבל גרסאות חדשות כמה ימים לפני כל האתרים' ) . '</label><p>';
		echo '<button type="submit" class="button">' . esc_html( 'שמור ובדוק עדכונים' ) . '</button></p></form>';
	}
}
