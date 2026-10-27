<?php
/**
 * WuloPay payment gateway: redirects the shopper to WuloPay's secure payment page.
 *
 * @package WuloPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Flow: process_payment() creates a WuloPay checkout session for the order and redirects the shopper to it. The
 * order is marked paid by the signed checkout.session.completed webhook (WuloPay_Webhook) or, when the shopper
 * comes back, by asking WuloPay for the session's status (handle_return). The redirect alone is never trusted.
 */
class WuloPay_Gateway extends WC_Payment_Gateway {

	public const ID              = 'wulopay';
	public const META_SESSION_ID = '_wulopay_session_id';
	public const META_ENV        = '_wulopay_environment';
	public const META_EVENTS     = '_wulopay_events';

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'WuloPay', 'wulopay-woocommerce' );
		$this->method_description = __( 'Card payments (Visa, Mastercard) on WuloPay\'s secure payment page, with 3-D Secure. Card details never touch your site.', 'wulopay-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$webhook_url       = rest_url( WuloPay_Webhook::NAMESPACE . WuloPay_Webhook::ROUTE );
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable', 'wulopay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Accept card payments with WuloPay', 'wulopay-woocommerce' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'   => __( 'Title', 'wulopay-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'Card (Visa, Mastercard)', 'wulopay-woocommerce' ),
			),
			'description'    => array(
				'title'   => __( 'Description', 'wulopay-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'You will pay securely on WuloPay\'s payment page.', 'wulopay-woocommerce' ),
			),
			'environment'    => array(
				'title'   => __( 'Environment', 'wulopay-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					'sandbox' => __( 'Sandbox (test cards, no real money)', 'wulopay-woocommerce' ),
					'live'    => __( 'Live', 'wulopay-woocommerce' ),
				),
				'default' => 'sandbox',
			),
			'sandbox_key_id' => array(
				'title'       => __( 'Sandbox API key id', 'wulopay-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'From the WuloPay merchant portal: Product Configuration, Hosted Checkout, Enable.', 'wulopay-woocommerce' ),
				'placeholder' => 'wk_…',
			),
			'sandbox_secret' => array(
				'title' => __( 'Sandbox API secret', 'wulopay-woocommerce' ),
				'type'  => 'password',
			),
			'live_key_id'    => array(
				'title'       => __( 'Live API key id', 'wulopay-woocommerce' ),
				'type'        => 'text',
				'placeholder' => 'wk_…',
			),
			'live_secret'    => array(
				'title' => __( 'Live API secret', 'wulopay-woocommerce' ),
				'type'  => 'password',
			),
			'live_base_url'  => array(
				'title'       => __( 'Live API URL', 'wulopay-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'WuloPay gives you this address when your account goes live.', 'wulopay-woocommerce' ),
				'placeholder' => 'https://…/api/v1',
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook signing secret', 'wulopay-woocommerce' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: webhook URL */
					__( 'In the WuloPay merchant portal (Product Configuration, Webhooks) add the endpoint %s and paste its signing secret here.', 'wulopay-woocommerce' ),
					'<code>' . esc_html( $webhook_url ) . '</code>'
				),
				'placeholder' => 'whsec_…',
			),
			'debug'          => array(
				'title'   => __( 'Debug log', 'wulopay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Log WuloPay requests (WooCommerce, Status, Logs). Secrets are never logged.', 'wulopay-woocommerce' ),
				'default' => 'no',
			),
		);
	}

	/** Only for ZAR stores with credentials configured. */
	public function is_available() {
		if ( ! parent::is_available() || 'ZAR' !== get_woocommerce_currency() ) {
			return false;
		}
		return null !== self::client();
	}

	/** Settings screen: point out what is missing. */
	public function admin_options() {
		if ( 'ZAR' !== get_woocommerce_currency() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'WuloPay takes payments in South African rand (ZAR). Set the store currency to ZAR to offer it at checkout.', 'wulopay-woocommerce' ) . '</p></div>';
		}
		parent::admin_options();
	}

	public function process_payment( $order_id ) {
		$order  = wc_get_order( $order_id );
		$client = self::client();
		if ( ! $order || null === $client ) {
			wc_add_notice( __( 'Card payments are not available right now. Please choose another payment method.', 'wulopay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$name   = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$params = array(
			'amount'      => (float) $order->get_total(),
			'currency'    => $order->get_currency(),
			'reference'   => substr( (string) $order->get_order_number(), 0, 64 ),
			'description' => substr(
				sprintf(
					/* translators: 1: order number, 2: site name */
					__( 'Order %1$s from %2$s', 'wulopay-woocommerce' ),
					$order->get_order_number(),
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
				),
				0,
				120
			),
			'returnUrl'   => $this->get_return_url( $order ),
			'cancelUrl'   => wc_get_checkout_url(),
			'metadata'    => array(
				'wc_order_id'  => (string) $order->get_id(),
				'wc_order_key' => $order->get_order_key(),
				'site'         => substr( home_url(), 0, 500 ),
			),
		);
		if ( $order->get_billing_email() ) {
			$params['customer'] = array_filter(
				array(
					'email' => $order->get_billing_email(),
					'name'  => '' !== $name ? substr( $name, 0, 120 ) : null,
				)
			);
		}

		try {
			$session = $client->checkoutSessions->create( $params, 'wc-' . $order->get_id() . '-' . wp_generate_uuid4() ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- library API.
		} catch ( \WuloPay\Exception\WuloPayException $e ) {
			self::log( 'Creating the checkout session for order ' . $order->get_id() . ' failed: ' . $e->getMessage(), 'error' );
			wc_add_notice( __( 'We could not start the card payment. Please try again.', 'wulopay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( self::META_SESSION_ID, $session['id'] );
		$order->update_meta_data( self::META_ENV, self::environment() );
		$order->add_order_note(
			sprintf(
				/* translators: %s: WuloPay checkout session id */
				__( 'WuloPay checkout started (session %s). Waiting for payment.', 'wulopay-woocommerce' ),
				$session['id']
			)
		);
		$order->update_status( 'pending' );
		$order->save();
		self::log( 'Order ' . $order->get_id() . ': checkout session ' . $session['id'] . ' created.' );

		return array(
			'result'   => 'success',
			'redirect' => $session['url'],
		);
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order      = wc_get_order( $order_id );
		$session_id = $order ? (string) $order->get_meta( self::META_SESSION_ID ) : '';
		$client     = self::client( $order ? (string) $order->get_meta( self::META_ENV ) : null );
		if ( '' === $session_id || null === $client ) {
			return new WP_Error( 'wulopay_refund', __( 'This order has no WuloPay payment to refund.', 'wulopay-woocommerce' ) );
		}
		try {
			$refund = $client->checkoutSessions->refund( $session_id, null !== $amount ? (float) $amount : null, '' !== (string) $reason ? substr( (string) $reason, 0, 200 ) : null ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- library API.
		} catch ( \WuloPay\Exception\WuloPayException $e ) {
			self::log( 'Refund of order ' . $order_id . ' failed: ' . $e->getMessage(), 'error' );
			return new WP_Error( 'wulopay_refund', $e->getMessage() );
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: refunded amount, 2: amount still refundable */
				__( 'WuloPay refunded %1$s (still refundable: %2$s).', 'wulopay-woocommerce' ),
				wc_price( $refund['refundedThisTime'], array( 'currency' => $order->get_currency() ) ),
				wc_price( $refund['remainingRefundable'], array( 'currency' => $order->get_currency() ) )
			)
		);
		return true;
	}

	/**
	 * The shopper is back on the order-received page with ?session_id=…: ask WuloPay (never trust the URL) and
	 * mark the order paid if the session is paid. Covers stores whose webhook isn't set up yet.
	 */
	public static function handle_return() {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() || empty( $_GET['session_id'] ) || empty( $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$order_id = absint( get_query_var( 'order-received' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		$key      = sanitize_text_field( wp_unslash( $_GET['key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$session  = sanitize_text_field( wp_unslash( $_GET['session_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) || self::ID !== $order->get_payment_method() || ! $order->needs_payment() ) {
			return;
		}
		if ( ! hash_equals( (string) $order->get_meta( self::META_SESSION_ID ), $session ) ) {
			return;
		}
		$client = self::client( (string) $order->get_meta( self::META_ENV ) );
		if ( null === $client ) {
			return;
		}
		try {
			self::apply_session( $order, $client->checkoutSessions->retrieve( $session ), 'return' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- library API.
		} catch ( \WuloPay\Exception\WuloPayException $e ) {
			self::log( 'Return check for order ' . $order_id . ' failed: ' . $e->getMessage(), 'warning' );
		}
	}

	/**
	 * Applies a WuloPay session (from the API or a verified webhook) to its order. Marks the order paid only when the
	 * session is paid and belongs to this order: same session id, amount and currency.
	 *
	 * @param WC_Order $order   The order.
	 * @param array    $session Checkout session.
	 * @param string   $source  'webhook' or 'return', for the order note.
	 */
	public static function apply_session( WC_Order $order, array $session, string $source ): void {
		if ( ( $session['status'] ?? '' ) !== 'paid' || ! $order->needs_payment() ) {
			return;
		}
		$expected_session = (string) $order->get_meta( self::META_SESSION_ID );
		$amount_ok        = abs( (float) ( $session['amount'] ?? -1 ) - (float) $order->get_total() ) < 0.005;
		$currency_ok      = strtoupper( (string) ( $session['currency'] ?? '' ) ) === strtoupper( $order->get_currency() );
		if ( '' === $expected_session || ! hash_equals( $expected_session, (string) ( $session['id'] ?? '' ) ) || ! $amount_ok || ! $currency_ok ) {
			self::log( 'Order ' . $order->get_id() . ': paid session ' . ( $session['id'] ?? '?' ) . ' does not match the order; not marked paid.', 'error' );
			$order->add_order_note( __( 'WuloPay reported a payment that does not match this order (session, amount or currency). The order was not marked paid; please check it in the WuloPay merchant portal.', 'wulopay-woocommerce' ) );
			return;
		}
		$payment = $session['payment'] ?? array();
		$card    = trim( ( $payment['cardBrand'] ?? '' ) . ( ! empty( $payment['last4'] ) ? ' ending ' . $payment['last4'] : '' ) );
		$order->payment_complete( (string) $session['id'] );
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: card brand and last digits, 3: how it was confirmed (webhook or return) */
				__( 'WuloPay payment of %1$s received%2$s (confirmed by %3$s).', 'wulopay-woocommerce' ),
				wc_price( (float) $session['amount'], array( 'currency' => $order->get_currency() ) ),
				'' !== $card ? ' (' . $card . ')' : '',
				$source
			)
		);
		self::log( 'Order ' . $order->get_id() . ' paid (session ' . $session['id'] . ', ' . $source . ').' );
	}

	/** The configured environment: "sandbox" or "live". */
	public static function environment(): string {
		$settings = get_option( 'woocommerce_' . self::ID . '_settings', array() );
		return ( $settings['environment'] ?? 'sandbox' ) === 'live' ? 'live' : 'sandbox';
	}

	/**
	 * API client for the given environment (default: the configured one), or null when its settings are incomplete.
	 *
	 * @param string|null $environment 'sandbox' or 'live'.
	 */
	public static function client( ?string $environment = null ): ?\WuloPay\Client {
		$settings    = get_option( 'woocommerce_' . self::ID . '_settings', array() );
		$environment = in_array( $environment, array( 'sandbox', 'live' ), true ) ? $environment : self::environment();
		$key_id      = trim( (string) ( $settings[ $environment . '_key_id' ] ?? '' ) );
		$secret      = trim( (string) ( $settings[ $environment . '_secret' ] ?? '' ) );
		$options     = array( 'tokenCache' => new WuloPay_Token_Cache() );
		if ( 'live' === $environment ) {
			$base = trim( (string) ( $settings['live_base_url'] ?? '' ) );
			if ( 0 !== strpos( $base, 'https://' ) ) {
				return null;
			}
			$options['baseUrl'] = $base;
		}
		if ( '' === $key_id || '' === $secret ) {
			return null;
		}
		return new \WuloPay\Client( $key_id, $secret, $options );
	}

	/** Debug log (WooCommerce logger, source "wulopay"); errors are always logged. */
	public static function log( string $message, string $level = 'info' ): void {
		$settings = get_option( 'woocommerce_' . self::ID . '_settings', array() );
		if ( 'error' !== $level && 'yes' !== ( $settings['debug'] ?? 'no' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => 'wulopay' ) );
	}
}
