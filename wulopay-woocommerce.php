<?php
/**
 * Plugin Name:       WuloPay for WooCommerce
 * Plugin URI:        https://github.com/WuloPay/wulopay-woocommerce
 * Description:       Accept Visa and Mastercard payments with WuloPay. Shoppers pay on WuloPay's secure payment page (3-D Secure included) and orders are confirmed by signed webhooks.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Requires Plugins:  woocommerce
 * Author:            WuloPay
 * Author URI:        https://www.wulopay.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wulopay-woocommerce
 * WC requires at least: 9.0
 * WC tested up to:     11.2
 *
 * @package WuloPay
 */

defined( 'ABSPATH' ) || exit;

define( 'WULOPAY_WC_VERSION', '1.0.0' );
define( 'WULOPAY_WC_FILE', __FILE__ );
define( 'WULOPAY_WC_DIR', plugin_dir_path( __FILE__ ) );

// WooCommerce features this plugin supports: High-Performance Order Storage and the block checkout.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}
		require_once WULOPAY_WC_DIR . 'lib/wulopay-php/autoload.php';
		require_once WULOPAY_WC_DIR . 'includes/class-wulopay-token-cache.php';
		require_once WULOPAY_WC_DIR . 'includes/class-wulopay-gateway.php';
		require_once WULOPAY_WC_DIR . 'includes/class-wulopay-webhook.php';

		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = 'WuloPay_Gateway';
				return $gateways;
			}
		);
		WuloPay_Webhook::register();
		add_action( 'template_redirect', array( 'WuloPay_Gateway', 'handle_return' ) );
	}
);

add_action(
	'woocommerce_blocks_loaded',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		require_once WULOPAY_WC_DIR . 'includes/class-wulopay-blocks.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new WuloPay_Blocks() );
			}
		);
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wulopay' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wulopay-woocommerce' ) . '</a>' );
		return $links;
	}
);
