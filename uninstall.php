<?php
/**
 * Removes the plugin's stored connection (including the encrypted secret).
 * MasterPet keeps its side until the owner disconnects there, or the next
 * signed request is refused.
 *
 * @package MasterPet
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'masterpet_wc_connection' );
delete_option( 'masterpet_wc_test_site' );
delete_option( 'masterpet_wc_auto_update_on' );
delete_option( 'masterpet_wc_self_update_last' );
wp_clear_scheduled_hook( 'masterpet_wc_self_update' );
delete_transient( 'masterpet_wc_release_test' );
delete_transient( 'masterpet_wc_release_stable' );
wp_clear_scheduled_hook( 'masterpet_wc_heartbeat' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'masterpet_wc_sync', array(), 'masterpet' );
}
// Product meta (_masterpet_*) stays: without a connection it locks nothing.
