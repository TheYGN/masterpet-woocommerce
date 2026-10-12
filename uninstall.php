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
wp_clear_scheduled_hook( 'masterpet_wc_heartbeat' );
