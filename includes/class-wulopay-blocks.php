<?php
/**
 * WuloPay in the WooCommerce block checkout.
 *
 * @package WuloPay
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WuloPay_Blocks extends AbstractPaymentMethodType {

	protected $name = 'wulopay';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_wulopay_settings', array() );
	}

	public function is_active() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways['wulopay'] ) && $gateways['wulopay']->is_available();
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'wulopay-blocks',
			plugins_url( 'assets/js/blocks.js', WULOPAY_WC_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			WULOPAY_WC_VERSION,
			true
		);
		return array( 'wulopay-blocks' );
	}

	/** Features the block checkout matches against the cart's needs (WooCommerce Subscriptions adds its own). */
	public function get_supported_features() {
		return array_merge( array( 'products', 'refunds' ), WuloPay_Subscriptions::active() ? WuloPay_Subscriptions::SUPPORTS : array() );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'supports'    => $this->get_supported_features(),
		);
	}
}
