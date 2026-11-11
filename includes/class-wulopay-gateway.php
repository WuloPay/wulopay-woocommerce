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
	public const META_PREVIOUS   = '_wulopay_previous_sessions';

	/**
	 * Store currencies WuloPay can take: South African rand, Namibian dollar, Botswana pula, Lesotho loti and eSwatini
	 * lilangeni. The sandbox accepts all of them; in live, currencies other than ZAR are enabled per merchant by WuloPay
	 * (the API refuses one that isn't). Filter: wulopay_wc_currencies.
	 */
	public const CURRENCIES = array( 'ZAR', 'NAD', 'BWP', 'LSL', 'SZL' );

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'WuloPay', 'wulopay-woocommerce' );
		$this->method_description = __( 'Card payments (Visa, Mastercard) on WuloPay\'s secure payment page, with 3-D Secure. Card details never touch your site.', 'wulopay-woocommerce' );
		$this->has_fields         = false;
		$this->icon               = plugins_url( 'assets/images/wulopay-mark.svg', WULOPAY_WC_FILE );
		$this->supports           = array_merge( array( 'products', 'refunds' ), WuloPay_Subscriptions::active() ? WuloPay_Subscriptions::SUPPORTS : array() );

		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$webhook_url       = rest_url( WuloPay_Webhook::NAMESPACE . WuloPay_Webhook::ROUTE );
		$this->form_fields = array(
			'enabled'                => array(
				'title'   => __( 'Enable', 'wulopay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Accept card payments with WuloPay', 'wulopay-woocommerce' ),
				'default' => 'no',
			),
			'title'                  => array(
				'title'   => __( 'Title', 'wulopay-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'Card (Visa, Mastercard)', 'wulopay-woocommerce' ),
			),
			'description'            => array(
				'title'   => __( 'Description', 'wulopay-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'You will pay securely on WuloPay\'s payment page.', 'wulopay-woocommerce' ),
			),
			'accept_marks'           => array(
				'title'   => __( '"We accept" logos', 'wulopay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show the card logos shoppers can pay with under the payment method at checkout', 'wulopay-woocommerce' ),
				'default' => 'yes',
			),
			'environment'            => array(
				'title'   => __( 'Environment', 'wulopay-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					'sandbox' => __( 'Sandbox (test cards, no real money)', 'wulopay-woocommerce' ),
					'live'    => __( 'Live', 'wulopay-woocommerce' ),
				),
				'default' => 'sandbox',
			),
			'sandbox_key_id'         => array(
				'title'       => __( 'Sandbox API key id', 'wulopay-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'From the WuloPay merchant portal: Product Configuration, Hosted Checkout, Enable.', 'wulopay-woocommerce' ),
				'placeholder' => 'wk_…',
			),
			'sandbox_secret'         => array(
				'title' => __( 'Sandbox API secret', 'wulopay-woocommerce' ),
				'type'  => 'password',
			),
			'live_key_id'            => array(
				'title'       => __( 'Live API key id', 'wulopay-woocommerce' ),
				'type'        => 'text',
				'placeholder' => 'wk_…',
			),
			'live_secret'            => array(
				'title' => __( 'Live API secret', 'wulopay-woocommerce' ),
				'type'  => 'password',
			),
			'live_base_url'          => array(
				'title'       => __( 'Live API URL', 'wulopay-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'WuloPay gives you this address when your account goes live.', 'wulopay-woocommerce' ),
				'placeholder' => 'https://…/api/v1',
			),
			'sandbox_webhook_secret' => array(
				'title'       => __( 'Sandbox webhook signing secret', 'wulopay-woocommerce' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: webhook URL */
					__( 'In the WuloPay merchant portal (Product Configuration, Webhooks) add the endpoint %s and paste its signing secret here.', 'wulopay-woocommerce' ),
					'<code>' . esc_html( $webhook_url ) . '</code>'
				),
				'placeholder' => 'whsec_…',
			),
			'live_webhook_secret'    => array(
				'title'       => __( 'Live webhook signing secret', 'wulopay-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'The same endpoint, added in your live merchant portal. Each environment has its own signing secret, so you can switch between them without changing it.', 'wulopay-woocommerce' ),
				'placeholder' => 'whsec_…',
			),
		);
		// Before 1.3.0 one signing secret served both environments. It keeps working, without an environment check,
		// until the merchant clears it.
		$saved = get_option( $this->get_option_key(), array() );
		if ( '' !== trim( (string) ( $saved['webhook_secret'] ?? '' ) ) ) {
			$this->form_fields['webhook_secret'] = array(
				'title'       => __( 'Webhook signing secret (earlier version)', 'wulopay-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Accepted for both environments. Enter it above as the sandbox or live secret, then clear this field.', 'wulopay-woocommerce' ),
			);
		}
		$this->form_fields += array(
			'debug' => array(
				'title'   => __( 'Debug log', 'wulopay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Log WuloPay requests (WooCommerce, Status, Logs). Secrets are never logged.', 'wulopay-woocommerce' ),
				'default' => 'no',
			),
		);
	}

	/** Only for stores in a supported currency, with credentials configured. */
	public function is_available() {
		if ( ! parent::is_available() || ! self::currency_supported() ) {
			return false;
		}
		return null !== self::client();
	}

	/**
	 * The full WuloPay logo next to the payment method at the classic checkout ($this->icon, the square mark, is what
	 * the WooCommerce Payments settings list shows). Themes size it with their own CSS; the width and height
	 * attributes only apply when they don't. Return '' from the wulopay_wc_checkout_logo filter to show no logo.
	 */
	public function get_icon() {
		$url  = (string) apply_filters( 'wulopay_wc_checkout_logo', self::logo_url() );
		$html = '' === $url ? '' : sprintf(
			'<img src="%1$s" alt="%2$s" width="109" height="36" class="wulopay-logo" />',
			esc_url( WC_HTTPS::force_https_url( $url ) ),
			esc_attr__( 'WuloPay', 'wulopay-woocommerce' )
		);
		return apply_filters( 'woocommerce_gateway_icon', $html, $this->id );
	}

	/** The full WuloPay logo (cards and wordmark). */
	public static function logo_url(): string {
		return plugins_url( 'assets/images/wulopay-logo.svg', WULOPAY_WC_FILE );
	}

	/** Acceptance marks the plugin ships: brand => [label, image file in assets/images]. */
	private const MARKS = array(
		'visa'       => array( 'Visa', 'card-visa.svg' ),
		'mastercard' => array( 'Mastercard', 'card-mastercard.svg' ),
	);

	/**
	 * The "We accept" row under the payment method: label and image URL of each way the shopper can pay. Empty when
	 * the "We accept" logos setting is off. Filter wulopay_wc_accept_marks receives and returns a list of
	 * array( 'brand' => …, 'label' => …, 'src' => … ); return an empty array to show no row.
	 *
	 * @return array<int, array{brand: string, label: string, src: string}>
	 */
	public static function accept_marks(): array {
		$settings = get_option( 'woocommerce_' . self::ID . '_settings', array() );
		if ( 'no' === ( $settings['accept_marks'] ?? 'yes' ) ) {
			return array();
		}
		$marks = array();
		foreach ( self::MARKS as $brand => list( $label, $file ) ) {
			$marks[] = array(
				'brand' => $brand,
				'label' => $label,
				'src'   => plugins_url( 'assets/images/' . $file, WULOPAY_WC_FILE ),
			);
		}
		$marks = apply_filters( 'wulopay_wc_accept_marks', $marks );
		return is_array( $marks ) ? array_values( array_filter( $marks, array( self::class, 'valid_mark' ) ) ) : array();
	}

	/**
	 * A filtered mark is used only when it has a label and an image URL.
	 *
	 * @param mixed $mark One entry from the wulopay_wc_accept_marks filter.
	 */
	private static function valid_mark( $mark ): bool {
		return is_array( $mark ) && '' !== (string) ( $mark['label'] ?? '' ) && '' !== (string) ( $mark['src'] ?? '' );
	}

	/** The row as HTML for the classic checkout ('' when there are no marks). */
	public static function accept_marks_html(): string {
		$marks = self::accept_marks();
		if ( empty( $marks ) ) {
			return '';
		}
		$images = '';
		foreach ( $marks as $mark ) {
			$images .= sprintf(
				'<img src="%1$s" alt="%2$s" title="%2$s" width="52" height="34" class="wulopay-accept-mark" style="display:inline-block;width:52px;height:34px;margin:0;float:none;max-height:none;" />',
				esc_url( WC_HTTPS::force_https_url( $mark['src'] ) ),
				esc_attr( $mark['label'] )
			);
		}
		return sprintf(
			'<div class="wulopay-accept" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:8px;"><span class="wulopay-accept-label">%1$s</span>%2$s</div>',
			esc_html__( 'We accept', 'wulopay-woocommerce' ),
			$images
		);
	}

	/** WooCommerce only opens the payment box for a description or fields; the row counts as content too. */
	public function has_fields() {
		return ! empty( self::accept_marks() );
	}

	/** The description, then the "We accept" row, in the box shown when the shopper picks WuloPay. */
	public function payment_fields() {
		$description = $this->get_description();
		if ( '' !== (string) $description ) {
			echo wp_kses_post( wpautop( wptexturize( $description ) ) );
		}
		echo self::accept_marks_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in accept_marks_html().
	}

	/**
	 * True while the selected environment has no usable API key. The WooCommerce Payments list then shows "Action
	 * needed" and refuses to enable the method ("Finish setting up WuloPay before enabling it") instead of enabling a
	 * method that could not appear at checkout.
	 */
	public function needs_setup() {
		return null === self::client();
	}

	/** Settings screen: point out what keeps WuloPay off the checkout. */
	public function admin_options() {
		foreach ( $this->checkout_blockers() as $blocker ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $blocker ) . '</p></div>';
		}
		parent::admin_options();
	}

	/**
	 * Why shoppers would not see WuloPay at checkout, in plain words (empty when nothing is missing).
	 *
	 * @return string[]
	 */
	public function checkout_blockers(): array {
		$blockers = array();
		if ( 'yes' !== $this->get_option( 'enabled', 'no' ) ) {
			$blockers[] = __( 'WuloPay is switched off, so shoppers don\'t see it at checkout. Tick "Enable" below and save.', 'wulopay-woocommerce' );
		}
		if ( ! self::currency_supported() ) {
			$blockers[] = sprintf(
				/* translators: %s: the store's currency code */
				__( 'WuloPay takes payments in South African rand (ZAR), Namibian dollar (NAD), Botswana pula (BWP), Lesotho loti (LSL) and eSwatini lilangeni (SZL), but this store uses %s. Change the store currency to offer WuloPay at checkout.', 'wulopay-woocommerce' ),
				get_woocommerce_currency()
			);
		} elseif ( 'ZAR' !== get_woocommerce_currency() && 'live' === self::environment() ) {
			$blockers[] = sprintf(
				/* translators: %s: the store's currency code */
				__( 'Live payments in %s must be enabled for your WuloPay account. Ask WuloPay before going live; until then live checkouts in this currency are refused.', 'wulopay-woocommerce' ),
				get_woocommerce_currency()
			);
		}
		if ( null === self::client() ) {
			$blockers[] = 'live' === self::environment()
				? __( 'WuloPay is set to Live but the live API key id, secret or API URL is missing, so it is not shown at checkout.', 'wulopay-woocommerce' )
				: __( 'WuloPay is set to Sandbox but the sandbox API key id or secret is missing, so it is not shown at checkout.', 'wulopay-woocommerce' );
		}
		return $blockers;
	}

	public function process_payment( $order_id ) {
		$order  = wc_get_order( $order_id );
		$client = self::client();
		if ( ! $order || null === $client ) {
			wc_add_notice( __( 'Card payments are not available right now. Please choose another payment method.', 'wulopay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		if ( WuloPay_Subscriptions::is_method_change( $order ) ) {
			return WuloPay_Subscriptions::change_payment_method( $order );
		}
		$previous = $this->settle_previous_session( $order );
		if ( null !== $previous ) {
			return $previous;
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
			if ( WuloPay_Subscriptions::is_signup( $order ) ) {
				if ( ! WuloPay_Subscriptions::library_ok( $client ) ) {
					throw new RuntimeException( __( 'Card payments for subscriptions are not available right now.', 'wulopay-woocommerce' ) );
				}
				$params = WuloPay_Subscriptions::signup_params( $order, $params, $client );
			}
			$session = $client->checkoutSessions->create( $params, 'wc-' . $order->get_id() . '-' . wp_generate_uuid4() ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- library API.
		} catch ( RuntimeException $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return array( 'result' => 'failure' );
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
		WuloPay_Status_Check::schedule( $order->get_id() );

		return array(
			'result'   => 'success',
			'redirect' => $session['url'],
		);
	}

	/**
	 * One payable session per order. When the shopper places the order again (back button, second tab, changed
	 * cart), the earlier session is looked up first: paid → the order is completed and the shopper goes to the
	 * receipt; in progress (3-D Secure in another window) → the shopper is asked to finish it; open → it is cancelled
	 * so it can no longer be paid, and a new one is created. Without this, two sessions could both be paid.
	 *
	 * @param WC_Order $order The order being paid.
	 * @return array|null A process_payment() result to return now, or null to create a new session.
	 */
	private function settle_previous_session( WC_Order $order ): ?array {
		$session_id = (string) $order->get_meta( self::META_SESSION_ID );
		$client     = '' !== $session_id ? self::client( (string) $order->get_meta( self::META_ENV ) ) : null;
		if ( null === $client ) {
			return null;
		}
		$failure = static function ( string $message ): array {
			wc_add_notice( $message, 'error' );
			return array( 'result' => 'failure' );
		};
		$busy    = __( 'A card payment for this order is already in progress in another window. Please finish it there, or try again in a few minutes.', 'wulopay-woocommerce' );
		try {
			$session = $client->checkoutSessions->retrieve( $session_id ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- library API.
			if ( 'open' === ( $session['status'] ?? '' ) ) {
				try {
					$session = $client->checkoutSessions->cancel( $session_id ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- library API.
				} catch ( \WuloPay\Exception\ConflictException $e ) {
					// The shopper started paying it in the meantime.
					$session = $client->checkoutSessions->retrieve( $session_id ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- library API.
				}
			}
		} catch ( \WuloPay\Exception\WuloPayException $e ) {
			self::log( 'Order ' . $order->get_id() . ': could not check the earlier session ' . $session_id . ': ' . $e->getMessage(), 'error' );
			return $failure( __( 'We could not check the earlier card payment for this order. Please try again in a moment.', 'wulopay-woocommerce' ) );
		}

		switch ( $session['status'] ?? '' ) {
			case 'paid':
				self::apply_session( $order, $session, 'checkout retry' );
				$order = wc_get_order( $order->get_id() );
				if ( ! $order->needs_payment() && ! WuloPay_Subscriptions::awaiting_card( $order ) ) {
					return array(
						'result'   => 'success',
						'redirect' => $this->get_return_url( $order ),
					);
				}
				return $failure( __( 'This order was already paid with a different amount. Please contact the store.', 'wulopay-woocommerce' ) );
			case 'processing':
			case 'open':
				return $failure( $busy );
			case 'cancelled':
				$order->add_order_note(
					sprintf(
						/* translators: %s: WuloPay checkout session id */
						__( 'The earlier WuloPay checkout (session %s) was cancelled so that it can no longer be paid; a new one is being started.', 'wulopay-woocommerce' ),
						$session_id
					)
				);
				break;
		}
		$previous   = (array) $order->get_meta( self::META_PREVIOUS );
		$previous[] = $session_id;
		$order->update_meta_data( self::META_PREVIOUS, array_slice( array_values( array_unique( $previous ) ), -10 ) );
		$order->delete_meta_data( self::META_SESSION_ID );
		$order->save();
		return null;
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order      = wc_get_order( $order_id );
		$session_id = $order ? (string) $order->get_meta( self::META_SESSION_ID ) : '';
		$client     = self::client( $order ? (string) $order->get_meta( self::META_ENV ) : null );
		if ( $order && '' === $session_id && function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
			return new WP_Error( 'wulopay_refund', __( 'Refund subscription renewal payments in the WuloPay merchant portal (Transactions).', 'wulopay-woocommerce' ) );
		}
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
		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) || self::ID !== $order->get_payment_method() || ( ! $order->needs_payment() && ! WuloPay_Subscriptions::awaiting_card( $order ) ) ) {
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
		if ( ( $session['status'] ?? '' ) !== 'paid' ) {
			return;
		}
		$expected_session = (string) $order->get_meta( self::META_SESSION_ID );
		if ( '' !== $expected_session && hash_equals( $expected_session, (string) ( $session['id'] ?? '' ) ) ) {
			// Subscription sign-up or payment method change: remember the WuloPay subscription for renewals.
			WuloPay_Subscriptions::remember( $order, $session );
			$order->save();
		}
		if ( ! $order->needs_payment() && ! WuloPay_Subscriptions::awaiting_card( $order ) ) {
			return;
		}
		$amount_ok   = abs( (float) ( $session['amount'] ?? -1 ) - (float) $order->get_total() ) < 0.005;
		$currency_ok = strtoupper( (string) ( $session['currency'] ?? '' ) ) === strtoupper( $order->get_currency() );
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

	/** Whether the store currency is one WuloPay takes. */
	public static function currency_supported(): bool {
		$currencies = array_map( 'strtoupper', (array) apply_filters( 'wulopay_wc_currencies', self::CURRENCIES ) );
		return in_array( strtoupper( get_woocommerce_currency() ), $currencies, true );
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
