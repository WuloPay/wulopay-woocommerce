<?php
/**
 * Plugin Name:       WuloPay for WooCommerce
 * Plugin URI:        https://github.com/WuloPay/wulopay-woocommerce
 * Description:       Accept Visa and Mastercard payments with WuloPay. Shoppers pay on WuloPay's secure payment page (3-D Secure included) and orders are confirmed by signed webhooks.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.3
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

/**
 * The PHP release that fixed known critical flaws for the running branch, when this server's PHP is older.
 * PHP2Shell (CVE-2026-109618, CVE-2026-109619) was fixed in 8.3.36, 8.4.27 and 8.5.12 on 21 October 2026.
 */
function wulopay_wc_php_security_fix(): ?string {
	$fixed  = array(
		'8.3' => '8.3.36',
		'8.4' => '8.4.27',
		'8.5' => '8.5.12',
	);
	$branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
	return isset( $fixed[ $branch ] ) && version_compare( PHP_VERSION, $fixed[ $branch ], '<' ) ? $fixed[ $branch ] : null;
}

// Card payments run on this server: warn store managers when PHP lacks critical security fixes.
add_action(
	'admin_notices',
	static function () {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$fix    = wulopay_wc_php_security_fix();
		if ( null === $fix || ! current_user_can( 'manage_woocommerce' ) || ! $screen || ! in_array( $screen->id, array( 'plugins', 'woocommerce_page_wc-settings', 'dashboard' ), true ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'WuloPay: your server\'s PHP needs a security update.', 'wulopay-woocommerce' ),
			esc_html(
				sprintf(
					/* translators: 1: running PHP version, 2: first fixed PHP version */
					__( 'It runs PHP %1$s, which has known critical security flaws that let attackers take over a website. Ask your host to update PHP to %2$s or newer. (Some hosts patch PHP without changing the version number; if yours confirms that, you can ignore this.)', 'wulopay-woocommerce' ),
					PHP_VERSION,
					$fix
				)
			)
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
