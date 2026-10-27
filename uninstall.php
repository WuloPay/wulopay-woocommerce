<?php
/**
 * Removes the plugin's settings when it is deleted (orders and their notes are kept).
 *
 * @package WuloPay
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_wulopay_settings' );
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wulopay\\_token\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wulopay\\_token\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
