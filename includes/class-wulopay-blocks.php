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

	/**
	 * Registered whenever the method is enabled, so the checkout block editor lists it. Whether a shopper can pay with
	 * it (currency, API key) is decided per checkout by canMakePayment() from the "ready" flag below.
	 */
	public function is_active() {
		return 'yes' === ( $this->settings['enabled'] ?? 'no' );
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

	/** The checkout block editor needs the same script to show the method. */
	public function get_payment_method_script_handles_for_admin() {
		return $this->get_payment_method_script_handles();
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
			'logo'        => (string) apply_filters( 'wulopay_wc_checkout_logo', WuloPay_Gateway::logo_url() ),
			'acceptMarks' => WuloPay_Gateway::accept_marks(),
			'acceptLabel' => __( 'We accept', 'wulopay-woocommerce' ),
			'ready'       => $this->gateway_ready(),
		);
	}

	/** Same rules as the classic checkout: WuloPay_Gateway::is_available(). */
	private function gateway_ready(): bool {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways['wulopay'] ) && $gateways['wulopay']->is_available();
	}
}
